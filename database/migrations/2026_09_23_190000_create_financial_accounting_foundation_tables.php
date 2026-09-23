<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 32);
            $table->string('status', 32)->default('active');
            $table->char('currency', 3);
            $table->timestamps();

            $table->unique(['enterprise_id', 'name']);
            $table->index(['enterprise_id', 'status']);
        });

        Schema::create('transaction_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->unique(['enterprise_id', 'name']);
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('transaction_category_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 20, 4);
            $table->date('transaction_date');
            $table->text('description')->nullable();
            $table->string('reference')->nullable();
            $table->timestamps();

            $table->index(['enterprise_id', 'transaction_date']);
            $table->index(['financial_account_id', 'transaction_date']);
            $table->index(['transaction_category_id', 'transaction_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('transaction_categories');
        Schema::dropIfExists('financial_accounts');
    }
};
