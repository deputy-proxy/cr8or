<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained()->nullOnDelete();
            $table->string('invoice_number');
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->decimal('total', 20, 4);
            $table->char('currency', 3);
            $table->string('status', 32)->default('draft');
            $table->string('counterparty_name_snapshot')->nullable();
            $table->string('counterparty_email_snapshot')->nullable();
            $table->timestamps();
            $table->unique(['enterprise_id', 'invoice_number']);
            $table->index(['enterprise_id', 'issue_date']);
            $table->index(['enterprise_id', 'status']);
            $table->index(['customer_id']);
            $table->index(['partner_id']);
        });

        Schema::create('revenues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 20, 4);
            $table->char('currency', 3);
            $table->date('revenue_date');
            $table->string('source', 64)->nullable();
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->index(['enterprise_id', 'revenue_date']);
            $table->index(['financial_account_id', 'revenue_date']);
            $table->index(['transaction_id']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('transaction_category_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 20, 4);
            $table->char('currency', 3);
            $table->date('expense_date');
            $table->string('source', 64)->nullable();
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->index(['enterprise_id', 'expense_date']);
            $table->index(['financial_account_id', 'expense_date']);
            $table->index(['transaction_id']);
            $table->index(['transaction_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('revenues');
        Schema::dropIfExists('invoices');
    }
};
