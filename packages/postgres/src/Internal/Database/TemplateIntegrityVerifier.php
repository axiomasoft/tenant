<?php

declare(strict_types=1);

namespace Tenant\Postgres\Internal\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Eloquent\Model;
use Packman\Attributes\Internal;
use stdClass;
use Tenant\Postgres\Contracts\Provisioning\TemplateIntegrityVerifier as TemplateIntegrityVerifierContract;
use Tenant\Postgres\Data\Provisioning\ClaimRequest;
use Tenant\Postgres\Data\Provisioning\QualifiedTemplate;
use Tenant\Postgres\Data\Provisioning\ResourceSnapshot;
use Tenant\Postgres\Data\Provisioning\TemplateTrust;
use Tenant\Postgres\Exceptions\Provisioning\TemplateIntegrityException;

/** @internal */
#[Internal]
final readonly class TemplateIntegrityVerifier implements TemplateIntegrityVerifierContract
{
    public function __construct(
        private string $centralConnection,
        private SchemaCatalogueSerializer $serializer,
        private TemplateIntegrityLock $lock,
        private TemplateTrust $options,
        private ConnectionResolverInterface $connections,
        private ConnectionFactory $connectionFactory,
    ) {}

    public function qualify(ClaimRequest $request): QualifiedTemplate
    {
        $template = $this->verify(templateId: $request->templateId, version: null, clusterId: $request->clusterId);

        return new QualifiedTemplate(
            clusterId: $request->clusterId,
            templateId: DatabaseValue::text($template->id),
            templateVersion: DatabaseValue::integer($template->version),
            artifactDigest: $this->manifestFor(DatabaseValue::text($template->id))->digest($this->serializer),
        );
    }

    public function verifyCurrent(int $version, ?string $clusterId = null): void
    {
        $this->assertCurrent(version: $version, clusterId: $clusterId);
    }

    public function verifyAccount(ResourceSnapshot $account): void
    {
        $this->assertAccount((object) [
            'template_version' => $account->templateVersion,
            'cluster_id' => $account->clusterId,
            'db_name' => $account->databaseName,
        ]);
    }

    /** @return stdClass */
    public function assertCurrent(int $version, ?string $clusterId = null): object
    {
        return $this->verify(templateId: null, version: $version, clusterId: $clusterId);
    }

    public function assertAccount(object $account): object
    {
        if (! $account instanceof Model && ! $account instanceof stdClass) {
            throw new TemplateIntegrityException('The account template view is malformed.');
        }
        $template = $this->assertCurrent(
            version: DatabaseValue::integer($account instanceof Model ? $account->getAttribute('template_version') : $account->template_version),
            clusterId: DatabaseValue::text($account instanceof Model ? $account->getAttribute('cluster_id') : $account->cluster_id),
        );
        $actualHash = $this->actualHashForDatabase(DatabaseValue::text($account instanceof Model ? $account->getAttribute('db_name') : $account->db_name));

        if ($actualHash !== $template->schema_hash) {
            throw new TemplateIntegrityException('The account database no longer matches its trusted template schema.');
        }

        return $template;
    }

    private function verify(?string $templateId, ?int $version, ?string $clusterId): stdClass
    {
        $connection = $this->db();

        return $connection->transaction(function () use ($connection, $templateId, $version, $clusterId): stdClass {
            // Resolve the lock identity without a row lock, then always take advisory
            // before row. Cloners hold the same advisory lock for the whole DDL session.
            $lookup = $connection->table('tenant_pg_templates')->where('status', 'ready');
            if ($templateId !== null) {
                $lookup->where('id', $templateId);
            }
            if ($version !== null) {
                $lookup->where('version', $version);
            }
            $identity = $lookup->first(['id']);
            if ($identity === null) {
                throw new TemplateIntegrityException('The requested template is unavailable.');
            }
            $this->lock->acquire(connection: $connection, templateId: DatabaseValue::text($identity->id));
            $query = $connection->table('tenant_pg_templates')->where('id', $identity->id)->where('status', 'ready');
            if ($templateId !== null) {
                $query->where('id', $templateId);
            }
            if ($version !== null) {
                $query->where('version', $version);
            }
            $template = $query->lockForUpdate()->first();
            if ($template === null || ! is_string($template->schema_hash)) {
                throw new TemplateIntegrityException('The requested template has no trusted schema hash.');
            }

            if ($clusterId !== null && ! $connection->table('tenant_pg_clusters')
                ->where('id', $clusterId)->where('status', 'active')->where('template_version', $template->version)->exists()) {
                throw new TemplateIntegrityException('The cluster does not currently admit this template.');
            }

            $manifest = $this->manifestFor(DatabaseValue::text($template->id));
            $manifest->assertTrusted(
                trustedKeys: $this->tenancyOptions()->templateIntegrityTrustedKeys,
                serializer: $this->serializer,
            );
            $actualHash = $this->actualHash($template);
            $manifest->assertMatches(template: $template, actualHash: $actualHash);

            return $template;
        });
    }

    private function actualHash(stdClass $template): string
    {
        return $this->actualHashForDatabase(DatabaseValue::text($template->db_name));
    }

    private function actualHashForDatabase(string $database): string
    {
        if (! PostgresIdentifier::isValid($database)) {
            throw new TemplateIntegrityException('Invalid PostgreSQL identifier.');
        }
        $central = $this->db();
        $configuration = DbRow::configMap($central);

        if (! PostgresRoute::isDirect($configuration)) {
            throw new TemplateIntegrityException('The template connection requires a direct PostgreSQL route.');
        }
        $configuration['database'] = $database;
        $connection = $this->connectionFactory->make($configuration, 'tenant-pg-template-catalogue');

        try {
            return $this->serializer->hash($connection);
        } finally {
            $connection->disconnect();
        }
    }

    private function manifestFor(string $templateId): TemplateManifest
    {
        $manifests = $this->tenancyOptions()->templateIntegrityManifests;
        $manifest = $manifests[$templateId] ?? null;

        if (! is_array($manifest)) {
            throw new TemplateIntegrityException('No trusted manifest is configured for the template.');
        }

        return TemplateManifest::fromConfig($manifest);
    }

    private function tenancyOptions(): TemplateTrust
    {
        return $this->options;
    }

    private function db(): Connection
    {
        /** @var Connection */
        $connection = $this->connections->connection($this->centralConnection);

        return $connection;
    }
}
