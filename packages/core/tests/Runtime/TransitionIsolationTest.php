<?php

declare(strict_types=1);

use Tenant\Contracts\Runtime\PhaseContext;
use Tenant\Data\Context\ContextState;
use Tenant\Data\Runtime\FrameToken;
use Tenant\Data\Runtime\RuntimeObservation;
use Tenant\Exceptions\Runtime\ConcurrentEntry;
use Tenant\Exceptions\Runtime\StaleHandle;
use TenantTests\Check;
use TenantTests\RuntimeRig;

require_once __DIR__.'/../Support/RuntimeRig.php';

it('closes activation capabilities before notifying observers', function (bool $decorated): void {
    $rig = new RuntimeRig($decorated);
    $observed = [];
    $rig->onObserve = function (RuntimeObservation $observation) use ($rig, &$observed): void {
        if (in_array($observation->kind, ['entered', 'activation_failed'], true)) {
            expect(fn (): ContextState => $rig->retainedPhase->candidate())->toThrow(StaleHandle::class);
            $observed[] = $observation->kind;
        }
    };
    $a = $rig->enter('A');
    $failure = new RuntimeException('activation failed');
    $rig->failures['activate:db:B'] = $failure;
    Check::switchFailure(fn (): FrameToken => $rig->enter('B'), $failure);
    $rig->tenant->restore($a);

    expect($rig->observerFailures)->toBe([])
        ->and($observed)->toBe(['entered', 'activation_failed']);
})->with([false, true]);

it('revokes the failed candidate before compensating participants', function (bool $decorated): void {
    $rig = new RuntimeRig($decorated);
    $a = $rig->enter('A');
    $checked = 0;
    $rig->hook = function (string $phase) use ($rig, &$checked): void {
        if ($phase === 'restore') {
            expect(fn (): ContextState => $rig->retainedPhase->candidate())->toThrow(StaleHandle::class);
            $checked++;
        }
    };
    $failure = new RuntimeException('activation failed');
    $rig->failures['activate:db:B'] = $failure;
    Check::switchFailure(fn (): FrameToken => $rig->enter('B'), $failure);

    expect($checked)->toBe(2)
        ->and($rig->tenant->current()->context->identity->id)->toBe('A');
    $rig->tenant->restore($a);
})->with([false, true]);

it('refuses phase capabilities from another fiber during activation', function (bool $decorated): void {
    $rig = new RuntimeRig($decorated);
    $checked = 0;
    $rig->hook = function (string $phase) use ($rig, &$checked): void {
        if ($phase === 'prepare') {
            $fiber = new Fiber(fn (): ContextState => $rig->retainedPhase->candidate());
            expect($fiber->start(...))->toThrow(ConcurrentEntry::class);
            $checked++;
        }
    };
    $a = $rig->enter('A');
    $rig->tenant->restore($a);

    expect($checked)->toBe(4)
        ->and($rig->tenant->current()->kind)->toBe('missing');
})->with([false, true]);

it('refuses cloning an open phase capability to extend its lifetime', function (bool $decorated): void {
    $rig = new RuntimeRig($decorated);
    $rig->hook = function (string $phase) use ($rig): void {
        if ($phase === 'prepare') {
            expect(fn (): PhaseContext => clone $rig->retainedPhase)->toThrow(Error::class);
        }
    };
    $a = $rig->enter('A');
    $rig->tenant->restore($a);

    expect($rig->tenant->current()->kind)->toBe('missing');
})->with([false, true]);
