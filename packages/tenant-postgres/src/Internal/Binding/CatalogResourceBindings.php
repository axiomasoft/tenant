<?php

declare(strict_types=1);

namespace Tenant\Postgres\Internal\Binding;

use Closure;
use Illuminate\Database\Connection;
use Packman\Attributes\Internal;
use RuntimeException;
use Tenant\Data\Verification\BindingExpectation;
use Tenant\Data\Verification\BindingVerificationInput;
use Tenant\Data\Verification\VerifiedBinding;
use Tenant\Exceptions\Verification\BindingMismatch;
use Tenant\Postgres\Contracts\Provisioning\ResourceBindings;
use Tenant\Postgres\Data\Binding\PhysicalAccess;
use Tenant\Postgres\Data\Binding\ResolvedBinding;
use Tenant\Postgres\Internal\Database\DbRow;
use Throwable;

/** Delegates marker/role/anchor checks to the sole existing verifier. */
#[Internal]
final readonly class CatalogResourceBindings implements ResourceBindings
{
    /** @param Closure(object): array<string, mixed> $runtimeRoute */
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
                throw new RuntimeException('Unknown resource binding.');
            }
            $expected = new BindingExpectation($resourceRef, DbRow::str($row, 'incarnation'), DbRow::str($row, 'route_revision'));
            $access = new PhysicalAccess(($this->runtimeRoute)($row));
            $verified = $this->verify(new BindingVerificationInput($expected, $access));

            return new ResolvedBinding($expected, $access, $verified->recoveryGeneration);
        } catch (BindingMismatch $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new BindingMismatch([$failure]);
        }
    }

    public function verify(BindingVerificationInput $input): VerifiedBinding
    {
        return $this->verifier->verify($input);
    }
}
