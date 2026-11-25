<?php

declare(strict_types=1);

use Tenant\Postgres\Admission;
use Tenant\Postgres\Contracts\Lifecycle\ArchiveStore;
use Tenant\Postgres\Contracts\Lifecycle\RecoveryAnchor;
use Tenant\Postgres\Contracts\Provisioning\ProvisioningClaim;
use Tenant\Postgres\Contracts\Provisioning\ResourceBindings;
use Tenant\Postgres\Contracts\Provisioning\TemplateIntegrityVerifier;
use Tenant\Postgres\Data\Binding\PhysicalAccess;
use Tenant\Postgres\Data\Binding\ResolvedBinding;
use Tenant\Postgres\Data\Provisioning\ClaimRequest;
use Tenant\Postgres\Data\Provisioning\ClaimResult;
use Tenant\Postgres\Data\Provisioning\QualifiedTemplate;
use Tenant\Postgres\Data\Provisioning\ResourceSnapshot;
use Tenant\Postgres\Data\Provisioning\TemplateTrust;
use Tenant\Postgres\Exceptions\Claim\ClaimConflict;
use Tenant\Postgres\Exceptions\Claim\ClaimUnavailable;
use Tenant\Postgres\Exceptions\Claim\PoolExhausted;
use Tenant\Postgres\Exceptions\Provisioning\TemplateIntegrityException;
use Tenant\Postgres\Internal\Binding\CatalogResourceBindings;
use Tenant\Postgres\Internal\Binding\PostgresBindingVerifier;
use Tenant\Postgres\Internal\Claim\CanonicalIntent;
use Tenant\Postgres\Internal\Claim\ClaimReceiptStore;
use Tenant\Postgres\Internal\Claim\ClaimResource;
use Tenant\Postgres\Internal\Claim\ClaimWithPool;
use Tenant\Postgres\Internal\Claim\OperationKeys;
use Tenant\Postgres\Internal\Claim\ProvisionJournal;
use Tenant\Postgres\Internal\Claim\ReplayableProvision;
use Tenant\Postgres\Internal\Database\DatabaseValue;
use Tenant\Postgres\Internal\Database\DbRow;
use Tenant\Postgres\Internal\Lifecycle\ErrorBoundary;
use Tenant\Postgres\Internal\Lifecycle\LifecycleJournal;
use Tenant\Postgres\Internal\Pool\PendingPool;
use Tenant\Postgres\Internal\Pool\ResourceSnapshotReader;
use Tenant\Postgres\Internal\Recovery\AnchorHistory;
use Tenant\Postgres\Internal\Recovery\PhysicalFence;
use Tenant\Postgres\OperationKey;
use Tenant\Postgres\OperationReceipt;
use Tenant\Postgres\OperationRef;
use Tenant\Postgres\ProvisionIntent;

(static function (): void {
    $contracts = 'Tenant\\Postgres\\Contracts\\';
    $data = 'Tenant\\Postgres\\Data\\';
    $exceptions = 'Tenant\\Postgres\\Exceptions\\';
    $internal = 'Tenant\\Postgres\\Internal\\';

    /** @var array<string, class-string> $aliases */
    $aliases = [
        $data.'Admission' => Admission::class,
        $data.'OperationKey' => OperationKey::class,
        $data.'OperationReceipt' => OperationReceipt::class,
        $data.'OperationRef' => OperationRef::class,
        $data.'ProvisionIntent' => ProvisionIntent::class,
        $contracts.'ArchiveStore' => ArchiveStore::class,
        $contracts.'RecoveryAnchor' => RecoveryAnchor::class,
        $contracts.'ProvisioningClaim' => ProvisioningClaim::class,
        $contracts.'ResourceBindings' => ResourceBindings::class,
        $contracts.'TemplateIntegrityVerifier' => TemplateIntegrityVerifier::class,
        $data.'PhysicalAccess' => PhysicalAccess::class,
        $data.'ResolvedBinding' => ResolvedBinding::class,
        $data.'ClaimRequest' => ClaimRequest::class,
        $data.'ClaimResult' => ClaimResult::class,
        $data.'QualifiedTemplate' => QualifiedTemplate::class,
        $data.'ResourceSnapshot' => ResourceSnapshot::class,
        $data.'TemplateTrust' => TemplateTrust::class,
        $exceptions.'ClaimConflict' => ClaimConflict::class,
        $exceptions.'ClaimUnavailable' => ClaimUnavailable::class,
        $exceptions.'PoolExhausted' => PoolExhausted::class,
        $exceptions.'TemplateIntegrityException' => TemplateIntegrityException::class,
        $internal.'CatalogResourceBindings' => CatalogResourceBindings::class,
        $internal.'PostgresBindingVerifier' => PostgresBindingVerifier::class,
        $internal.'CanonicalIntent' => CanonicalIntent::class,
        $internal.'ClaimReceiptStore' => ClaimReceiptStore::class,
        $internal.'ClaimResource' => ClaimResource::class,
        $internal.'ClaimWithPool' => ClaimWithPool::class,
        $internal.'OperationKeys' => OperationKeys::class,
        $internal.'ProvisionJournal' => ProvisionJournal::class,
        $internal.'ReplayableProvision' => ReplayableProvision::class,
        $internal.'DatabaseValue' => DatabaseValue::class,
        $internal.'DbRow' => DbRow::class,
        $internal.'ErrorBoundary' => ErrorBoundary::class,
        $internal.'LifecycleJournal' => LifecycleJournal::class,
        $internal.'PendingPool' => PendingPool::class,
        $internal.'ResourceSnapshotReader' => ResourceSnapshotReader::class,
        $internal.'AnchorHistory' => AnchorHistory::class,
        $internal.'PhysicalFence' => PhysicalFence::class,
    ];

    foreach ($aliases as $legacy => $target) {
        if (! class_exists($legacy, false) && ! interface_exists($legacy, false)) {
            class_alias($target, $legacy);
        }
    }
})();
