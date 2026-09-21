<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('description')->nullable();
            $table->string('industry')->nullable();
            $table->string('business_model')->nullable();
            $table->string('target_market')->nullable();
            $table->string('geography')->nullable();
            $table->json('additional_context')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_contexts');
    }
};