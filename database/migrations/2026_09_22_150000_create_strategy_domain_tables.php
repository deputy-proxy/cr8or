<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('objectives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('kpi_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('enterprise_id');
            $table->index('goal_id');
            $table->index('kpi_id');
        });

        Schema::create('strategies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('objective_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('objective_id');
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('strategy_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('strategy_id');
        });

        Schema::create('initiatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('plan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('initiatives');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('strategies');
        Schema::dropIfExists('objectives');
    }
};