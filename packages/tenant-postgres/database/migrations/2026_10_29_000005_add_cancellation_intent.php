<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void { Schema::table('tenant_pg_provision_attempts',function(Blueprint $t):void{$t->boolean('cancel_requested')->default(false);}); }
    public function down():void { Schema::table('tenant_pg_provision_attempts',function(Blueprint $t):void{$t->dropColumn('cancel_requested');}); }
};
