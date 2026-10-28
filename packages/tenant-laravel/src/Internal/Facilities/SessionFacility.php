<?php

declare(strict_types=1);

namespace Tenant\Laravel\Internal\Facilities;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Session\SessionManager;
use Tenant\Contracts\{Facility, FrameState, PhaseContext, RestoreContext};
use Tenant\Data\CapabilityId;
use Tenant\Laravel\Internal\FrameworkConfig;

/** @internal Adapted CoreX live-store handler swap; no singleton originals. */
final readonly class SessionFacility implements Facility
{
    public function __construct(private SessionManager $sessions, private CacheManager $cache, private Container $app, private FrameworkConfig $config, private SessionGeneration $generation) {}
    public function prepare(PhaseContext $context, FrameState $state): void
    {
        $state->put('config', $this->config->get('session'));
        $name = $this->config->get('session.store');
        $state->put('cache', is_string($name) ? $this->config->get('cache.stores.'.$name) : null);
        $handlers = [];
        foreach (FrameworkConfig::stores($this->sessions) as $key => $store) {
            $handler = $store->getHandler();
            $handlers[$key] = $handler instanceof GuardedSessionHandler ? $handler->delegate : $handler;
        }
        $state->put('handlers', $handlers);
        $attributes = [];
        foreach (FrameworkConfig::stores($this->sessions) as $key => $store) $attributes[$key] = [$store->all(), $store->getId()];
        $state->put('attributes', $attributes);
        $state->put('captured', true);
    }
    public function activate(PhaseContext $context, FrameState $state): void
    {
        $this->generation->advance();
        if ($context->candidate()->kind === 'active') {
            $facet = $context->require(new CapabilityId('tenant:database'));
            if (!$facet instanceof ResourceDescriptor) throw new \InvalidArgumentException('unsupported_capability');
            if ($this->config->get('session.driver') === 'file') {
                // Reuse storage path/symlink refusal before creating any directory.
                \Tenant\Laravel\Internal\Storage\GuardedFilesystem::assertRoot($facet->sessionRoot);
                if (!is_dir($facet->sessionRoot) && !mkdir($facet->sessionRoot, 0700, true) && !is_dir($facet->sessionRoot)) throw new \RuntimeException('session directory unavailable');
                if (!is_writable($facet->sessionRoot)) throw new \RuntimeException('session directory not writable');
                chmod($facet->sessionRoot, 0700);
                $this->config->set('session.files', $facet->sessionRoot);
            } elseif ($this->config->get('session.driver') === 'redis') {
                $name = $this->config->text('session.store');
                $this->config->set('cache.stores.'.$name.'.prefix', $facet->prefix.'session:');
                $this->config->set('session.prefix', $facet->prefix.'session:');
                $this->cache->forgetDriver($name);
            }
        }
        foreach (FrameworkConfig::stores($this->sessions) as $store) { $store->flush(); $store->setId(null); $store->setExists(false); }
        $this->swap();
    }
    /** @param array<string, \SessionHandlerInterface> $handlers */
    private function swap(array $handlers = []): void
    {
        foreach (FrameworkConfig::stores($this->sessions) as $name => $store) {
            $handler = $handlers[$name] ?? $this->freshHandler($name);
            $store->setHandler(new GuardedSessionHandler($handler, $this->generation->checker()));
        }
        // Drivers resolved later are built from the current session config and wrapped by provider.
    }
    public function restore(RestoreContext $context, FrameState $state): void
    {
        if (!$state->get('captured')) return;
        $this->generation->advance();
        $this->config->set('session', $state->get('config'));
        $name = $this->config->get('session.store');
        if (is_string($name) && $state->get('cache') !== null) {
            $this->config->set('cache.stores.'.$name, $state->get('cache'));
            $this->cache->forgetDriver($name);
        }
        $handlers = $state->get('handlers') ?? [];
        if (!is_array($handlers)) throw new \LogicException('Invalid handler snapshot');
        foreach ($handlers as $key => $handler) if (!is_string($key) || !$handler instanceof \SessionHandlerInterface) throw new \LogicException('Invalid handler snapshot');
        $this->swap($handlers);
        foreach (FrameworkConfig::stores($this->sessions) as $name => $store) {
            $snapshot = FrameworkConfig::asMap($state->get('attributes') ?? [])[$name] ?? [[], null];
            if (!is_array($snapshot) || count($snapshot) !== 2 || !array_is_list($snapshot) || !is_array($snapshot[0]) || (!is_string($snapshot[1]) && $snapshot[1] !== null)) throw new \LogicException('Invalid session snapshot');
            [$attributes, $id] = $snapshot;
            $store->flush(); $store->replace($attributes); $store->setId($id); $store->setExists(false);
        }
    }
    public function verify(RestoreContext $context, FrameState $state): void
    {
        if ($state->get('captured') && $this->config->get('session') !== $state->get('config')) throw new \RuntimeException('session restore mismatch');
    }
    private function freshHandler(string $name): \SessionHandlerInterface
    {
        $store = (new SessionManager($this->app))->driver($name);
        if (!$store instanceof \Illuminate\Session\Store) throw new \LogicException('Invalid session Store');
        return $store->getHandler();
    }
}
