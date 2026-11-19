<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Context\Data\ScopeRef;
use Packman\Panels\Builder\Panel;
use Packman\Panels\Contracts\Admission\PanelAdmitter;
use Packman\Panels\Contracts\Registry\PanelRegistry;
use Packman\Panels\Data\Admission\AdmissionResult;
use Packman\Panels\Exceptions\Registry\UnknownPanelException;
use Tenant\Contracts\Runtime\TenantRuntime;
use Tenant\Data\Context\ContextState;
use Tenant\Data\Context\IsolationIdentity;
use Tenant\Data\Context\ProfileRef;
use Tenant\Data\Context\Selection;
use Tenant\Data\Verification\BindingExpectation;
use Tenant\Exceptions\Context\ContextDenied;
use Tenant\Exceptions\Context\ScopeRequired;
use Tenant\Exceptions\Verification\StaleIncarnation;
use Tenant\Panels\Contracts\PanelScopeResolver;
use Tenant\Panels\PanelTenant;
use Tenant\Panels\TenantPanelsServiceProvider;
use Tenant\Panels\Tests\Fixtures\PanelHost;
use Tenant\Panels\Tests\Fixtures\PanelUser;
use Tenant\Panels\Tests\PanelTestCase;
use TenantTests\RuntimeDecorator;
use TenantTests\RuntimeRig;

uses(PanelTestCase::class);

it('admits only after activation and restores nested tenant state through the selected runtime', function (bool $decorated): void {
    $host = $this->app->make(PanelHost::class);
    if ($decorated) {
        $this->app->instance(TenantRuntime::class, new RuntimeDecorator($host->rig->runtime));
    }
    $bridge = $this->app->make(PanelTenant::class);
    expect($this->app->make(PanelTenant::class))->toBe($bridge);

    $host->rig->tenant->run(RuntimeRig::selection('A'), RuntimeRig::purpose(), function () use ($bridge, $host): void {
        $result = $bridge->run('workspace', RuntimeRig::selection('B'), new PanelUser, RuntimeRig::purpose(), function (ContextState $context, AdmissionResult $admission) use ($host): string {
            expect($context->context?->identity->id)->toBe('B')
                ->and($admission->tenant->id())->toBe('acct:B')
                ->and($admission->scopeId)->toBe('7')
                ->and($host->rig->cells['db'])->toBe('B');

            return 'completed';
        }, 'team', '7');
        expect($result)->toBe('completed')
            ->and($host->rig->runtime->current()->context?->identity->id)->toBe('A')
            ->and($host->rig->cells['db'])->toBe('A');
    });

    expect($host->seen[0][0])->toBe('acct:B')
        ->and($host->seen[0][2]['db'])->toBe('B')
        ->and($host->rig->runtime->current()->kind)->toBe('missing');
})->with([false, true]);

it('restores the outer frame on denial and policy or application failure', function (string $failure): void {
    $host = $this->app->make(PanelHost::class);
    $bridge = $this->app->make(PanelTenant::class);
    $called = false;
    if ($failure === 'denied') {
        $host->allowed = false;
    } elseif ($failure === 'policy') {
        $host->membership = static fn (): never => throw new RuntimeException('policy unavailable');
    }

    $host->rig->tenant->run(RuntimeRig::selection('A'), RuntimeRig::purpose(), function () use ($host, $bridge, $failure, &$called): void {
        expect(function () use ($bridge, &$called): void {
            $bridge->run('workspace', RuntimeRig::selection('B'), new PanelUser, RuntimeRig::purpose(), function () use (&$called): never {
                $called = true;
                throw new RuntimeException('application failed');
            });
        })->toThrow($failure === 'denied' ? ContextDenied::class : RuntimeException::class);

        expect($called)->toBe($failure === 'callback')
            ->and($host->rig->runtime->current()->context?->identity->id)->toBe('A')
            ->and($host->rig->cells['db'])->toBe('A');
    });
})->with(['denied', 'policy', 'callback']);

it('refuses an unknown panel and malformed scopes before changing tenant facilities', function (): void {
    $host = $this->app->make(PanelHost::class);
    $bridge = $this->app->make(PanelTenant::class);
    expect(fn () => $bridge->run('unknown', RuntimeRig::selection('A'), new PanelUser, RuntimeRig::purpose(), static fn (): null => null))
        ->toThrow(UnknownPanelException::class);
    foreach ([['team', null], [null, '1'], ['', '1'], ['team', ''], ['unknown', '1']] as [$type, $id]) {
        expect(fn () => $bridge->run('workspace', RuntimeRig::selection('A'), new PanelUser, RuntimeRig::purpose(), static fn (): null => null, $type, $id))
            ->toThrow(ContextDenied::class);
    }
    expect($host->rig->resolver->lookups)->toBe([])->and($host->seen)->toBe([]);
});

