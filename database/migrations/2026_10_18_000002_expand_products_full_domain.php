<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('product_type', 32)->default('other');
            $table->string('tagline', 255)->nullable();
            $table->text('summary')->nullable();
            $table->longText('description')->nullable();
            $table->text('problem_statement')->nullable();
            $table->text('value_proposition')->nullable();
            $table->text('primary_outcome')->nullable();
            $table->text('positioning')->nullable();
            $table->json('differentiators')->nullable();
            $table->json('features')->nullable();
            $table->json('benefits')->nullable();
            $table->json('deliverables')->nullable();
            $table->json('requirements')->nullable();
            $table->json('limitations')->nullable();
            $table->string('delivery_model', 64)->default('other');
            $table->string('fulfillment_method', 255)->nullable();
            $table->text('support_model')->nullable();
            $table->string('monetization_model', 32)->default('other');
            $table->string('pricing_model', 32)->default('other');
            $table->decimal('price_amount', 12, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('billing_interval', 32)->nullable();
            $table->text('commercial_terms')->nullable();
            $table->text('commercial_notes')->nullable();
            $table->boolean('is_commercially_available')->default(false);
            $table->timestamp('availability_start_at')->nullable();
            $table->timestamp('availability_end_at')->nullable();
            $table->json('required_disclosures')->nullable();
            $table->json('restrictions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn([
                'product_type', 'tagline', 'summary', 'description', 'problem_statement', 'value_proposition',
                'primary_outcome', 'positioning', 'differentiators', 'features', 'benefits', 'deliverables',
                'requirements', 'limitations', 'delivery_model', 'fulfillment_method', 'support_model',
                'monetization_model', 'pricing_model', 'price_amount', 'currency', 'billing_interval',
                'commercial_terms', 'commercial_notes', 'is_commercially_available', 'availability_start_at',
                'availability_end_at', 'required_disclosures', 'restrictions',
            ]);
        });
    }
};
