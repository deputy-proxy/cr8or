<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generation_jobs', function (Blueprint $table): void {
            $table->dropForeign(['workflow_job_id']);
            $table->dropForeign(['execution_id']);
            $table->dropColumn(['workflow_job_id', 'execution_id']);
        });

        Schema::table('render_jobs', function (Blueprint $table): void {
            $table->dropForeign(['workflow_job_id']);
            $table->dropForeign(['execution_id']);
            $table->dropColumn(['workflow_job_id', 'execution_id']);
        });

        Schema::dropIfExists('executions');
        Schema::dropIfExists('workflow_jobs');
    }

    public function down(): void
    {
        Schema::create('workflow_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('idempotency_key')->unique();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('status')->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
            $table->index(['workflow_id', 'status']);
        });

        Schema::create('executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_job_id')->constrained('workflow_jobs')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('enterprise_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('work_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('organization_name')->nullable();
            $table->string('enterprise_name')->nullable();
            $table->string('project_name')->nullable();
            $table->string('task_name')->nullable();
            $table->string('work_item_name')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
            $table->index(['workflow_job_id', 'status']);
        });

        Schema::table('generation_jobs', function (Blueprint $table): void {
            $table->foreignId('workflow_job_id')->nullable()->constrained('workflow_jobs')->restrictOnDelete();
            $table->foreignId('execution_id')->nullable()->constrained('executions')->restrictOnDelete();
        });

        Schema::table('render_jobs', function (Blueprint $table): void {
            $table->foreignId('workflow_job_id')->nullable()->constrained('workflow_jobs')->restrictOnDelete();
            $table->foreignId('execution_id')->nullable()->constrained('executions')->restrictOnDelete();
        });
    }
};