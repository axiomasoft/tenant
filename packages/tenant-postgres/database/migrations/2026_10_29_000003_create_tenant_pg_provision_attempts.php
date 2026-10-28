<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('tenant_pg_provision_attempts', function(Blueprint $t):void {
        $t->uuid('id')->primary();$t->string('key_hash',64)->unique();$t->string('intent_digest',64);$t->string('key_namespace',128);$t->string('operation_key',128);$t->string('host_intent_ref',128);
        $t->string('resource_ref',128);$t->string('incarnation',128);$t->uuid('cluster_id');$t->uuid('template_id');$t->unsignedInteger('template_version');$t->string('manifest_hash',64);$t->string('template_database',63);$t->string('database_name',63)->unique();
        $t->string('status',16);$t->unsignedInteger('attempt_generation');$t->unsignedInteger('receipt_version');$t->string('observed_effect',32);
    }); }
    public function down():void { Schema::dropIfExists('tenant_pg_provision_attempts'); }
};
