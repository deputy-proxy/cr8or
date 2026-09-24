<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('transaction_category_id')->nullable()->constrained()->restrictOnDelete();
            $table->char('currency', 3);
            $table->json('metrics');
            $table->json('source_snapshot');
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index(['enterprise_id', 'financial_period_id', 'generated_at'], 'financial_reports_period_generated_index');
            $table->index(['financial_account_id', 'financial_period_id']);
            $table->index(['transaction_category_id', 'financial_period_id'], 'financial_reports_category_period_index');
        });

        Schema::create('business_health_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_report_id')->constrained()->restrictOnDelete();
            $table->string('health_status', 32);
            $table->json('metrics');
            $table->json('source_snapshot');
            $table->timestamp('evaluated_at');
            $table->timestamps();

            $table->index(['enterprise_id', 'evaluated_at']);
            $table->index(['financial_report_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_health_results');
        Schema::dropIfExists('financial_reports');
    }
};
