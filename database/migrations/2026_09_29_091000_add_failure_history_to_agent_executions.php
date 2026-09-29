<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_executions', function (Blueprint $table): void {
            $table->json('failure_history')->nullable()->after('failure_provenance');
        });
    }

    public function down(): void
    {
        Schema::table('agent_executions', function (Blueprint $table): void {
            $table->dropColumn('failure_history');
        });
    }
};