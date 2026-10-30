<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('objectives', 'status')) {
            Schema::table('objectives', function (Blueprint $table): void {
                $table->string('status')->default('active');
            });
        }

        if (! Schema::hasIndex('objectives', 'objectives_enterprise_id_status_index')) {
            Schema::table('objectives', function (Blueprint $table): void {
                $table->index(['enterprise_id', 'status'], 'objectives_enterprise_id_status_index');
            });
        }

        // Lifecycle snapshots were retired from the application. Existing installations
        // retain their historical table and rows; fresh installations do not create it.

    }

    public function down(): void
    {
        // Deliberately preserve any legacy lifecycle_snapshots table and its historical data.

        if (Schema::hasIndex('objectives', 'objectives_enterprise_id_status_index')) {
            Schema::table('objectives', function (Blueprint $table): void {
                $table->dropIndex('objectives_enterprise_id_status_index');
            });
        }

        if (Schema::hasColumn('objectives', 'status')) {
            Schema::table('objectives', function (Blueprint $table): void {
                $table->dropColumn('status');
            });
        }
    }
};
