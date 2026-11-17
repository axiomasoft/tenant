<?php

declare(strict_types=1);

namespace Tenant\Laravel\Internal\Facilities;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ConfigurationUrlParser;
use InvalidArgumentException;
use LogicException;
use Packman\Attributes\Internal;
use RuntimeException;
use Tenant\Contracts\Runtime\Facility;
use Tenant\Contracts\Runtime\FrameState;
use Tenant\Contracts\Runtime\PhaseContext;
use Tenant\Contracts\Runtime\RestoreContext;
use Tenant\Data\Composition\CapabilityId;
use Tenant\Laravel\Data\ResourceDescriptor;
use Tenant\Laravel\Internal\Configuration\FrameworkConfig;

/** @internal URL/replica stripping and exact restoration, with frame-local state. */
#[Internal]
final readonly class DatabaseFacility implements Facility
{
    public function __construct(private DatabaseManager $databases, private FrameworkConfig $config, private string $central) {}

    public function prepare(PhaseContext $context, FrameState $state): void
    {
        $state->put('default', $this->databases->getDefaultConnection());
        $state->put('config', $this->config->get('database.connections.tenant'));
        $state->put('captured', true);
    }

    public function activate(PhaseContext $context, FrameState $state): void
    {
        if ($context->candidate()->kind !== 'active') {
            $this->config->set('database.connections.tenant', ['driver' => 'tenant-guarded-pgsql']);
        } else {
            $facet = $context->require(new CapabilityId('tenant:database'));
            if (! $facet instanceof ResourceDescriptor) {
                throw new InvalidArgumentException('unsupported_capability');
            }
            $base = (new ConfigurationUrlParser)->parseConfiguration($this->config->map('database.connections.'.$this->central));
            unset($base['url'], $base['read'], $base['write']);
            $route = array_replace($base, $facet->route, $facet->options);
            $route['driver'] = 'tenant-guarded-pgsql';
            unset($route['url'], $route['read'], $route['write']);
            $this->config->set('database.connections.tenant', $route);
        }
        $this->databases->purge('tenant');
        // Default tenant IO must refuse even in Missing/Central; central callers name their port.
        $this->databases->setDefaultConnection('tenant');
    }

    public function restore(RestoreContext $context, FrameState $state): void
    {
        if ($state->get('captured') !== true) {
            return;
        }
        $this->databases->purge('tenant');
        $this->config->set('database.connections.tenant', $state->get('config'));
        $default = $state->get('default');
        if (! is_string($default)) {
            throw new LogicException('Invalid database snapshot');
        }
        $this->databases->setDefaultConnection($default);
    }

    public function verify(RestoreContext $context, FrameState $state): void
    {
        if ($state->get('captured') === true && ($this->config->get('database.connections.tenant') !== $state->get('config') || $this->databases->getDefaultConnection() !== $state->get('default'))) {
            throw new RuntimeException('database restore mismatch');
        }
    }
}
