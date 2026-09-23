<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->foreignId('agent_execution_id')->nullable()->after('audience_id')->constrained('agent_executions')->nullOnDelete();
            $table->foreignId('agent_decision_id')->nullable()->after('agent_execution_id')->constrained('agent_decisions')->nullOnDelete();
            $table->index(['agent_execution_id', 'agent_decision_id']);
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->dropForeign(['agent_decision_id']);
            $table->dropForeign(['agent_execution_id']);
            $table->dropIndex(['agent_execution_id', 'agent_decision_id']);
            $table->dropColumn(['agent_execution_id', 'agent_decision_id']);
        });
    }
};
