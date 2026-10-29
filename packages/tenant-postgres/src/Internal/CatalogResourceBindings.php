<?php

declare(strict_types=1);

namespace Tenant\Postgres\Internal;

use Closure;
use Illuminate\Database\Connection;
use Tenant\Data\{BindingExpectation, BindingVerificationInput, VerifiedBinding};
use Tenant\Exceptions\BindingMismatch;
use Tenant\Postgres\Contracts\ResourceBindings;
use Tenant\Postgres\Data\{PhysicalAccess, ResolvedBinding};

/** Delegates marker/role/anchor checks to the sole existing verifier. */
final readonly class CatalogResourceBindings implements ResourceBindings
{
    public function __construct(
        private Connection $catalog,
        private Closure $runtimeRoute,
        private PostgresBindingVerifier $verifier,
    ) {}

    public function resolve(string $resourceRef): ResolvedBinding
    {
        try {
            $row = $this->catalog->table('tenant_pg_resources')->where('resource_ref', $resourceRef)->first();
            if ($row === null) {
                throw new \RuntimeException('Unknown resource binding.');
            }
            $expected = new BindingExpectation($resourceRef, $row->incarnation, $row->route_revision);
            $access = new PhysicalAccess(($this->runtimeRoute)($row));
            $verified = $this->verify(new BindingVerificationInput($expected, $access));

            return new ResolvedBinding($expected, $access, $verified->recoveryGeneration);
        } catch (BindingMismatch $failure) {
            throw $failure;
        } catch (\Throwable $failure) {
            throw new BindingMismatch([$failure]);
        }
    }

    public function verify(BindingVerificationInput $input): VerifiedBinding
    {
        return $this->verifier->verify($input);
    }
}
