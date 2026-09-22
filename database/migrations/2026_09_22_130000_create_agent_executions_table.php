<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('enterprise_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_descriptor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('organization_name')->nullable();
            $table->string('enterprise_name')->nullable();
            $table->string('agent_slug')->nullable();
            $table->string('agent_runtime_class')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('status');
            $table->timestamp('requested_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status', 'requested_at']);
            $table->index(['enterprise_id', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_executions');
    }
};