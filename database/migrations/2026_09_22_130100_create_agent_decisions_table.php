<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('enterprise_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('execution_id')->nullable()->constrained('agent_executions')->nullOnDelete();
            $table->foreignId('agent_descriptor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('organization_name')->nullable();
            $table->string('enterprise_name')->nullable();
            $table->string('agent_slug')->nullable();
            $table->string('agent_runtime_class')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('title');
            $table->text('summary');
            $table->text('rationale')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->index(['organization_id', 'decided_at']);
            $table->index(['enterprise_id', 'decided_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_decisions');
    }
};