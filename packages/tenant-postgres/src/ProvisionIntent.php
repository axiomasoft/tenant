<?php

declare(strict_types=1);

namespace Tenant\Postgres;

use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ProvisionIntent
{
    /** @param array<string, mixed> $parameters */
    public function __construct(
        public string $hostIntentRef,
        public string $clusterId,
        public string $templateId,
        public int $templateVersion,
        public string $manifestHash,
        public array $parameters,
    ) {
        if ($hostIntentRef === '' || strlen($hostIntentRef) > 128 || str_contains($hostIntentRef, '://')
            || $templateVersion < 1 || preg_match('/^[a-f0-9]{64}$/D', $manifestHash) !== 1) {
            throw new InvalidArgumentException('Invalid provisioning intent.');
        }
        foreach ([$clusterId, $templateId] as $uuid) {
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $uuid) !== 1) {
                throw new InvalidArgumentException('Invalid frozen identifier.');
            }
        }
        self::canonical($parameters);
    }

    public function digest(): string
    {
        return hash('sha256', json_encode(self::canonical([
            'schemaVersion' => 1, 'operationKind' => 'provision', 'hostIntentRef' => $this->hostIntentRef,
            'clusterBinding' => $this->clusterId, 'templateRef' => $this->templateId,
            'templateVersion' => $this->templateVersion, 'manifestHash' => $this->manifestHash,
            'parameters' => $this->parameters,
        ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                foreach (array_keys($value) as $key) {
                    if (! is_string($key)) {
                        throw new InvalidArgumentException('Canonical object keys must be strings.');
                    }
                }
                ksort($value, SORT_STRING);
            }

            return array_map(self::canonical(...), $value);
        }
        if (is_string($value) || is_int($value) || is_bool($value) || $value === null) {
            return $value;
        }
        throw new InvalidArgumentException('Unsupported canonical value.');
    }
}
