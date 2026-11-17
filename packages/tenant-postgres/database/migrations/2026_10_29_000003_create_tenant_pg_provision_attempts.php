<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        Schema::create('tenant_pg_provision_attempts', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->string('key_hash', 64)->unique();
            $table->string('intent_digest', 64);
            $table->string('key_namespace', 128);
            $table->string('operation_key', 128);
            $table->string('host_intent_ref', 128);
            $table->string('resource_ref', 128);
            $table->string('incarnation', 128);
            $table->uuid('cluster_id');
            $table->uuid('template_id');
            $table->unsignedInteger('template_version');
            $table->string('manifest_hash', 64);
            $table->string('template_database', 63);
            $table->string('database_name', 63)->unique();
            $table->string('status', 16);
            $table->unsignedInteger('attempt_generation');
            $table->unsignedInteger('receipt_version');
            $table->string('observed_effect', 32);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_pg_provision_attempts');
    }
};
