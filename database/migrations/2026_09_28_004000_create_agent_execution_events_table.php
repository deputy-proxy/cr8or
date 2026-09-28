<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_execution_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enterprise_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_execution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 191);
            $table->unsignedInteger('version')->default(1);
            $table->string('visibility', 32)->default('internal');
            $table->string('correlation_id', 128)->nullable();
            $table->json('provenance')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['agent_execution_id', 'occurred_at']);
            $table->index(
                ['organization_id', 'enterprise_id', 'occurred_at'],
                'aee_org_ent_occurred_idx'
            );
            $table->index(['event_type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_execution_events');
    }
};
