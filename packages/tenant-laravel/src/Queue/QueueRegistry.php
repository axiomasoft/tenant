<?php

declare(strict_types=1);
namespace Tenant\Laravel\Queue;

use Closure;
use Illuminate\Contracts\Queue\Job;
use Tenant\Data\{BindingExpectation, ContextReference, ExtensionRef, IsolationIdentity, ProfileRef, Purpose, Selection};
use Tenant\Exceptions\TenantFailure;

/** Immutable host-owned allowlists and callbacks. Signing material never enters payloads. */
final readonly class QueueRegistry
{
    /**
     * @param list<string> $namespaces
     * @param array<string, array{version: int, modes: list<string>}> $profiles
     * @param array<string, array{version: int, refs: list<string>}> $extensions
     * @param array<string, array{producer: string, purposes: list<string>, extensions: list<string>, admit: Closure(array<string, scalar|null>, Purpose): void, handle: Closure(array<string, scalar|null>, Job): void, failed?: Closure(array<string, scalar|null>, \Throwable): void}> $handlers
     * @param list<string> $centralJobs Exact framework class@method entries, trusted broker only.
     */
    public function __construct(public array $namespaces, public array $profiles, public array $extensions, public array $handlers, private string $key, public ProfileRef $centralProfile, public array $centralJobs = [])
    {
        if (strlen($key) < 32) throw new \InvalidArgumentException('Queue signing key must be at least32 bytes');
    }
    public function sign(string $bytes): string { return hash_hmac('sha256', $bytes, $this->key); }
    public function selection(ContextReference $reference, ?string $handler = null): Selection
    {
        $e = $reference->envelope;
        if (!is_string($e['profileId']) || !is_int($e['profileVersion']) || !is_array($e['extensions'])) throw new TenantFailure('unsupported_profile');
        $profile = $this->profiles[$e['profileId']] ?? null;
        if ($profile === null || $profile['version'] !== $e['profileVersion'] || !in_array($e['mode'], $profile['modes'], true)) throw new TenantFailure('unsupported_profile');
        $p = new ProfileRef($e['profileId'], $e['profileVersion']);
        if ($e['mode'] === 'central') return Selection::central($p);
        if (!in_array($e['namespace'], $this->namespaces, true)) throw new TenantFailure('denied');
        foreach (['namespace','isolationId','resourceRef','incarnation','routeRevision'] as $key) if (!is_string($e[$key])) throw new TenantFailure('denied');
        $refs = [];
        foreach ($e['extensions'] as $row) {
            if (!is_array($row) || !is_string($row['type'] ?? null) || !is_int($row['version'] ?? null) || !is_bool($row['required'] ?? null) || !is_array($row['refs'] ?? null)) throw new TenantFailure('unsupported_extension');
            $registered = $this->extensions[$row['type']] ?? null;
            $allowed = $registered !== null && $registered['version'] === $row['version'] && ($handler === null || in_array($row['type'].'/'.$row['version'], $this->handlers[$handler]['extensions'] ?? [], true));
            if (!$allowed) {
                if ($row['required']) throw new TenantFailure('unsupported_extension');
                continue;
            }
            $keys = array_keys($row['refs']); $expected = $registered['refs']; sort($keys); sort($expected);
            if ($keys !== $expected) throw new TenantFailure('unsupported_extension');
            $fields = [];
            foreach ($row['refs'] as $key => $value) { if (!is_string($key) || !is_string($value)) throw new TenantFailure('unsupported_extension'); $fields[$key] = $value; }
            $refs[] = new ExtensionRef($row['type'], $row['version'], $row['required'], $fields);
        }
        $id = new IsolationIdentity($e['namespace'], $e['isolationId']);
        $binding = new BindingExpectation($e['resourceRef'], $e['incarnation'], $e['routeRevision']);
        return $e['mode'] === 'single' ? Selection::single($id, $refs, $binding) : Selection::tenant($id, $p, $refs, $binding);
    }
    public function authorize(string $producer, string $handler, Purpose $purpose): void
    {
        $entry = $this->handlers[$handler] ?? null;
        if ($entry === null || $entry['producer'] !== $producer || !in_array($purpose->id, $entry['purposes'], true)) throw new TenantFailure('denied');
    }
    /** @param array<string, scalar|null> $data */
    public function admit(string $producer, string $handler, Purpose $purpose, array $data): void
    {
        $this->authorize($producer, $handler, $purpose);
        $entry = $this->handlers[$handler];
        ($entry['admit'])($data, $purpose);
    }
    /** @param array<string, scalar|null> $data */
    public function handle(string $handler, array $data, Job $job): void { ($this->handlers[$handler]['handle'])($data, $job); }
    /** @param array<string, scalar|null> $data */
    public function failed(string $handler, array $data, \Throwable $error): void { if (isset($this->handlers[$handler]['failed'])) ($this->handlers[$handler]['failed'])($data, $error); }
}
