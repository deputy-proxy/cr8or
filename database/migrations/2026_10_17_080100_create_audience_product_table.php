<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audience_product', function (Blueprint $table) {
            $table->foreignId('audience_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['audience_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audience_product');
    }
};