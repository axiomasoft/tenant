<?php

declare(strict_types=1);

namespace Tenant\Data\Context;

use InvalidArgumentException;
use Packman\Attributes\Api;
use Tenant\Data\Verification\BindingExpectation;
use Tenant\Internal\Validation\Validate;

#[Api(since: '1.0')]
final readonly class ContextReference
{
    /** @param array<string, mixed> $envelope */
    public function __construct(public array $envelope)
    {
        $allowed = ['schema', 'mode', 'namespace', 'isolationId', 'profileId', 'profileVersion', 'resourceRef', 'incarnation', 'routeRevision', 'extensions'];
        if (array_diff(array_keys($envelope), $allowed) !== [] || ($envelope['schema'] ?? null) !== 'tenant.context/1' || ! in_array($envelope['mode'] ?? null, ['tenant', 'central', 'single'], true) || ! is_string($envelope['profileId'] ?? null) || ! is_int($envelope['profileVersion'] ?? null) || ! is_array($envelope['extensions'] ?? null)) {
            throw new InvalidArgumentException('Invalid context reference');
        }
        new ProfileRef($envelope['profileId'], $envelope['profileVersion']);
        if ($envelope['profileVersion'] > 2147483647 || ! array_is_list($envelope['extensions']) || count($envelope['extensions']) > 16) {
            throw new InvalidArgumentException('Invalid context reference');
        }
        if ($envelope['mode'] === 'central') {
            foreach (['namespace', 'isolationId', 'resourceRef', 'incarnation', 'routeRevision'] as $key) {
                if (array_key_exists($key, $envelope)) {
                    throw new InvalidArgumentException('Central reference contains identity');
                }
            }
            if ($envelope['extensions'] !== []) {
                throw new InvalidArgumentException('Central reference contains extensions');
            }
        } else {
            foreach (['namespace', 'isolationId', 'resourceRef', 'incarnation', 'routeRevision'] as $key) {
                if (! is_string($envelope[$key] ?? null)) {
                    throw new InvalidArgumentException('Missing reference binding');
                }
            }
            new IsolationIdentity($envelope['namespace'], $envelope['isolationId']);
            new BindingExpectation($envelope['resourceRef'], $envelope['incarnation'], $envelope['routeRevision']);
            if ($envelope['mode'] === 'single' && ($envelope['profileId'] !== 'single' || $envelope['profileVersion'] !== 1)) {
                throw new InvalidArgumentException('Invalid single profile');
            }
        }
        $seen = [];
        foreach ($envelope['extensions'] as $extension) {
            if (! is_array($extension) || array_diff(array_keys($extension), ['type', 'version', 'required', 'refs']) !== [] || ! is_string($extension['type'] ?? null) || ! is_int($extension['version'] ?? null) || ! is_bool($extension['required'] ?? null) || ! is_array($extension['refs'] ?? null)) {
                throw new InvalidArgumentException('Invalid extension');
            }
            $ref = new ExtensionRef($extension['type'], $extension['version'], $extension['required'], Validate::refs($extension['refs']));
            if (isset($seen[$ref->type])) {
                throw new InvalidArgumentException('Duplicate extension');
            }
            $seen[$ref->type] = true;
        }
    }
}
