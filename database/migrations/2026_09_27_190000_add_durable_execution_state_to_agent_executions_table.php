<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_executions', function (Blueprint $table): void {
            $table->unsignedInteger('max_steps')->default(5)->after('status');
            $table->unsignedInteger('current_step')->default(0)->after('max_steps');
            $table->text('prompt')->nullable()->after('current_step');
            $table->json('target_context')->nullable()->after('prompt');
            $table->json('expert_slugs')->nullable()->after('target_context');
            $table->json('model_options')->nullable()->after('expert_slugs');
            $table->json('execution_context')->nullable()->after('model_options');
            $table->json('last_result')->nullable()->after('execution_context');
            $table->text('next_step')->nullable()->after('last_result');
            $table->string('state_reason')->nullable()->after('next_step');
            $table->string('idempotency_key', 128)->nullable()->after('correlation_id');

            $table->unique(['organization_id', 'idempotency_key']);
            $table->index(['enterprise_id', 'agent_assignment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('agent_executions', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'idempotency_key']);
            $table->dropIndex(['enterprise_id', 'agent_assignment_id', 'status']);
            $table->dropColumn([
                'max_steps',
                'current_step',
                'prompt',
                'target_context',
                'expert_slugs',
                'model_options',
                'execution_context',
                'last_result',
                'next_step',
                'state_reason',
                'idempotency_key',
            ]);
        });
    }
};