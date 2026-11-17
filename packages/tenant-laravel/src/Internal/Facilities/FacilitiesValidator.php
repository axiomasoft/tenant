<?php

declare(strict_types=1);

namespace Tenant\Laravel\Internal\Facilities;

use Illuminate\Contracts\Config\Repository;
use Packman\Attributes\Internal;
use Packman\Config\Exceptions\InvalidConfiguration;
use Tenant\Exceptions\TenantFailure;
use Tenant\Laravel\Configuration\TenantOptions;
use Tenant\Laravel\Internal\Configuration\FrameworkConfig;

/** @internal Early facility validation, restricted to qualified v1 drivers. */
#[Internal]
final readonly class FacilitiesValidator
{
    public function __construct(
        private Repository $config,
        private ?TenantOptions $options = null,
    ) {}

    public function assertValid(): void
    {
        $options = $this->options ?? $this->compileOptions();
        $view = new FrameworkConfig(fn (): Repository => $this->config);
        $stores = $options->cacheStores;
        $central = $options->centralConnection;
        if ($central === '' || $central === 'tenant' || ! is_array($this->config->get('database.connections.'.$central))) {
            $this->deny();
        }
        foreach (['database', 'cache', 'session', 'storage'] as $facility) {
            if ($options->facilities->enabled($facility)) {
                continue;
            }
            $shared = $options->shared[$facility] ?? null;
            if ($shared === null || $shared['owner'] === '' || $shared['purpose'] === '') {
                $this->deny();
            }
            // Shared facilities retain their named, host-owned configuration.
        }
        foreach ($stores as $name) {
            $store = $this->config->get('cache.stores.'.$name);
            if (! is_array($store) || ! in_array($store['driver'] ?? null, ['redis', 'array'], true)) {
                $this->deny();
            }
            if ($store['driver'] === 'redis' && ! is_string($store['connection'] ?? null)) {
                $this->deny();
            }
            if (in_array($name, $options->centralCacheStores, true)) {
                $this->deny();
            }
        }
        if ($options->facilities->cache && ! in_array($this->config->get('cache.default'), $stores, true)) {
            $this->deny();
        }
        if ($options->facilities->storage && $options->storageDriver !== 'local') {
            $this->deny();
        }
        $driver = $this->config->get('session.driver');
        if ($driver !== null && ! in_array($driver, ['file', 'redis'], true)) {
            $this->deny();
        }
        if ($driver === 'file' && (! is_string($this->config->get('session.files')) || ! str_starts_with($view->text('session.files'), '/'))) {
            $this->deny();
        }
        if ($driver === 'redis') {
            $name = $this->config->get('session.store');
            if (! is_string($name) || in_array($name, [...$stores, ...$options->centralCacheStores], true) || $this->config->get('cache.stores.'.$name.'.driver') !== 'redis' || ! is_string($this->config->get('session.connection'))) {
                $this->deny();
            }
        }
    }

    private function compileOptions(): TenantOptions
    {
        $raw = $this->config->get('tenant', []);
        if (! is_array($raw)) {
            $this->deny();
        }

        try {
            return TenantOptions::fromArray($raw, 'tenant');
        } catch (InvalidConfiguration $exception) {
            throw new TenantFailure('facility_configuration', [$exception]);
        }
    }

    private function deny(): never
    {
        throw new TenantFailure('facility_configuration');
    }
}
