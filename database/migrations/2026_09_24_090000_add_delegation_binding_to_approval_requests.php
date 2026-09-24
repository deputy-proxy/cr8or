<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_requests', function (Blueprint $table): void {
            $table->foreignId('agent_delegation_id')
                ->nullable()
                ->after('agent_execution_id')
                ->constrained('agent_delegations')
                ->nullOnDelete();

            $table->foreignId('consumed_agent_execution_id')
                ->nullable()
                ->after('agent_delegation_id')
                ->constrained('agent_executions')
                ->nullOnDelete();

            $table->foreignId('consumed_agent_delegation_id')
                ->nullable()
                ->after('consumed_agent_execution_id')
                ->constrained('agent_delegations')
                ->nullOnDelete();

            $table->index('agent_delegation_id');
            $table->index('consumed_agent_execution_id');
            $table->index('consumed_agent_delegation_id');
        });
    }

    public function down(): void
    {
        Schema::table('approval_requests', function (Blueprint $table): void {
            $table->dropForeign(['consumed_agent_delegation_id']);
            $table->dropForeign(['consumed_agent_execution_id']);
            $table->dropForeign(['agent_delegation_id']);
            $table->dropIndex(['consumed_agent_delegation_id']);
            $table->dropIndex(['consumed_agent_execution_id']);
            $table->dropIndex(['agent_delegation_id']);
            $table->dropColumn(['consumed_agent_delegation_id', 'consumed_agent_execution_id', 'agent_delegation_id']);
        });
    }
};
