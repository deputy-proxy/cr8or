<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['enterprise_id', 'name']);
            $table->index(['enterprise_id', 'period_start', 'period_end']);
        });

        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('transaction_category_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->decimal('planned_amount', 20, 4);
            $table->char('currency', 3);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['enterprise_id', 'financial_period_id']);
            $table->index(['financial_account_id', 'financial_period_id']);
            $table->index(['transaction_category_id', 'financial_period_id']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('financial_period_id')
                ->nullable()
                ->after('transaction_category_id')
                ->constrained('financial_periods')
                ->restrictOnDelete();

            $table->index(['enterprise_id', 'financial_period_id', 'transaction_date']);
        });

        Schema::table('revenues', function (Blueprint $table) {
            $table->foreignId('financial_period_id')
                ->nullable()
                ->after('transaction_id')
                ->constrained('financial_periods')
                ->restrictOnDelete();

            $table->index(['enterprise_id', 'financial_period_id', 'revenue_date']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('financial_period_id')
                ->nullable()
                ->after('transaction_category_id')
                ->constrained('financial_periods')
                ->restrictOnDelete();

            $table->index(['enterprise_id', 'financial_period_id', 'expense_date']);
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['financial_period_id']);
            $table->dropIndex(['enterprise_id', 'financial_period_id', 'expense_date']);
            $table->dropColumn('financial_period_id');
        });

        Schema::table('revenues', function (Blueprint $table) {
            $table->dropForeign(['financial_period_id']);
            $table->dropIndex(['enterprise_id', 'financial_period_id', 'revenue_date']);
            $table->dropColumn('financial_period_id');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['financial_period_id']);
            $table->dropIndex(['enterprise_id', 'financial_period_id', 'transaction_date']);
            $table->dropColumn('financial_period_id');
        });

        Schema::dropIfExists('budgets');
        Schema::dropIfExists('financial_periods');
    }
};
