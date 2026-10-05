<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflows', function (Blueprint $table): void {
            $table->boolean('enterprise_specific')->default(true)->after('enterprise_id');
        });

        Schema::table('workflows', function (Blueprint $table): void {
            $table->foreignId('enterprise_id')->nullable()->change();
        });

        Schema::table('workflow_versions', function (Blueprint $table): void {
            $table->foreignId('enterprise_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('workflows')->where('enterprise_specific', false)->exists()) {
            throw new RuntimeException('Cannot roll back workflow scope while generic Workflows exist.');
        }

        if (DB::table('workflow_versions')->whereNull('enterprise_id')->exists()) {
            throw new RuntimeException('Cannot roll back workflow scope while generic WorkflowVersions exist.');
        }

        Schema::table('workflow_versions', function (Blueprint $table): void {
            $table->foreignId('enterprise_id')->nullable(false)->change();
        });

        Schema::table('workflows', function (Blueprint $table): void {
            $table->foreignId('enterprise_id')->nullable(false)->change();
            $table->dropColumn('enterprise_specific');
        });
    }
};