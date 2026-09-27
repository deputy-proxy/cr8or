<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_execution_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('enterprise_id')->constrained()->restrictOnDelete();
            $table->foreignId('agent_execution_id')->constrained('agent_executions')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('status', 32);
            $table->string('type', 32)->default('reasoning');
            $table->text('intent')->nullable();
            $table->json('input_context')->nullable();
            $table->json('output')->nullable();
            $table->json('capability_requests')->nullable();
            $table->text('failure_reason')->nullable();
            $table->string('failure_code')->nullable();
            $table->string('correlation_id')->nullable();
            $table->string('idempotency_key', 128);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['agent_execution_id', 'sequence']);
            $table->unique(['organization_id', 'idempotency_key']);
            $table->index(['agent_execution_id', 'status']);
            $table->index(['enterprise_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_execution_steps');
    }
};