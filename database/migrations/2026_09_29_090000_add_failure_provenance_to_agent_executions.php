<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_executions', function (Blueprint $table): void {
            $table->json('failure_provenance')->nullable()->after('failure_category');
        });

        Schema::table('agent_execution_steps', function (Blueprint $table): void {
            $table->json('failure_provenance')->nullable()->after('failure_code');
        });
    }

    public function down(): void
    {
        Schema::table('agent_execution_steps', function (Blueprint $table): void {
            $table->dropColumn('failure_provenance');
        });

        Schema::table('agent_executions', function (Blueprint $table): void {
            $table->dropColumn('failure_provenance');
        });
    }
};