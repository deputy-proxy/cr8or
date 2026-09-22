<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('enterprise_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_assignment_id')->constrained()->restrictOnDelete();
            $table->foreignId('agent_execution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('capability');
            $table->json('target_context')->nullable();
            $table->string('organization_name')->nullable();
            $table->string('enterprise_name')->nullable();
            $table->string('agent_slug')->nullable();
            $table->string('agent_runtime_class')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('approver_name')->nullable();
            $table->string('status');
            $table->timestamp('requested_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status', 'requested_at']);
            $table->index(['agent_assignment_id', 'capability', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
