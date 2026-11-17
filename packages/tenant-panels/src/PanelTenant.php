<?php

declare(strict_types=1);

namespace Tenant\Panels;

use Closure;
use Packman\Attributes\Api;
use Packman\Panels\Builder\Panel;
use Packman\Panels\Contracts\Admission\PanelAdmitter;
use Packman\Panels\Contracts\Registry\PanelRegistry;
use Packman\Panels\Data\Admission\AdmissionResult;
use Packman\Panels\Enums\Admission\AssignmentScopeMode;
use Packman\Panels\Enums\Admission\TenantMode;
use Tenant\Contracts\Runtime\TenantRuntime;
use Tenant\Data\Context\ContextState;
use Tenant\Data\Context\Purpose;
use Tenant\Data\Context\Selection;
use Tenant\Exceptions\Context\ContextDenied;
use Tenant\Exceptions\Context\ScopeRequired;
use Tenant\Panels\Contracts\PanelScopeResolver;

/** Panel entry uses the host's existing runtime and authorization policy. */
#[Api(since: '1.0')]
final readonly class PanelTenant
{
    public function __construct(
        private TenantRuntime $runtime,
        private PanelRegistry $panels,
        private PanelAdmitter $admitter,
        private PanelScopeResolver $scopes,
    ) {}

    /**
     * Resolve a trusted selection through the runtime, then admit before invoking application code.
     *
     * @param  Closure(ContextState, AdmissionResult): mixed  $callback
     */
    public function run(
        string $panelId,
        Selection $selection,
        object $subject,
        Purpose $purpose,
        Closure $callback,
        ?string $scopeType = null,
        ?string $scopeId = null,
    ): mixed {
        $panel = $this->panels->get($panelId);
        $this->assertScope($panel, $scopeType, $scopeId);
        if ($panel->tenantMode() === TenantMode::Required && $selection->mode === 'central') {
            throw new ContextDenied;
        }

        return $this->runtime->run($selection, $purpose, function (ContextState $context) use ($panel, $subject, $callback, $scopeType, $scopeId): mixed {
            $admission = $this->admitContext($panel, $context, $subject, $scopeType, $scopeId);

            return $callback($context, $admission);
        });
    }

    /** Admit within an already active HTTP/worker frame without creating another executor. */
    public function admit(string $panelId, object $subject, ?string $scopeType = null, ?string $scopeId = null): AdmissionResult
    {
        $panel = $this->panels->get($panelId);
        $this->assertScope($panel, $scopeType, $scopeId);

        return $this->admitContext($panel, $this->runtime->current(), $subject, $scopeType, $scopeId);
    }

    private function admitContext(Panel $panel, ContextState $context, object $subject, ?string $scopeType, ?string $scopeId): AdmissionResult
    {
        if ($context->kind === 'missing') {
            throw new ScopeRequired;
        }
        if ($panel->tenantMode() === TenantMode::Required && $context->kind !== 'active') {
            throw new ContextDenied;
        }

        $scope = $this->scopes->resolve($panel, $context);
        $centralScope = in_array($scope->id(), ['global', 'system'], true);
        if (($context->kind === 'central') !== $centralScope || serialize($this->runtime->current()) !== serialize($context)) {
            throw new ContextDenied;
        }

        $admission = $this->admitter->admit($panel, $subject, $scope, $scopeType, $scopeId);
        if ($admission === null
            || $admission->tenant->id() !== $scope->id()
            || $admission->scopeType !== $scopeType
            || $admission->scopeId !== $scopeId
            || serialize($this->runtime->current()) !== serialize($context)) {
            throw new ContextDenied;
        }

        return new AdmissionResult($scope, $scopeType, $scopeId, $admission->scopedWitness);
    }

    private function assertScope(Panel $panel, ?string $scopeType, ?string $scopeId): void
    {
        if (($scopeType === null) !== ($scopeId === null)
            || $scopeType === '' || $scopeId === ''
            || ($scopeType !== null && ($panel->scopeMode() === AssignmentScopeMode::None
                || ! in_array($scopeType, $panel->scopeTypes(), true)))) {
            throw new ContextDenied;
        }
    }
}
