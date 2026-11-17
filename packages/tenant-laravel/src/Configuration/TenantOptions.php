<?php

declare(strict_types=1);

namespace Tenant\Laravel\Configuration;

use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Immutable compiled configuration snapshot for `axiomasoft/tenant-laravel`.
 */
#[Api(since: '1.0')]
final readonly class TenantOptions implements OptionsFactory
{
    /**
     * @param  list<string>  $cacheStores
     * @param  list<string>  $centralCacheStores
     * @param  array<string, array{owner: string, purpose: string}>  $shared
     */
    public function __construct(
        public string $centralConnection = 'central',
        public array $cacheStores = ['tenant'],
        public array $centralCacheStores = ['central'],
        public TenantFacilitiesOptions $facilities = new TenantFacilitiesOptions,
        public string $storageDriver = 'local',
        public array $shared = [],
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'tenant'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys(
            $config,
            ['central_connection', 'cache_stores', 'central_cache_stores', 'facilities', 'storage_driver', 'shared'],
            $path,
        );

        $centralConnection = $config['central_connection'] ?? 'central';

        if (! is_string($centralConnection) || $centralConnection === '' || $centralConnection === 'tenant') {
            $validator->add(path: $path.'.central_connection', message: 'must be a non-empty string distinct from "tenant"');
            $centralConnection = 'central';
        }

        $cacheStores = self::stringList($validator, $path.'.cache_stores', $config['cache_stores'] ?? ['tenant'], ['tenant']);
        $centralCacheStores = self::stringList($validator, $path.'.central_cache_stores', $config['central_cache_stores'] ?? ['central'], ['central']);

        $rawFacilities = $config['facilities'] ?? [];

        if (! is_array($rawFacilities)) {
            $validator->add(path: $path.'.facilities', message: 'must be an array');
            $rawFacilities = [];
        }

        $storageDriver = $config['storage_driver'] ?? 'local';

        if (! is_string($storageDriver) || $storageDriver === '') {
            $validator->add(path: $path.'.storage_driver', message: 'must be a non-empty string');
            $storageDriver = 'local';
        }

        $shared = self::sharedMap($validator, $path.'.shared', $config['shared'] ?? []);

        $validator->failIfAny();

        $facilities = TenantFacilitiesOptions::fromArray($rawFacilities, $path.'.facilities');

        return new self(
            centralConnection: $centralConnection,
            cacheStores: $cacheStores,
            centralCacheStores: $centralCacheStores,
            facilities: $facilities,
            storageDriver: $storageDriver,
            shared: $shared,
        );
    }

    /**
     * @param  list<string>  $fallback
     * @return list<string>
     */
    private static function stringList(ConfigurationValidator $validator, string $path, mixed $value, array $fallback): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $validator->add(path: $path, message: 'must be a list of non-empty strings');

            return $fallback;
        }

        $result = [];

        foreach ($value as $item) {
            if (! is_string($item) || $item === '') {
                $validator->add(path: $path, message: 'must contain only non-empty strings');

                return $fallback;
            }

            $result[] = $item;
        }

        return $result;
    }

    /**
     * @return array<string, array{owner: string, purpose: string}>
     */
    private static function sharedMap(ConfigurationValidator $validator, string $path, mixed $value): array
    {
        if (! is_array($value)) {
            $validator->add(path: $path, message: 'must be an associative array');

            return [];
        }

        $result = [];

        foreach ($value as $facility => $entry) {
            if (! is_string($facility) || $facility === '') {
                $validator->add(path: $path, message: 'facility keys must be non-empty strings');

                continue;
            }

            if (! is_array($entry)) {
                $validator->add(path: $path.'.'.$facility, message: 'must define non-empty owner and purpose strings');

                continue;
            }

            $validator->rejectUnknownKeys($entry, ['owner', 'purpose'], $path.'.'.$facility);

            if (! is_string($entry['owner'] ?? null) || $entry['owner'] === ''
                || ! is_string($entry['purpose'] ?? null) || $entry['purpose'] === '') {
                $validator->add(path: $path.'.'.$facility, message: 'must define non-empty owner and purpose strings');

                continue;
            }

            $result[$facility] = [
                'owner' => $entry['owner'],
                'purpose' => $entry['purpose'],
            ];
        }

        return $result;
    }
}
