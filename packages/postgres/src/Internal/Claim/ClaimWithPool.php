<?php

declare(strict_types=1);

namespace Tenant\Postgres\Internal\Claim;

use Packman\Attributes\Internal;
use Tenant\Postgres\Admission;
use Tenant\Postgres\Exceptions\Claim\PoolExhausted;
use Tenant\Postgres\Internal\Pool\PendingPool;
use Tenant\Postgres\OperationKey;
use Tenant\Postgres\OperationReceipt;
use Tenant\Postgres\ProvisionIntent;

/** One synchronous fill on a proven empty pool; uncertain physical effects are never retried. */
#[Internal]
final readonly class ClaimWithPool
{
    public function __construct(private ClaimReceiptStore $claims, private PendingPool $pool) {}

    public function claim(ProvisionIntent $intent, OperationKey $key, Admission $admission): OperationReceipt
    {
        try {
            return $this->claims->claim($intent, $key, $admission);
        } catch (PoolExhausted) {
            $receipt = $this->pool->allocate($intent, $key, $admission);
            if ($receipt->status !== 'succeeded') {
                return $receipt;
            }

            return $this->claims->claim($intent, $key, $admission);
        }
    }
}
