<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_pg_resources', function (Blueprint $table): void {
            $table->string('resource_ref', 128)->nullable()->unique();
            $table->string('incarnation', 128)->nullable();
            $table->string('database_name', 63)->nullable()->unique();
            $table->string('route_revision', 128)->nullable();
            $table->string('recovery_generation', 128)->nullable();
        });
        Schema::create('tenant_pg_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key_hash', 64)->unique();
            $table->string('intent_digest', 64);
            $table->string('key_namespace', 128);
            $table->string('operation_key', 128);
            $table->string('host_intent_ref', 128);
            $table->uuid('resource_id');
            $table->string('resource_ref', 128);
            $table->string('incarnation', 128);
            $table->string('status', 16);
            $table->unsignedInteger('attempt_generation');
            $table->unsignedInteger('receipt_version');
            $table->string('observed_effect', 32);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('tenant_pg_operations');
        Schema::table('tenant_pg_resources', function (Blueprint $table): void {
            $table->dropColumn(['resource_ref','incarnation','database_name','route_revision','recovery_generation']);
        });
    }
};
