<?php

declare(strict_types=1);

namespace Tenant\Laravel\Diagnostics;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Override;
use Packman\Attributes\Api;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Tenant\Exceptions\TenantFailure;
use Tenant\Laravel\Configuration\TenantOptions;

/**
 * Read-only diagnostic check for Tenant extensions, central connection, queue commit mode, and cache stores.
 */
#[Api(since: '1.0')]
final readonly class TenantDoctorCheck implements DoctorCheck
{
    /** @param (Closure(): TenantOptions)|null $optionsResolver */
    public function __construct(
        private Repository $config,
        private ?TenantOptions $options = null,
        private ?Closure $optionsResolver = null,
    ) {}

    #[Override]
    public function key(): string
    {
        return 'tenant.runtime.configuration';
    }

    #[Override]
    public function title(): string
    {
        return 'Tenant runtime and facility configuration';
    }

    /** @return list<DoctorFinding> */
    #[Override]
    public function run(DoctorContext $context): array
    {
        $findings = [];

        if (! extension_loaded('intl')) {
            $findings[] = DoctorFinding::error(
                code: 'tenant.doctor.ext_intl_missing',
                message: 'PHP extension ext-intl is required for NFC identity validation.',
                remediation: 'Install and enable the PHP intl extension.',
            );
        }

        if (! extension_loaded('posix')) {
            $findings[] = DoctorFinding::error(
                code: 'tenant.doctor.ext_posix_missing',
                message: 'PHP extension ext-posix is required for fail-stop FPM boundary guards.',
                remediation: 'Install and enable the PHP posix extension.',
            );
        }

        [$options, $optionsFinding] = $this->resolveOptions();

        if ($optionsFinding !== null) {
            return [...$findings, $optionsFinding];
        }

        assert($options instanceof TenantOptions);

        if (! is_array($this->config->get('database.connections.'.$options->centralConnection))) {
            $findings[] = DoctorFinding::error(
                code: 'tenant.doctor.invalid_central_connection',
                message: sprintf('Configured central_connection "%s" is not defined in database.connections.', $options->centralConnection),
                remediation: 'Define the named central connection in config/database.php or update tenant.central_connection.',
                evidence: ['central_connection' => $options->centralConnection],
            );
        }

        if ($this->config->get('queue.connections.database.after_commit') !== false) {
            $findings[] = DoctorFinding::error(
                code: 'tenant.doctor.queue_after_commit',
                message: 'Queue connection "database" must set after_commit = false for transactional dispatch guarantees.',
                remediation: 'Set queue.connections.database.after_commit to false in config/queue.php.',
            );
        }

        $findings = [...$findings, ...$this->checkCacheStores($options)];

        if ($findings === []) {
            return [
                DoctorFinding::ok(
                    code: 'tenant.doctor.healthy',
                    message: 'Tenant PHP extensions, central connection, queue commit policy, and cache stores are valid.',
                    evidence: ['central_connection' => $options->centralConnection, 'cache_stores' => count($options->cacheStores)],
                ),
            ];
        }

        return $findings;
    }

    /** @return array{0: ?TenantOptions, 1: ?DoctorFinding} */
    private function resolveOptions(): array
    {
        if ($this->options instanceof TenantOptions) {
            return [$this->options, null];
        }

        if ($this->optionsResolver instanceof Closure) {
            try {
                return [($this->optionsResolver)(), null];
            } catch (TenantFailure $exception) {
                $cause = $exception->causes()[0] ?? $exception;

                return [
                    null,
                    DoctorFinding::error(
                        code: 'tenant.doctor.invalid_options',
                        message: $cause->getMessage() !== '' ? $cause->getMessage() : 'Configuration key "tenant" is invalid.',
                        remediation: 'Fix the invalid tenant configuration keys reported above.',
                    ),
                ];
            } catch (InvalidConfiguration $exception) {
                return [
                    null,
                    DoctorFinding::error(
                        code: 'tenant.doctor.invalid_options',
                        message: $exception->getMessage(),
                        remediation: 'Fix the invalid tenant configuration keys reported above.',
                    ),
                ];
            }
        }

        $rawTenant = $this->config->get('tenant', []);

        if (! is_array($rawTenant)) {
            return [
                null,
                DoctorFinding::error(
                    code: 'tenant.doctor.invalid_options',
                    message: 'Configuration key "tenant" must be an array.',
                    remediation: 'Publish or restore config/tenant.php as an array.',
                ),
            ];
        }

        try {
            return [TenantOptions::fromArray($rawTenant, 'tenant'), null];
        } catch (InvalidConfiguration $exception) {
            return [
                null,
                DoctorFinding::error(
                    code: 'tenant.doctor.invalid_options',
                    message: $exception->getMessage(),
                    remediation: 'Fix the invalid tenant configuration keys reported above.',
                ),
            ];
        }
    }

    /** @return list<DoctorFinding> */
    private function checkCacheStores(TenantOptions $options): array
    {
        if ($options->facilities->cache && $options->cacheStores === []) {
            return [
                DoctorFinding::error(
                    code: 'tenant.doctor.empty_cache_stores',
                    message: 'At least one cache store must be declared in tenant.cache_stores when tenant cache isolation is enabled.',
                    remediation: 'Declare at least one redis or array store in config/tenant.php cache_stores.',
                ),
            ];
        }

        $findings = [];

        foreach ($options->cacheStores as $storeName) {
            if (in_array($storeName, $options->centralCacheStores, true)) {
                $findings[] = DoctorFinding::error(
                    code: 'tenant.doctor.cache_store_collision',
                    message: sprintf('Cache store "%s" cannot appear in both tenant.cache_stores and tenant.central_cache_stores.', $storeName),
                    remediation: 'Separate tenant-scoped and central cache stores in config/tenant.php.',
                    evidence: ['store' => $storeName],
                );

                continue;
            }

            $store = $this->config->get('cache.stores.'.$storeName);

            if (! is_array($store)) {
                $findings[] = DoctorFinding::error(
                    code: 'tenant.doctor.missing_cache_store',
                    message: sprintf('Declared tenant cache store "%s" is missing from cache.stores.', $storeName),
                    remediation: sprintf('Define cache.stores.%s in config/cache.php.', $storeName),
                    evidence: ['store' => $storeName],
                );

                continue;
            }

            $driver = $store['driver'] ?? null;
            $effectiveDriver = $driver === 'tenant-guarded' ? ($store['tenant_driver'] ?? null) : $driver;

            if (! in_array($effectiveDriver, ['redis', 'array'], true)) {
                $findings[] = DoctorFinding::error(
                    code: 'tenant.doctor.unsupported_cache_driver',
                    message: sprintf('Declared tenant cache store "%s" uses unsupported driver "%s".', $storeName, is_string($effectiveDriver) ? $effectiveDriver : get_debug_type($effectiveDriver)),
                    remediation: 'Configure tenant cache stores with the "redis" or "array" driver.',
                    evidence: ['store' => $storeName],
                );
            }
        }

        return $findings;
    }
}
