<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        Schema::create('tenant_pg_control', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('recovery_generation', 128);
        });

        Schema::create('tenant_pg_lifecycle_operations', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->string('key_hash', 64)->unique();
            $table->string('key_namespace', 128);
            $table->string('operation_key', 128);
            $table->string('input_digest', 64);
            $table->string('intent_digest', 64);
            $table->string('kind', 16);
            $table->string('purpose_ref', 128);
            $table->string('resource_ref', 128);
            $table->string('incarnation', 128);
            $table->uuid('cluster_id');
            $table->string('database_name', 63);
            $table->string('route_revision', 128);
            $table->string('recovery_generation', 128);
            $table->string('status', 16);
            $table->unsignedInteger('attempt_generation');
            $table->unsignedInteger('receipt_version');
            $table->string('observed_effect', 32);
            $table->boolean('cancel_requested')->default(false);
            $table->uuid('export_id')->nullable()->unique();
            $table->json('export_ref')->nullable();
            $table->string('destination_ref', 128)->nullable();
            $table->string('retention_ref', 128)->nullable();
            $table->json('manifest')->nullable();
            $table->integer('backend_pid')->nullable();
            $table->string('backend_start', 128)->nullable();
            $table->string('database_oid', 32)->nullable();
        });

        Schema::create('tenant_pg_lifecycle_outbox', function (Blueprint $table): void {
            $this->uuidPrimary($table, 'operation_id');
            $table->string('resource_ref', 128);
            $table->string('incarnation', 128);
            $table->string('effect', 32);
            $table->unsignedInteger('receipt_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_pg_lifecycle_outbox');
        Schema::dropIfExists('tenant_pg_lifecycle_operations');
        Schema::dropIfExists('tenant_pg_control');
    }
};
