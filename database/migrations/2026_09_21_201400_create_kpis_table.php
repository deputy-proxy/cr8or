<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('definition');
            $table->string('unit')->nullable();
            $table->decimal('target_value', 20, 4)->nullable();
            $table->decimal('current_value', 20, 4)->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['enterprise_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpis');
    }
};