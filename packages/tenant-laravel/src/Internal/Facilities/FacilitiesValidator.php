<?php

declare(strict_types=1);

namespace Tenant\Laravel\Internal\Facilities;

use Illuminate\Contracts\Config\Repository;
use Tenant\Exceptions\TenantFailure;

/** @internal Adapted CoreX early validation, restricted to qualified v1 drivers. */
final readonly class FacilitiesValidator
{
    public function __construct(private Repository $config) {}
    public function assertValid(): void
    {
$view = new \Tenant\Laravel\Internal\FrameworkConfig(fn () => $this->config);
        $stores = $view->strings('tenant.cache_stores');
        $central = $this->config->get('tenant.central_connection');
        if (!is_string($central) || $central === '' || $central === 'tenant' || !is_array($this->config->get('database.connections.'.$central))) $this->deny();
        foreach (['database', 'cache', 'session', 'storage'] as $facility) {
            if ($this->config->get('tenant.facilities.'.$facility, true)) continue;
            $shared = $this->config->get('tenant.shared.'.$facility);
            if (!is_array($shared) || !is_string($shared['owner'] ?? null) || $shared['owner'] === '' || !is_string($shared['purpose'] ?? null) || $shared['purpose'] === '') $this->deny();
            // Shared facilities retain their named, host-owned configuration.
        }
        foreach ($stores as $name) {
            $store = $this->config->get('cache.stores.'.$name);
            if (!is_array($store) || !in_array($store['driver'] ?? null, ['redis', 'array'], true)) $this->deny();
            if ($store['driver'] === 'redis' && !is_string($store['connection'] ?? null)) $this->deny();
            if (in_array($name, $view->strings('tenant.central_cache_stores'), true)) $this->deny();
        }
        if ($this->config->get('tenant.facilities.cache', true) && !in_array($this->config->get('cache.default'), $stores, true)) $this->deny();
        if ($this->config->get('tenant.facilities.storage', true) && $this->config->get('tenant.storage_driver', 'local') !== 'local') $this->deny();
        $driver = $this->config->get('session.driver');
        if ($driver !== null && !in_array($driver, ['file', 'redis'], true)) $this->deny();
        if ($driver === 'file' && (!is_string($this->config->get('session.files')) || !str_starts_with($view->text('session.files'), '/'))) $this->deny();
        if ($driver === 'redis') {
            $name = $this->config->get('session.store');
            if (!is_string($name) || in_array($name, [...$stores, ...$view->strings('tenant.central_cache_stores')], true) || $this->config->get('cache.stores.'.$name.'.driver') !== 'redis' || !is_string($this->config->get('session.connection'))) $this->deny();
        }
    }
    private function deny(): never { throw new TenantFailure('facility_configuration'); }
}
