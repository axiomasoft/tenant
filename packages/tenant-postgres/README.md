# Tenant PostgreSQL owner

Optional `PostgresLifecycle` implements provisioning, original export/delete
receipts, cancellation and reconciliation. `PostgresFactory::owner()` composes
the template, clone, pool, binding verifier and lifecycle mechanisms.
The supported factory profile uses one frozen PostgreSQL management server;
cluster selection does not authorize moving a database to a different server.
PHP 8.4/8.5, Illuminate 12/13 and PostgreSQL 16 are qualification lanes.

The host supplies restricted runtime routing, signed template trust, database
naming, a qualified `ArchiveStore`, an independent `RecoveryAnchor`, fresh
`Admission` and an explicit retention policy callback. Missing components refuse
operations. The callback receives kind, typed intent and frozen resource; it is
checked at registration and before effects. Technical readiness gives no paid,
trial, grace, legal retention or commercial entitlement. The lab policy is a
synthetic test policy, not a product retention decision.

`PgDumpArchiveStore` runs a configured native `pg_dump` executable, persists and
syncs custom-format bytes and the original manifest under a private directory.
Use the matching PostgreSQL client version. Its private directory is a trusted,
durable storage boundary; it must not be writable by runtime users. Receipts
contain safe destination/export references, not file paths, DSNs or signed URLs.
Content hash, size, resource, incarnation, route, generation and original export
are checked before readiness and again before DROP. A newer interrupted export
invalidates older readiness. V1 always requires a verified original export for
deletion; retention policies requiring archive-free deletion are unsupported.

Migrations belong solely to this package. `PostgresSchema::migrationPath()` selects
all six on the explicit `tenant_pg_catalog` connection and its single `migrations`
repository, shared by standalone setup and a host schema-provider adapter. Apply
them on a fresh independent catalog, create the external `FileRecoveryAnchor` in a private durable
directory, then initialize `tenant_pg_control` row 1 with its generation. Never
initialize over an existing catalog or missing anchor to bypass recovery checks.
The anchor is outside the catalog backup cohort. Retain anchor entries, operation
rows, tombstones and archive manifests for the whole selected replay horizon.
There is no automatic pruning or forgetting of keys. A write-ahead anchor entry
without its committed catalog operation fails closed and requires operator
recovery of the original history; generating another key is not reconciliation.

Physical effects run outside metadata transactions under the original target's
same-session advisory fence. DDL uses the pinned PDO directly, avoiding framework
reconnection/retry of a lost DDL reply. Running/unknown operations block other
writers. Reconcile requires the original backend PID **and backend start time**
to be absent even if its advisory lock disappeared. Use another management
connection after the original backend exits; never terminate an arbitrary backend
or drop/recreate an uncertain target. An unproven outcome remains quarantined.

Cancellation is a durable request, not rollback of submitted DDL. An observed
late DROP still produces the original tombstone with a cancelled receipt and
`observedEffect=dropped`. A cancelled export never becomes ready. One durable
outbox row accompanies the final original receipt; events and ACKs are not proof.

`admitRecovery()` returns an operator-only `RecoveryPlan`; it never executes a
restore. `ProvisioningOwner::recoveryOperator()` exposes the already composed
operator. `RecoveryOperator` offers explicit quarantine, completion and reopening
steps. The operator stops workers and writes, excludes old backends, advances the
independent generation, restores the exact verified archive into the frozen
isolated target using native tools, and retains all original receipts. Completion
verifies the restored source marker, records a new incarnation before stamping,
then verifies using the restricted runtime role. A clone also has a new logical
resource. Completion remains quarantined; reopening needs separate fresh host
admission. Other resources stay closed until explicitly reconciled. A failed or
interrupted completion cannot be silently restamped or retried; retain its new
binding and recover through an explicit operator procedure.

After restoring the control-plane catalog, `reconcileCatalogGeneration()` refuses
missing original history or unresolved attempts. Changing a catalog-local epoch
cannot replace the independent anchor. Missing anchor, stale references, old
incarnations, incomplete history and unsupported profiles fail closed. Backup
restoration that lost original receipts is unsupported until that exact history
is restored; this implementation does not invent replacements.

`PostgresFactory::bindings($catalog, $runtimeRoute, $anchor)` returns the public
`ResourceBindings` facade. `resolve($resourceRef)` verifies the current catalog,
external anchor and physical marker using the existing non-maintenance verifier;
`verify()` checks a caller's original binding. Its `ResolvedBinding`, expected
coordinates and `PhysicalAccess` route are process-local. Never serialize routes
or replace host admission with a resource reference. Successful claim publication
marks technical availability in the same transaction as the original receipt;
it grants no trial, grace or commercial rights.

The host source cohort selects this owner and retains product
accounts plus original account-to-resource/operation references in the host. It
disables legacy allocator/marker/export writers and mixed physical migrations.
Bulk pending-create/clear is explicitly unsupported. Host product schema DDL uses
an independently admitted management connection through its own schema migrator;
the ordinary runtime role remains unable to create databases or mutate markers.
This source switch is for a fresh disposable baseline, without deployed-data
migration. Final matrix/rollback and independent review remain qualification gates.
E09 licence/support/security decisions remain release blockers. No release or
deployment readiness is claimed by development checks.
