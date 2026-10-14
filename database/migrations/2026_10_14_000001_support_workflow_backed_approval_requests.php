<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_requests', function (Blueprint $table): void {
            $table->foreignId('agent_assignment_id')->nullable()->change();
        });
        Schema::table('approval_requests', function (Blueprint $table): void {
            $table->foreignId('workflow_execution_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('consumed_workflow_execution_id')->nullable()->constrained('workflow_executions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('approval_requests', function (Blueprint $table): void {
            $table->dropForeign(['consumed_workflow_execution_id']);
            $table->dropForeign(['workflow_execution_id']);
            $table->dropColumn(['consumed_workflow_execution_id', 'workflow_execution_id']);
            $table->foreignId('agent_assignment_id')->nullable(false)->change();
        });
    }
};
