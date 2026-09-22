<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('strategy_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('initiative_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('planned');
            $table->timestamps();
            $table->index(['enterprise_id', 'status']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('todo');
            $table->string('priority')->default('normal');
            $table->timestamp('due_at')->nullable();
            $table->timestamps();
            $table->index(['enterprise_id', 'status']);
        });

        Schema::create('work_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('todo');
            $table->timestamps();
            $table->index(['enterprise_id', 'status']);
        });

        Schema::create('milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->string('status')->default('planned');
            $table->timestamps();
            $table->index(['enterprise_id', 'project_id']);
        });

        Schema::create('dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('predecessor_type');
            $table->unsignedBigInteger('predecessor_id');
            $table->string('successor_type');
            $table->unsignedBigInteger('successor_id');
            $table->string('type')->default('blocks');
            $table->timestamps();
            $table->index(['predecessor_type', 'predecessor_id']);
            $table->index(['successor_type', 'successor_id']);
            $table->index(['enterprise_id', 'project_id']);
        });

        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->string('assignable_type');
            $table->unsignedBigInteger('assignable_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['assignable_type', 'assignable_id']);
            $table->index('enterprise_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignments');
        Schema::dropIfExists('dependencies');
        Schema::dropIfExists('milestones');
        Schema::dropIfExists('work_items');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('projects');
    }
};
