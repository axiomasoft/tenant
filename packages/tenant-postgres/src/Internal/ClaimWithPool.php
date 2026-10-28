<?php

declare(strict_types=1);
namespace Tenant\Postgres\Internal;
use Tenant\Postgres\{Admission,OperationKey,OperationReceipt,ProvisionIntent};
use Tenant\Postgres\Exceptions\{ClaimUnavailable,PoolExhausted};
/** One synchronous fill on a proven empty pool; uncertain physical effects are never retried. */
final readonly class ClaimWithPool
{
    public function __construct(private ClaimReceiptStore $claims,private PendingPool $pool) {}
    public function claim(ProvisionIntent $intent,OperationKey $key,Admission $admission):OperationReceipt
    {
        try{return $this->claims->claim($intent,$key,$admission);}catch(PoolExhausted){
            $receipt=$this->pool->fill($intent,$key,$admission);
            if($receipt->status!=='succeeded')throw new ClaimUnavailable('Provision outcome requires reconciliation.');
            return $this->claims->claim($intent,$key,$admission);
        }
    }
}
