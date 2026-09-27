<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_semantic_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('enterprise_id')->constrained()->restrictOnDelete();
            $table->foreignId('agent_descriptor_id')->constrained()->restrictOnDelete();
            $table->text('statement');
            $table->decimal('confidence', 5, 4);
            $table->string('status');
            $table->json('conflict_memory_ids')->nullable();
            $table->json('provenance');
            $table->timestamps();

            $table->index(['organization_id', 'enterprise_id', 'agent_descriptor_id']);
            $table->index(['enterprise_id', 'status', 'updated_at']);
        });

        Schema::create('agent_semantic_memory_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_semantic_memory_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('enterprise_id')->constrained()->restrictOnDelete();
            $table->foreignId('agent_descriptor_id')->constrained()->restrictOnDelete();
            $table->text('statement');
            $table->decimal('confidence', 5, 4);
            $table->string('status');
            $table->json('conflict_memory_ids')->nullable();
            $table->json('provenance');
            $table->string('change_type');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['agent_semantic_memory_id', 'recorded_at']);
            $table->index(['enterprise_id', 'agent_descriptor_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_semantic_memory_versions');
        Schema::dropIfExists('agent_semantic_memories');
    }
};
