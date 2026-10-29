<?php

declare(strict_types=1);

namespace Tenant\Postgres\Internal;

use Tenant\Postgres\Contracts\ProvisioningClaim;
use Tenant\Postgres\Contracts\TemplateIntegrityVerifier;
use Tenant\Postgres\Data\ClaimRequest;
use Tenant\Postgres\Data\ClaimResult;
use Tenant\Postgres\Data\QualifiedTemplate;
use Tenant\Postgres\Exceptions\ClaimConflict;
use Tenant\Postgres\Exceptions\ClaimUnavailable;
use Tenant\Postgres\Exceptions\PoolExhausted;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\QueryException;
use stdClass;

/** @internal */
final readonly class ClaimResource implements ProvisioningClaim
{
    private const string SignupKeyIndex = 'tenant_pg_resources_signup_key_uq';

    private const int MaxTransactionAttempts = 3;

    public function __construct(
        private string $centralConnection,
        private TemplateIntegrityVerifier $templateIntegrity,
        private ConnectionResolverInterface $connections,
    ) {}

    public function claim(ClaimRequest $request, ?string $originalResourceId = null): ClaimResult
    {
        $this->validateRequest($request);

        for ($attempt = 1; $attempt <= self::MaxTransactionAttempts; $attempt++) {
            try {
                return $this->connection()->transaction(
                    callback: fn (): ClaimResult => $this->claimInTransaction($request, $originalResourceId),
                    attempts: 1,
                );
            } catch (QueryException $exception) {
                if ($this->isNamedSignupKeyViolation($exception)) {
                    continue;
                }

                if (! $this->isRetryableTransactionFailure($exception) || $attempt === self::MaxTransactionAttempts) {
                    throw $exception;
                }
            }
        }

        throw new ClaimUnavailable('The signup claim could not be reconciled.');
    }

    private function claimInTransaction(ClaimRequest $request, ?string $originalResourceId): ClaimResult
    {
        $connection = $this->connection();

        $connection->select(
            query: 'SELECT pg_advisory_xact_lock(CAST(? AS bigint))',
            bindings: [$this->advisoryLockId($request->requestKey)],
        );

        $bindings = $connection->table('tenant_pg_resources')
            ->select(['id', 'status', 'deleted_at', 'signup_key', 'signup_request_digest', 'signup_cluster_id', 'signup_template_id'])
            ->where('signup_key', $request->requestKey)
            ->orderBy('id')
            ->lock('FOR UPDATE')
            ->get();

        if ($bindings->count() > 1) {
            throw new ClaimConflict('The signup key has ambiguous account bindings.');
        }

        $binding = $bindings->first();

        if ($binding !== null) {
            if ($originalResourceId !== null && $binding->id !== $originalResourceId) throw new ClaimConflict('Original allocation differs from signup binding.');
            return $this->replayResult(binding: $binding, request: $request);
        }

        $qualifiedTemplate = $this->templateIntegrity->qualify($request);
        $this->validateQualifiedTemplate(qualifiedTemplate: $qualifiedTemplate, request: $request);
        $this->lockAndVerifyTemplate(connection: $connection, qualifiedTemplate: $qualifiedTemplate);

        $candidateQuery = $connection->table('tenant_pg_resources')
            ->select(['id', 'cluster_id', 'template_version'])
            ->where('cluster_id', $request->clusterId)
            ->where('template_version', $qualifiedTemplate->templateVersion)
            ->where('status', 'pending')
            ->whereNull('deleted_at')
            ->whereNull('signup_key');
        if ($originalResourceId !== null) $candidateQuery->where('id', $originalResourceId);
        $candidate = $candidateQuery->orderBy('id')
            ->lock('FOR UPDATE SKIP LOCKED')
            ->first();

        if ($candidate === null) {
            throw new PoolExhausted('No qualified pending resource is available.');
        }

        $updated = $connection->update(
            query: "UPDATE tenant_pg_resources
                SET status = ?, signup_key = ?, signup_request_digest = ?, signup_cluster_id = ?, signup_template_id = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
                  AND status = 'pending'
                  AND deleted_at IS NULL
                  AND signup_key IS NULL",
            bindings: ['provisioning', $request->requestKey, $request->requestDigest, $request->clusterId, $request->templateId, $candidate->id],
        );

        if ($updated !== 1) {
            throw new ClaimUnavailable('The pending account changed before reservation.');
        }

        return new ClaimResult(resourceId: DatabaseValue::text($candidate->id), replayed: false);
    }

    private function replayResult(stdClass $binding, ClaimRequest $request): ClaimResult
    {
        if ($binding->deleted_at !== null
            || $binding->status === 'deleted'
            || $binding->signup_key !== $request->requestKey
            || $binding->signup_request_digest !== $request->requestDigest
            || $binding->signup_cluster_id !== $request->clusterId
            || $binding->signup_template_id !== $request->templateId) {
            throw new ClaimConflict('The signup key is already bound to a different request.');
        }

        return new ClaimResult(resourceId: DatabaseValue::text($binding->id), replayed: true);
    }

    private function lockAndVerifyTemplate(Connection $connection, QualifiedTemplate $qualifiedTemplate): void
    {
        (new \Tenant\Postgres\Internal\Database\TemplateIntegrityLock)->acquire($connection, $qualifiedTemplate->templateId);
        $cluster = $connection->table('tenant_pg_clusters')
            ->where('id', $qualifiedTemplate->clusterId)
            ->where('status', 'active')
            ->where('template_version', $qualifiedTemplate->templateVersion)
            ->lock('FOR UPDATE')
            ->first(['id']);

        $template = $connection->table('tenant_pg_templates')
            ->where('id', $qualifiedTemplate->templateId)
            ->where('version', $qualifiedTemplate->templateVersion)
            ->where('status', 'ready')
            ->lock('FOR UPDATE')
            ->first(['id']);

        if ($cluster === null || $template === null) {
            throw new ClaimUnavailable('The qualified template is no longer current.');
        }
    }

    private function validateRequest(ClaimRequest $request): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $request->requestKey) !== 1) {
            throw new ClaimConflict('The signup key is invalid.');
        }

        if (! $this->isCanonicalUuid($request->clusterId) || ! $this->isCanonicalUuid($request->templateId)) {
            throw new ClaimConflict('The cluster and template identifiers must be canonical UUIDs.');
        }

        if (preg_match('/^[a-f0-9]{64}$/D', $request->requestDigest) !== 1) {
            throw new ClaimConflict('The request digest is invalid.');
        }
    }

    private function validateQualifiedTemplate(QualifiedTemplate $qualifiedTemplate, ClaimRequest $request): void
    {
        if ($qualifiedTemplate->clusterId !== $request->clusterId
            || $qualifiedTemplate->templateId !== $request->templateId
            || $qualifiedTemplate->templateVersion < 1
            || preg_match('/^[a-f0-9]{64}$/D', $qualifiedTemplate->artifactDigest) !== 1
            || ($request->expectedTemplateVersion !== null && $qualifiedTemplate->templateVersion !== $request->expectedTemplateVersion)
            || ($request->expectedManifestHash !== null && ! hash_equals($request->expectedManifestHash, $qualifiedTemplate->artifactDigest))) {
            throw new ClaimUnavailable('The template proof does not match the request.');
        }
    }

    private function advisoryLockId(string $requestKey): string
    {
        $bytes = substr(hash('sha256', "corex:signup:v1:{$requestKey}", binary: true), 0, 8);

        $unpacked = unpack('q', strrev($bytes));

        if ($unpacked === false || ! is_int($unpacked[1] ?? null)) {
            throw new ClaimUnavailable('The signup advisory lock could not be derived.');
        }

        return (string) $unpacked[1];
    }

    private function isCanonicalUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
    }

    private function isNamedSignupKeyViolation(QueryException $exception): bool
    {
        return $this->sqlState($exception) === '23505'
            && str_contains($exception->getMessage(), self::SignupKeyIndex);
    }

    private function isRetryableTransactionFailure(QueryException $exception): bool
    {
        return in_array($this->sqlState($exception), ['40001', '40P01'], strict: true);
    }

    private function sqlState(QueryException $exception): ?string
    {
        $previous = $exception->getPrevious();

        $code = $previous?->getCode();

        return is_string($code) && $code !== '' ? $code : null;
    }

    public function usesConnection(Connection $connection): bool
    {
        return $this->connection() === $connection;
    }

    private function connection(): Connection
    {
        /** @var Connection */
        $connection = $this->connections->connection($this->centralConnection);

        return $connection;
    }
}
