<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        Schema::table('tenant_pg_clusters', function (Blueprint $table): void {
            $table->unsignedInteger('database_count')->default(0);
            $table->unsignedInteger('capacity')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('tenant_pg_clusters', function (Blueprint $table): void {
            $table->dropColumn(['database_count', 'capacity']);
        });
    }
};
