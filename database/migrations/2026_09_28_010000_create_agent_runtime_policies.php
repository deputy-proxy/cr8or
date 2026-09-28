<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_runtime_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('environment');
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('enterprise_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('agent_descriptor_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('expert_descriptor_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->nullable();
            $table->unsignedInteger('max_steps')->nullable();
            $table->unsignedInteger('max_retries')->nullable();
            $table->unsignedInteger('timeout_seconds')->nullable();
            $table->unsignedInteger('max_context_bytes')->nullable();
            $table->unsignedInteger('retrieved_knowledge_limit')->nullable();
            $table->unsignedInteger('memory_limit')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->json('fallback_providers')->nullable();
            $table->timestamps();

            $table->index(['environment', 'organization_id', 'enterprise_id']);
            $table->index(['environment', 'organization_id', 'agent_descriptor_id']);
            $table->index(['environment', 'organization_id', 'expert_descriptor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_runtime_policies');
    }
};