<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_definitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit')->nullable();
            $table->text('methodology');
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['enterprise_id', 'key']);
            $table->index(['enterprise_id', 'status']);
        });

        Schema::create('reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->string('report_type');
            $table->string('status');
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->timestamp('generated_at');
            $table->string('methodology_version');
            $table->timestamps();

            $table->index(['enterprise_id', 'report_type', 'period_start', 'period_end']);
        });

        Schema::create('report_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('captured_at');
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->string('methodology_version');
            $table->json('source_records');
            $table->string('source_fingerprint', 64);
            $table->timestamps();
        });

        Schema::create('report_metric_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('metric_definition_id')->constrained()->restrictOnDelete();
            $table->decimal('value', 20, 4);
            $table->string('unit')->nullable();
            $table->text('calculation');
            $table->json('source_records');
            $table->timestamps();

            $table->unique(['report_id', 'metric_definition_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_metric_values');
        Schema::dropIfExists('report_snapshots');
        Schema::dropIfExists('reports');
        Schema::dropIfExists('metric_definitions');
    }
};