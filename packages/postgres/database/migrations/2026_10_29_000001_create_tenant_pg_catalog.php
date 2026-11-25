<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        Schema::create('tenant_pg_clusters', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->string('status');
            $table->unsignedInteger('template_version');
        });

        Schema::create('tenant_pg_templates', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->unsignedInteger('version')->unique();
            $table->string('status');
            $table->string('db_name', 63)->unique();
            $table->string('schema_hash', 64)->nullable();
        });

        Schema::create('tenant_pg_resources', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->uuid('cluster_id');
            $table->unsignedInteger('template_version');
            $table->string('status');
            $table->timestamp('deleted_at')->nullable();
            $table->string('signup_key', 128)->nullable();
            $table->string('signup_request_digest', 64)->nullable();
            $table->uuid('signup_cluster_id')->nullable();
            $table->uuid('signup_template_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique('signup_key', 'tenant_pg_resources_signup_key_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_pg_resources');
        Schema::dropIfExists('tenant_pg_templates');
        Schema::dropIfExists('tenant_pg_clusters');
    }
};
