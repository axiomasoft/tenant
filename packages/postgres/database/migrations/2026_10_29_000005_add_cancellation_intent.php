<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        Schema::table('tenant_pg_provision_attempts', function (Blueprint $table): void {
            $table->boolean('cancel_requested')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('tenant_pg_provision_attempts', function (Blueprint $table): void {
            $table->dropColumn('cancel_requested');
        });
    }
};
