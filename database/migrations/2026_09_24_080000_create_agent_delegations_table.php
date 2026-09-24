<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('enterprise_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_agent_assignment_id')->constrained('agent_assignments')->restrictOnDelete();
            $table->foreignId('target_agent_assignment_id')->constrained('agent_assignments')->restrictOnDelete();
            $table->foreignId('parent_agent_execution_id')->nullable()->constrained('agent_executions')->nullOnDelete();
            $table->foreignId('target_agent_execution_id')->nullable()->constrained('agent_executions')->nullOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('organization_name');
            $table->string('enterprise_name');
            $table->string('source_agent_slug');
            $table->string('source_agent_runtime_class');
            $table->string('target_agent_slug');
            $table->string('target_agent_runtime_class');
            $table->string('actor_name');
            $table->string('capability');
            $table->text('prompt');
            $table->json('target_context')->nullable();
            $table->string('correlation_id');
            $table->string('idempotency_key');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('status');
            $table->timestamp('requested_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'idempotency_key']);
            $table->index(['organization_id', 'status', 'requested_at']);
            $table->index(['enterprise_id', 'requested_at']);
            $table->index(['source_agent_assignment_id', 'requested_at']);
            $table->index(['target_agent_assignment_id', 'requested_at']);
            $table->index('parent_agent_execution_id');
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_delegations');
    }
};
