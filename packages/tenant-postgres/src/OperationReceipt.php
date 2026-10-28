<?php

declare(strict_types=1);

namespace Tenant\Postgres;

final readonly class OperationReceipt
{
    public function __construct(
        public string $operationId,
        public string $intentDigest,
        public string $resourceRef,
        public string $incarnation,
        public int $attemptGeneration,
        public string $status,
        public int $receiptVersion,
        public string $observedEffect,
        public bool $cancellationRequested = false,
        public ?OperationKey $key = null,
        public ?ExportRef $exportRef = null,
    ) {}
}