it('requires an explicit context and never promotes missing or central to a required tenant', function (): void {
    $host = $this->app->make(PanelHost::class);
    $bridge = $this->app->make(PanelTenant::class);
    expect(fn () => $bridge->admit('control', new PanelUser))->toThrow(ScopeRequired::class);
    $central = Selection::central(new ProfileRef('php-scoped', 1));
    expect(fn () => $bridge->run('workspace', $central, new PanelUser, RuntimeRig::purpose(), static fn (): null => null))
        ->toThrow(ContextDenied::class);
    expect($host->rig->resolver->lookups)->toBe([]);
    $result = $bridge->run('control', $central, new PanelUser, RuntimeRig::purpose(), static fn (ContextState $context, AdmissionResult $admission): string => $admission->tenant->id());
    expect($result)->toBe('global')->and($host->rig->runtime->current()->kind)->toBe('missing');
});

it('refuses projections between central and tenant authorization scopes', function (bool $central): void {
    $host = $this->app->make(PanelHost::class);
    $host->projection = static fn (): ScopeRef => new ScopeRef($central ? 'acct:A' : 'global');
    $selection = $central ? Selection::central(new ProfileRef('php-scoped', 1)) : RuntimeRig::selection('A');
    expect(fn () => $this->app->make(PanelTenant::class)->run('control', $selection, new PanelUser, RuntimeRig::purpose(), static fn (): null => null))
        ->toThrow(ContextDenied::class);
    expect($host->seen)->toBe([])->and($host->rig->runtime->current()->kind)->toBe('missing');
})->with([false, true]);

it('uses fresh runtime verification before panel admission', function (): void {
    $host = $this->app->make(PanelHost::class);
    $selection = RuntimeRig::selection('A', binding: new BindingExpectation('A', 'old', 'r1'));
    expect(fn () => $this->app->make(PanelTenant::class)->run('workspace', $selection, new PanelUser, RuntimeRig::purpose(), static fn (): null => null))
        ->toThrow(StaleIncarnation::class);
    expect($host->seen)->toBe([])->and($host->rig->runtime->current()->kind)->toBe('missing');
});

it('supports the single-installation profile through the same panel adapter', function (): void {
    $result = $this->app->make(PanelTenant::class)->run(
        'workspace',
        Selection::single(new IsolationIdentity('acct', 'A')),
        new PanelUser,
        RuntimeRig::purpose(),
        static fn (ContextState $context): ?string => $context->context?->mode,
    );
    expect($result)->toBe('single');
});

it('refuses context changes made by a host scope resolver or admission gate', function (string $stage): void {
    $host = $this->app->make(PanelHost::class);
    $bridge = $this->app->make(PanelTenant::class);
    $host->rig->tenant->run(RuntimeRig::selection('A'), RuntimeRig::purpose(), function () use ($host, $bridge, $stage): void {
        $foreign = null;
        if ($stage === 'projection') {
            $host->projection = function () use ($host, &$foreign): ScopeRef {
                $foreign = $host->rig->enter('B');

                return new ScopeRef('acct:A');
            };
        } else {
            $host->membership = function () use ($host, &$foreign): bool {
                $foreign = $host->rig->enter('B');

                return true;
            };
        }

        try {
            expect(fn () => $bridge->admit('workspace', new PanelUser))->toThrow(ContextDenied::class);
            if ($stage === 'projection') {
                expect($host->seen)->toBe([]);
            }
        } finally {
            if ($foreign !== null) {
                $host->rig->tenant->restore($foreign);
            }
        }
    });
    expect($host->rig->runtime->current()->kind)->toBe('missing');
})->with(['projection', 'admission']);

it('admits in an existing request frame and refuses an admission that changes its target', function (): void {
    $host = $this->app->make(PanelHost::class);
    $host->rig->tenant->run(RuntimeRig::selection('A'), RuntimeRig::purpose(), function () use ($host): void {
        $result = $this->app->make(PanelTenant::class)->admit('workspace', new PanelUser);
        expect($result->tenant->id())->toBe('acct:A');
        $changed = new class implements PanelAdmitter
        {
            public function admit(Panel $panel, object $subject, ContextScopeInterface $tenant, ?string $scopeType = null, ?string $scopeId = null): AdmissionResult
            {
                return new AdmissionResult(new ScopeRef('acct:B'));
            }
        };
        $bridge = new PanelTenant($host->rig->runtime, $this->app->make(PanelRegistry::class), $changed, $host);
        expect(fn (): AdmissionResult => $bridge->admit('workspace', new PanelUser))->toThrow(ContextDenied::class)
            ->and($host->rig->runtime->current()->context?->identity->id)->toBe('A');
    });
});

it('registers lazily without creating a runtime or default authorization policy', function (): void {
    $app = new Application;
    $app->register(TenantPanelsServiceProvider::class);
    $app->boot();
    expect($app->bound(PanelTenant::class))->toBeTrue()
        ->and($app->resolved(PanelTenant::class))->toBeFalse()
        ->and($app->bound(TenantRuntime::class))->toBeFalse()
        ->and($app->bound(PanelScopeResolver::class))->toBeFalse()
        ->and($app->bound(PanelAdmitter::class))->toBeFalse();
});
