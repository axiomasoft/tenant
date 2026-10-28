<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_pg_control',function(Blueprint $t):void {
            $t->unsignedInteger('id')->primary();$t->string('recovery_generation',128);
        });
        Schema::create('tenant_pg_lifecycle_operations',function(Blueprint $t):void {
            $t->uuid('id')->primary();$t->string('key_hash',64)->unique();$t->string('key_namespace',128);$t->string('operation_key',128);
            $t->string('input_digest',64);$t->string('intent_digest',64);$t->string('kind',16);$t->string('purpose_ref',128);
            $t->string('resource_ref',128);$t->string('incarnation',128);$t->uuid('cluster_id');$t->string('database_name',63);$t->string('route_revision',128);$t->string('recovery_generation',128);
            $t->string('status',16);$t->unsignedInteger('attempt_generation');$t->unsignedInteger('receipt_version');$t->string('observed_effect',32);$t->boolean('cancel_requested')->default(false);
            $t->uuid('export_id')->nullable()->unique();$t->json('export_ref')->nullable();$t->string('destination_ref',128)->nullable();$t->string('retention_ref',128)->nullable();$t->json('manifest')->nullable();
            $t->integer('backend_pid')->nullable();$t->string('backend_start',128)->nullable();$t->string('database_oid',32)->nullable();
        });
        Schema::create('tenant_pg_lifecycle_outbox',function(Blueprint $t):void {
            $t->uuid('operation_id')->primary();$t->string('resource_ref',128);$t->string('incarnation',128);$t->string('effect',32);$t->unsignedInteger('receipt_version');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('tenant_pg_lifecycle_outbox');Schema::dropIfExists('tenant_pg_lifecycle_operations');Schema::dropIfExists('tenant_pg_control');
    }
};
