<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('document');
            $table->text('description')->nullable();
            $table->string('uri')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['enterprise_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_sources');
    }
};
