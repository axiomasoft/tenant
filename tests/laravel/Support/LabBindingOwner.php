<?php

declare(strict_types=1);

namespace TenantLab;

use Illuminate\Database\Connectors\PostgresConnector;
use Tenant\Contracts\{ContextResolver, BindingVerifier};
use Tenant\Data\{Selection, Purpose, ResolvedIsolation, ContextState, IsolationContext, FacetBindings, BindingExpectation, BindingVerificationInput, VerifiedBinding};
use Tenant\Exceptions\{ContextDenied, BindingMismatch};
use Tenant\Laravel\Internal\Facilities\ResourceDescriptor;

/** Test-only physical owner. No CoreX catalog dependency or restore/replay capability. */
final class LabBindingOwner implements ContextResolver, BindingVerifier
{
    public bool $available = true;
    public function __construct(public readonly array $manifest, private readonly array $credentials) {}
    public function route(string $label): array
    {
        return ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => $this->manifest['ports']['pg'], 'database' => $this->manifest['databases'][$label], 'username' => $this->manifest['runtime'], 'password' => $this->credentials['runtimePassword'], 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer'];
    }
    public function descriptor(string $label): ResourceDescriptor
    {
        $root = $this->manifest['root'].'/resources/'.$label;
        return new ResourceDescriptor($this->route($label), $this->manifest['project'].':'.$label.':', $root.'/durable', $root.'/ephemeral', $root.'/sessions');
    }
    public function resolve(Selection $selection, Purpose $purpose): ResolvedIsolation
    {
        if (!$this->available) throw new ContextDenied();
        if ($selection->mode === 'central') return new ResolvedIsolation(ContextState::central(), new FacetBindings([]));
        $label = $selection->identity?->id;
        if (!in_array($label, ['a', 'b'], true)) throw new ContextDenied();
        $facet = $this->descriptor($label);
        $binding = new BindingExpectation('resource:'.$label, 'incarnation:'.$label, 'route:1');
        return new ResolvedIsolation(ContextState::active(new IsolationContext($selection->mode, $selection->identity, $selection->expectedProfile, $binding, $selection->extensions)), new FacetBindings(['tenant:database' => $facet, 'tenant:binding-verification' => $facet]));
    }
    public function verify(BindingVerificationInput $input): VerifiedBinding
    {
        if (!$input->access instanceof ResourceDescriptor || !$this->available) throw new BindingMismatch();
        $pdo = (new PostgresConnector())->connect($input->access->route);
        $row = $pdo->query('SELECT * FROM tenant_binding')->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row) || $row !== ['protocol' => 'tenant.binding/1', 'owner_id' => 'lab:owner', 'resource_ref' => $input->expected->resourceRef, 'incarnation' => $input->expected->incarnation, 'recovery_generation' => 'recovery:1'] || $input->expected->routeRevision !== 'route:1') throw new BindingMismatch();
        return new VerifiedBinding($input->expected, 'lab:owner', 'recovery:1');
    }
}
