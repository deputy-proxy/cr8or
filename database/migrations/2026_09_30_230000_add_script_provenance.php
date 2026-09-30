<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scripts', function (Blueprint $table): void {
            $table->foreignId('agent_assignment_id')->nullable()->after('content_item_id')->constrained('agent_assignments')->nullOnDelete();
            $table->foreignId('agent_execution_id')->nullable()->after('agent_assignment_id')->constrained('agent_executions')->nullOnDelete();
            $table->index(['agent_assignment_id', 'agent_execution_id']);
        });
    }

    public function down(): void
    {
        Schema::table('scripts', function (Blueprint $table): void {
            $table->dropForeign(['agent_execution_id']);
            $table->dropForeign(['agent_assignment_id']);
            $table->dropIndex(['agent_assignment_id', 'agent_execution_id']);
            $table->dropColumn(['agent_assignment_id', 'agent_execution_id']);
        });
    }
};