<?php

declare(strict_types=1);

namespace Tenant\Laravel\Configuration;

use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Immutable toggle configuration for Tenant-managed Laravel facilities.
 */
#[Api(since: '1.0')]
final readonly class TenantFacilitiesOptions implements OptionsFactory
{
    public function __construct(
        public bool $database = true,
        public bool $cache = true,
        public bool $session = true,
        public bool $storage = true,
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'tenant.facilities'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, ['database', 'cache', 'session', 'storage'], $path);

        $database = self::boolOption($validator, $path.'.database', $config['database'] ?? true);
        $cache = self::boolOption($validator, $path.'.cache', $config['cache'] ?? true);
        $session = self::boolOption($validator, $path.'.session', $config['session'] ?? true);
        $storage = self::boolOption($validator, $path.'.storage', $config['storage'] ?? true);

        $validator->failIfAny();

        return new self(
            database: $database,
            cache: $cache,
            session: $session,
            storage: $storage,
        );
    }

    public function enabled(string $facility): bool
    {
        return match ($facility) {
            'database' => $this->database,
            'cache' => $this->cache,
            'session' => $this->session,
            'storage' => $this->storage,
            default => true,
        };
    }

    private static function boolOption(ConfigurationValidator $validator, string $path, mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $validator->add(path: $path, message: 'must be a boolean');

        return true;
    }
}
