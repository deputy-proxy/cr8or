<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status')->default('draft');
            $table->string('name');
            $table->text('purpose')->nullable();
            $table->json('execution_policy')->nullable();
            $table->json('completion_criteria')->nullable();
            $table->json('stage_definitions');
            $table->string('idempotency_key')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->unique(['workflow_id', 'version']);
            $table->unique(['workflow_id', 'idempotency_key']);
            $table->index(['enterprise_id', 'status']);
        });

        Schema::table('workflows', function (Blueprint $table): void {
            $table->foreignId('published_version_id')->nullable()->after('version')->constrained('workflow_versions')->nullOnDelete();
        });

        Schema::table('workflow_executions', function (Blueprint $table): void {
            $table->foreignId('workflow_version_id')->nullable()->after('workflow_id')->constrained('workflow_versions')->restrictOnDelete();
            $table->string('current_stage_key')->nullable()->after('current_stage_id');
        });

        Schema::table('workflow_executions', function (Blueprint $table): void {
            $table->dropUnique(['workflow_id', 'idempotency_key']);
            $table->unique(['workflow_version_id', 'idempotency_key']);
        });

        foreach (DB::table('workflows')->orderBy('id')->get() as $workflow) {
            $stageDefinitions = DB::table('workflow_stages')
                ->where('workflow_id', $workflow->id)
                ->orderBy('sequence')
                ->get()
                ->map(fn ($stage): array => [
                    'key' => $stage->key,
                    'name' => $stage->name,
                    'sequence' => $stage->sequence,
                    'dependencies' => json_decode($stage->dependencies ?: '[]', true) ?: [],
                    'expert_slugs' => json_decode($stage->expert_slugs ?: '[]', true) ?: [],
                    'capability_slugs' => json_decode($stage->capability_slugs ?: '[]', true) ?: [],
                    'input_contract' => json_decode($stage->input_contract ?: '{}', true) ?: [],
                    'output_contract' => json_decode($stage->output_contract ?: '{}', true) ?: [],
                    'repeatable' => (bool) $stage->repeatable,
                    'completion_criteria' => json_decode($stage->completion_criteria ?: '{}', true) ?: [],
                ])->values()->all();

            if ($stageDefinitions === []) {
                continue;
            }

            $versionId = DB::table('workflow_versions')->insertGetId([
                'workflow_id' => $workflow->id,
                'enterprise_id' => $workflow->enterprise_id,
                'version' => max(1, (int) $workflow->version),
                'status' => 'published',
                'name' => $workflow->name,
                'purpose' => $workflow->purpose ?? null,
                'execution_policy' => $workflow->execution_policy ?? null,
                'completion_criteria' => $workflow->completion_criteria ?? null,
                'stage_definitions' => json_encode($stageDefinitions, JSON_THROW_ON_ERROR),
                'idempotency_key' => 'migration:'.$workflow->id.':'.$workflow->version,
                'created_by' => DB::table('users')->min('id'),
                'published_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('workflows')->where('id', $workflow->id)->update(['published_version_id' => $versionId]);
            DB::table('workflow_executions')->where('workflow_id', $workflow->id)->update(['workflow_version_id' => $versionId, 'workflow_version' => max(1, (int) $workflow->version)]);
        }
    }

    public function down(): void
    {
        Schema::table('workflow_executions', function (Blueprint $table): void {
            $table->dropForeign(['workflow_version_id']);
            $table->dropColumn(['workflow_version_id', 'current_stage_key']);
        });

        Schema::table('workflows', function (Blueprint $table): void {
            $table->dropForeign(['published_version_id']);
            $table->dropColumn('published_version_id');
        });

        Schema::dropIfExists('workflow_versions');
    }
};