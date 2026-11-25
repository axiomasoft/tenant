<?php

declare(strict_types=1);

namespace Tenant\Laravel\Internal\Facilities;

use Illuminate\Cache\CacheManager;
use LogicException;
use Packman\Attributes\Internal;
use RuntimeException;
use Tenant\Contracts\Runtime\Facility;
use Tenant\Contracts\Runtime\FrameState;
use Tenant\Contracts\Runtime\PhaseContext;
use Tenant\Contracts\Runtime\RestoreContext;
use Tenant\Laravel\Internal\Configuration\FrameworkConfig;

/** @internal Whole-store snapshots/forget-driver, separated for every frame. */
#[Internal]
final readonly class CacheFacility implements Facility
{
    /** @param list<string> $stores */
    public function __construct(private CacheManager $cache, private FrameworkConfig $config, private array $stores) {}

    public function prepare(PhaseContext $context, FrameState $state): void
    {
        $original = [];
        foreach ($this->stores as $name) {
            $original[$name] = $this->config->map('cache.stores.'.$name);
        }
        $state->put('stores', $original);
    }

    public function activate(PhaseContext $context, FrameState $state): void
    {
        $facet = $context->candidate()->kind === 'active' ? ResourceScopeAccess::fromPhase($context) : null;
        foreach ($this->stores($state) as $name => $store) {
            $prefix = $store['tenant_base_prefix'] ?? '';
            if (! is_string($prefix)) {
                throw new LogicException('Invalid cache prefix');
            }
            $store['prefix'] = ($facet->prefix ?? 'denied:').$prefix;
            $store['tenant_driver'] ??= $store['driver'];
            $store['driver'] = 'tenant-guarded';
            $this->config->set('cache.stores.'.$name, $store);
            $this->cache->forgetDriver($name);
        }
    }

    public function restore(RestoreContext $context, FrameState $state): void
    {
        foreach ($this->stores($state) as $name => $store) {
            $this->config->set('cache.stores.'.$name, $store);
            $this->cache->forgetDriver($name);
        }
    }

    public function verify(RestoreContext $context, FrameState $state): void
    {
        foreach ($this->stores($state) as $name => $store) {
            if ($this->config->map('cache.stores.'.$name) !== $store) {
                throw new RuntimeException('cache restore mismatch');
            }
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function stores(FrameState $state): array
    {
        $value = $state->get('stores');
        if ($value === null) {
            return [];
        }
        $result = [];
        foreach (FrameworkConfig::asMap($value) as $key => $store) {
            $result[$key] = FrameworkConfig::asMap($store);
        }

        return $result;
    }
}
