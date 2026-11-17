<?php

declare(strict_types=1);

namespace Tenant\Internal\Composition;

use InvalidArgumentException;
use Packman\Attributes\Internal;
use Tenant\Composition\CompiledComposition;
use Tenant\Contracts\Context\ContextCodec;
use Tenant\Contracts\Context\ContextResolver;
use Tenant\Contracts\Context\ExtensionDecoder;
use Tenant\Contracts\Runtime\TenantRuntime;
use Tenant\Contracts\Verification\BindingVerifier;
use Tenant\Data\Composition\CapabilityId;
use Tenant\Data\Composition\ParticipantDeclaration;
use Tenant\Exceptions\TenantFailure;

#[Internal]
final class Compiler
{
    /**
     * @param  list<ParticipantDeclaration>  $declarations
     * @param  array<string, mixed>  $profiles
     * @param  list<array{id: string, slot: string, spiVersion: int, service: object, capabilities: list<string>}>  $services
     */
    public function compile(array $declarations, array $profiles, array $services): CompiledComposition
    {
        self::validateProfiles($profiles);
        $serviceSlots = [];
        $serviceIds = [];
        $serviceCapabilities = [];
        $serviceTypes = [
            'tenant:runtime/default' => TenantRuntime::class,
            'tenant:resolver/default' => ContextResolver::class,
            'tenant:codec/default' => ContextCodec::class,
            'tenant:binding/default' => BindingVerifier::class,
        ];
        foreach ($services as $service) {
            $id = $service['id'];
            $slot = $service['slot'];
            if (preg_match('/^[a-z][a-z0-9._-]*:[a-z][a-z0-9._\/-]*$/D', $id) !== 1 || isset($serviceIds[$id])) {
                throw new TenantFailure('duplicate_implementation');
            }
            if (isset($serviceSlots[$slot])) {
                throw new TenantFailure('exclusive_slot_conflict');
            }
            if ($service['spiVersion'] !== 1) {
                throw new TenantFailure('incompatible_spi');
            }
            $type = $serviceTypes[$slot] ?? (preg_match('~^tenant:extension/[a-z][a-z0-9._-]*:[a-z][a-z0-9._/-]*/[1-9][0-9]*$~D', $slot) === 1 ? ExtensionDecoder::class : throw new TenantFailure('unsupported_capability'));
            if (! $service['service'] instanceof $type) {
                throw new TenantFailure('replacement_incompatible');
            }
            $serviceIds[$id] = true;
            $serviceSlots[$slot] = $service['service'];
            foreach ($service['capabilities'] as $capability) {
                new CapabilityId($capability);
                $serviceCapabilities[$capability] = ($serviceCapabilities[$capability] ?? 0) + 1;
            }
        }
        $all = [];
        foreach ($declarations as $d) {
            if (isset($all[$d->id]) || isset($serviceIds[$d->id])) {
                throw new TenantFailure('duplicate_implementation');
            }
            if (preg_match('/^[a-z][a-z0-9._-]*:[a-z][a-z0-9._\/-]*$/D', $d->id) !== 1) {
                throw new TenantFailure('missing_dependency');
            }
            if ($d->spiVersion !== 1) {
                throw new TenantFailure('incompatible_spi');
            }
            if (! in_array($d->stage, ['resource', 'product'], true)) {
                throw new TenantFailure('invalid_stage_order');
            }
            $all[$d->id] = $d;
        }
        $replaced = [];
        foreach ($all as $d) {
            if ($d->replaces === null) {
                continue;
            }
            $old = $all[$d->replaces] ?? throw new TenantFailure('replacement_target_missing');
            if (isset($replaced[$old->id])) {
                throw new TenantFailure('replacement_conflict');
            }
            if ($old->replaces !== null || $d->id === $old->id || $d->stage !== $old->stage || array_diff($d->exclusiveSlots, $old->exclusiveSlots) !== [] || array_diff($old->exclusiveSlots, $d->exclusiveSlots) !== [] || array_diff(array_map(fn (CapabilityId $c): string => $c->id, $old->provides), array_map(fn (CapabilityId $c): string => $c->id, $d->provides)) !== []) {
                throw new TenantFailure('replacement_incompatible');
            }
            $replaced[$old->id] = $d->id;
        }
        foreach ($replaced as $old => $new) {
            if (isset($replaced[$new])) {
                throw new TenantFailure('replacement_conflict');
            }
            unset($all[$old]);
        }
        $slots = $providers = $edges = [];
        foreach ($all as $id => $d) {
            $edges[$id] = [];
            foreach ($d->exclusiveSlots as $slot) {
                if (preg_match('/^[a-z][a-z0-9._-]*:[a-z][a-z0-9._\/-]*$/D', $slot) !== 1) {
                    throw new TenantFailure('exclusive_slot_conflict');
                }
                if (isset($slots[$slot]) || isset($serviceSlots[$slot])) {
                    throw new TenantFailure('exclusive_slot_conflict');
                }
                $slots[$slot] = $id;
            }
            foreach ($d->provides as $capability) {
                $providers[$capability->id][] = $id;
            }
        }
        self::validateProfileCapabilities($profiles, $providers, $serviceCapabilities);
        foreach ($all as $id => $d) {
            foreach ($d->requires as $capability) {
                $owners = $providers[$capability->id] ?? [];
                for ($i = 0; $i < ($serviceCapabilities[$capability->id] ?? 0); $i++) {
                    $owners[] = null;
                }
                if ($owners === []) {
                    throw new TenantFailure('unsupported_capability');
                }
                if (count($owners) > 1) {
                    throw new TenantFailure('ambiguous_capability');
                }
                if ($owners[0] !== null && $owners[0] !== $id) {
                    self::addEdge($edges, $all, $owners[0], $id);
                }
            }
            foreach ($d->before as $target) {
                self::addEdge($edges, $all, $id, $replaced[$target] ?? $target);
            }
            foreach ($d->after as $target) {
                self::addEdge($edges, $all, $replaced[$target] ?? $target, $id);
            }
        }
        foreach ($all as $a => $one) {
            foreach ($all as $b => $two) {
                if ($one->stage === 'resource' && $two->stage === 'product') {
                    self::addEdge($edges, $all, $a, $b);
                }
            }
        }
        $indegree = array_fill_keys(array_keys($all), 0);
        foreach ($edges as $out) {
            foreach ($out as $target => $_) {
                $indegree[$target]++;
            }
        }
        $order = [];
        while (count($order) < count($all)) {
            $ready = array_keys(array_filter($indegree, fn (int $v): bool => $v === 0));
            sort($ready, SORT_STRING);
            if ($ready === []) {
                throw new TenantFailure('dependency_cycle');
            }
            $id = $ready[0];
            $order[] = $id;
            unset($indegree[$id]);
            foreach ($edges[$id] as $target => $_) {
                $indegree[$target]--;
            }
        }

        return new CompiledComposition(1, $order, $profiles, array_map(fn (string $id): ParticipantDeclaration => $all[$id], $order), $serviceSlots);
    }

