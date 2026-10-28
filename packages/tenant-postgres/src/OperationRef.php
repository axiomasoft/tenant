<?php

declare(strict_types=1);
namespace Tenant\Postgres;
final readonly class OperationRef
{
    public function __construct(public string $namespace,public string $operationId,public string $resourceRef,public string $incarnation)
    {
        if($namespace===''||$operationId==='' ||$resourceRef===''||$incarnation==='')throw new \InvalidArgumentException('Invalid operation reference.');
    }
}
