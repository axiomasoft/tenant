<?php

declare(strict_types=1);

namespace Tenant\Laravel\Queue;

use Closure;
use Illuminate\Contracts\Queue\Job;
use InvalidArgumentException;
use Packman\Attributes\Api;
use Packman\Config\Data\SecretRef;
use SensitiveParameter;
use Tenant\Data\Context\ContextReference;
use Tenant\Data\Context\ExtensionRef;
use Tenant\Data\Context\IsolationIdentity;
use Tenant\Data\Context\ProfileRef;
use Tenant\Data\Context\Purpose;
use Tenant\Data\Context\Selection;
use Tenant\Data\Verification\BindingExpectation;
use Tenant\Exceptions\TenantFailure;
use Throwable;

/**
 * Immutable host-owned allowlists and callbacks. Signing material never enters payloads.
 *
 * @phpstan-type HandlerRegistration array{producer: string, purposes: list<string>, extensions: list<string>, admit: Closure(array<string, scalar|null>, Purpose): void, handle: Closure(array<string, scalar|null>, Job): void, failed?: Closure(array<string, scalar|null>, Throwable): void}
 */
#[Api(since: '1.0')]
final readonly class QueueRegistry
{
    private SecretRef $key;

    /** @var list<string> */
    public array $namespaces;

    /** @var array<string, array{version: int, modes: list<string>}> */
    public array $profiles;

    /** @var array<string, array{version: int, refs: list<string>}> */
    public array $extensions;

    /** @var array<string, HandlerRegistration> */
    public array $handlers;

    /** @var list<string> */
    public array $centralJobs;

    /**
     * @param  list<string>  $namespaces
     * @param  array<string, array{version: int, modes: list<string>}>  $profiles
     * @param  array<string, array{version: int, refs: list<string>}>  $extensions
     * @param  array<string, HandlerRegistration>  $handlers
     * @param  list<string>  $centralJobs  Exact framework class@method entries, trusted broker only.
     */
    public function __construct(array $namespaces, array $profiles, array $extensions, array $handlers, #[SensitiveParameter] string $key, public ProfileRef $centralProfile, array $centralJobs = [])
    {
        if (strlen($key) < 32) {
            throw new InvalidArgumentException('Queue signing key must be at least 32 bytes');
        }
        $this->key = new SecretRef($key);
        // Rebuild every declared field by value: readonly arrays can otherwise
        // retain references to mutable host configuration after registration.
        $this->namespaces = self::strings($namespaces);
        $this->centralJobs = self::strings($centralJobs);
        $profileSnapshots = [];
        foreach ($profiles as $id => $profile) {
            $profileSnapshots[$id] = ['version' => $profile['version'], 'modes' => self::strings($profile['modes'])];
        }
        $this->profiles = $profileSnapshots;
        $extensionSnapshots = [];
        foreach ($extensions as $type => $extension) {
            $extensionSnapshots[$type] = ['version' => $extension['version'], 'refs' => self::strings($extension['refs'])];
        }
        $this->extensions = $extensionSnapshots;
        $handlerSnapshots = [];
        foreach ($handlers as $id => $handler) {
            $snapshot = [
                'producer' => $handler['producer'],
                'purposes' => self::strings($handler['purposes']),
                'extensions' => self::strings($handler['extensions']),
                'admit' => $handler['admit'],
                'handle' => $handler['handle'],
            ];
            if (isset($handler['failed'])) {
                $snapshot['failed'] = $handler['failed'];
            }
            $handlerSnapshots[$id] = $snapshot;
        }
        $this->handlers = $handlerSnapshots;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        return array_map(static fn (string $value): string => $value, $values);
    }

    public function sign(string $bytes): string
    {
        return hash_hmac('sha256', $bytes, $this->key->reveal());
    }

    public function selection(ContextReference $reference, ?string $handler = null): Selection
    {
        $e = $reference->envelope;
        if (! is_string($e['profileId']) || ! is_int($e['profileVersion']) || ! is_array($e['extensions'])) {
            throw new TenantFailure('unsupported_profile');
        }
        $profile = $this->profiles[$e['profileId']] ?? null;
        if ($profile === null || $profile['version'] !== $e['profileVersion'] || ! in_array($e['mode'], $profile['modes'], true)) {
            throw new TenantFailure('unsupported_profile');
        }
        $p = new ProfileRef($e['profileId'], $e['profileVersion']);
        if ($e['mode'] === 'central') {
            return Selection::central($p);
        }
        if (! in_array($e['namespace'], $this->namespaces, true)) {
            throw new TenantFailure('denied');
        }
        foreach (['namespace', 'isolationId', 'resourceRef', 'incarnation', 'routeRevision'] as $key) {
            if (! is_string($e[$key])) {
                throw new TenantFailure('denied');
            }
        }
        $refs = [];
        foreach ($e['extensions'] as $row) {
            if (! is_array($row) || ! is_string($row['type'] ?? null) || ! is_int($row['version'] ?? null) || ! is_bool($row['required'] ?? null) || ! is_array($row['refs'] ?? null)) {
                throw new TenantFailure('unsupported_extension');
            }
            $registered = $this->extensions[$row['type']] ?? null;
            $allowed = $registered !== null && $registered['version'] === $row['version'] && ($handler === null || in_array($row['type'].'/'.$row['version'], $this->handlers[$handler]['extensions'] ?? [], true));
            if (! $allowed) {
                if ($row['required']) {
                    throw new TenantFailure('unsupported_extension');
                }

                continue;
            }
            $keys = array_keys($row['refs']);
            $expected = $registered['refs'];
            sort($keys);
            sort($expected);
            if ($keys !== $expected) {
                throw new TenantFailure('unsupported_extension');
            }
            $fields = [];
            foreach ($row['refs'] as $key => $value) {
                if (! is_string($key) || ! is_string($value)) {
                    throw new TenantFailure('unsupported_extension');
                } $fields[$key] = $value;
            }
            $refs[] = new ExtensionRef($row['type'], $row['version'], $row['required'], $fields);
        }
        $id = new IsolationIdentity($e['namespace'], $e['isolationId']);
        $binding = new BindingExpectation($e['resourceRef'], $e['incarnation'], $e['routeRevision']);

        return $e['mode'] === 'single' ? Selection::single($id, $refs, $binding) : Selection::tenant($id, $p, $refs, $binding);
    }

    public function authorize(string $producer, string $handler, Purpose $purpose): void
    {
        $entry = $this->handlers[$handler] ?? null;
        if ($entry === null || $entry['producer'] !== $producer || ! in_array($purpose->id, $entry['purposes'], true)) {
            throw new TenantFailure('denied');
        }
    }

    /** @param array<string, scalar|null> $data */
    public function admit(string $producer, string $handler, Purpose $purpose, array $data): void
    {
        $this->authorize($producer, $handler, $purpose);
        $entry = $this->handlers[$handler];
        ($entry['admit'])($data, $purpose);
    }

    /** @param array<string, scalar|null> $data */
    public function handle(string $handler, array $data, Job $job): void
    {
        ($this->handlers[$handler]['handle'])($data, $job);
    }

    /** @param array<string, scalar|null> $data */
    public function failed(string $handler, array $data, Throwable $error): void
    {
        if (isset($this->handlers[$handler]['failed'])) {
            ($this->handlers[$handler]['failed'])($data, $error);
        }
    }
}
