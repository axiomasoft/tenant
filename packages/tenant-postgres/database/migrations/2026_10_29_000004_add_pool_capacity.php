<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void { Schema::table('tenant_pg_clusters',function(Blueprint $t):void{$t->unsignedInteger('database_count')->default(0);$t->unsignedInteger('capacity')->default(0);}); }
    public function down():void { Schema::table('tenant_pg_clusters',function(Blueprint $t):void{$t->dropColumn(['database_count','capacity']);}); }
};
