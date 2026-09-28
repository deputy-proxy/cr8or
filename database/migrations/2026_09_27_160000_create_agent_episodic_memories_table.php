<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_episodic_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('enterprise_id')->constrained()->restrictOnDelete();
            $table->foreignId('agent_descriptor_id')->constrained()->restrictOnDelete();
            $table->foreignId('execution_id')->constrained('agent_executions')->restrictOnDelete();
            $table->string('topic')->nullable();
            $table->text('objective');
            $table->text('action');
            $table->text('result');
            $table->text('outcome');
            $table->timestamp('occurred_at');
            $table->json('provenance');
            $table->timestamps();

            $table->index(
                ['organization_id', 'enterprise_id', 'occurred_at'],
                'aem_org_ent_occurred_idx'
            );
            $table->index(
                ['enterprise_id', 'agent_descriptor_id', 'occurred_at'],
                'aem_ent_agent_occurred_idx'
            );
            $table->index(
                ['enterprise_id', 'topic', 'occurred_at'],
                'aem_ent_topic_occurred_idx'
            );
            $table->index(
                ['execution_id', 'occurred_at'],
                'aem_execution_occurred_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_episodic_memories');
    }
};