    /**
     * @param  array<string, mixed>  $profiles
     * @param  array<string, list<string>>  $providers  Effective participants, after replacements.
     * @param  array<string, int>  $serviceCapabilities  Selected typed host services.
     */
    private static function validateProfileCapabilities(array $profiles, array $providers, array $serviceCapabilities): void
    {
        foreach ($profiles as $profile) {
            if (! is_array($profile) || ! array_key_exists('requiredCapabilities', $profile)) {
                continue;
            }
            $required = $profile['requiredCapabilities'];
            if (! is_array($required) || ! array_is_list($required)) {
                throw new TenantFailure('unsupported_capability');
            }
            foreach ($required as $capability) {
                if (! is_string($capability)) {
                    throw new TenantFailure('unsupported_capability');
                }
                try {
                    new CapabilityId($capability);
                } catch (InvalidArgumentException $failure) {
                    throw new TenantFailure('unsupported_capability', [$failure]);
                }
                $count = count($providers[$capability] ?? []) + ($serviceCapabilities[$capability] ?? 0);
                if ($count === 0) {
                    throw new TenantFailure('unsupported_capability');
                }
                if ($count > 1) {
                    throw new TenantFailure('ambiguous_capability');
                }
            }
        }
    }

    /** @param array<mixed> $profiles */
    private static function validateProfiles(array $profiles): void
    {
        foreach ($profiles as $value) {
            if (is_array($value)) {
                self::validateProfiles($value);

                continue;
            }
            if (! is_scalar($value) && $value !== null) {
                throw new TenantFailure('unsupported_capability');
            }
        }
    }

    /**
     * @param  array<string, array<string, bool>>  $edges
     * @param  array<string, ParticipantDeclaration>  $all
     */
    private static function addEdge(array &$edges, array $all, string $from, string $to): void
    {
        if (! isset($all[$from], $all[$to])) {
            throw new TenantFailure('missing_dependency');
        }
        if ($all[$from]->stage === 'product' && $all[$to]->stage === 'resource') {
            throw new TenantFailure('invalid_stage_order');
        }
        $edges[$from][$to] = true;
    }
}
