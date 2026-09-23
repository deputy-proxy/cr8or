<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('work_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('status')->default('pending');
            $table->timestamps();
            $table->index(['enterprise_id', 'status']);
        });

        Schema::create('workflow_jobs', function (Blueprint $table) {
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

        Schema::create('executions', function (Blueprint $table) {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('executions');
        Schema::dropIfExists('workflow_jobs');
        Schema::dropIfExists('workflows');
    }
};
