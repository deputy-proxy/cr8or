<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflows', function (Blueprint $table): void {
            $table->string('purpose')->nullable()->after('name');
            $table->unsignedInteger('version')->default(1)->after('purpose');
            $table->json('execution_policy')->nullable()->after('version');
            $table->json('completion_criteria')->nullable()->after('execution_policy');
        });

        Schema::table('agent_executions', function (Blueprint $table): void {
            $table->foreignId('workflow_id')->nullable()->after('enterprise_id')->constrained('workflows')->nullOnDelete();
            $table->unsignedInteger('workflow_version')->nullable()->after('workflow_id');
            $table->index(['workflow_id', 'status']);
        });

        Schema::create('workflow_stages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_id')->constrained('workflows')->cascadeOnDelete();
            $table->string('key', 100);
            $table->string('name');
            $table->unsignedInteger('sequence');
            $table->json('dependencies')->nullable();
            $table->json('expert_slugs')->nullable();
            $table->json('capability_slugs')->nullable();
            $table->json('input_contract')->nullable();
            $table->json('output_contract')->nullable();
            $table->boolean('repeatable')->default(false);
            $table->json('completion_criteria')->nullable();
            $table->timestamps();
            $table->unique(['workflow_id', 'key']);
            $table->unique(['workflow_id', 'sequence']);
            $table->index(['workflow_id', 'sequence']);
        });

        Schema::table('agent_execution_steps', function (Blueprint $table): void {
            $table->foreignId('workflow_stage_id')->nullable()->after('agent_execution_id')->constrained('workflow_stages')->nullOnDelete();
            $table->index(['workflow_stage_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('agent_execution_steps', function (Blueprint $table): void {
            $table->dropForeign(['workflow_stage_id']);
            $table->dropIndex(['workflow_stage_id', 'status']);
            $table->dropColumn('workflow_stage_id');
        });

        Schema::dropIfExists('workflow_stages');

        Schema::table('agent_executions', function (Blueprint $table): void {
            $table->dropForeign(['workflow_id']);
            $table->dropIndex(['workflow_id', 'status']);
            $table->dropColumn(['workflow_id', 'workflow_version']);
        });

        Schema::table('workflows', function (Blueprint $table): void {
            $table->dropColumn(['purpose', 'version', 'execution_policy', 'completion_criteria']);
        });
    }
};
