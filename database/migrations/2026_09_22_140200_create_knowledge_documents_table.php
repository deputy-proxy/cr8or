<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('knowledge_source_id')->nullable()->constrained('knowledge_sources')->nullOnDelete();
            $table->string('title');
            $table->string('identifier')->nullable();
            $table->string('status')->default('active');
            $table->longText('content')->nullable();
            $table->json('metadata')->nullable();
            $table->index(['enterprise_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_documents');
    }
};
