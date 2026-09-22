<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('knowledge_source_id')->nullable()->constrained('knowledge_sources')->nullOnDelete();
            $table->foreignId('knowledge_document_id')->nullable()->constrained('knowledge_documents')->nullOnDelete();
            $table->foreignId('knowledge_context_id')->nullable()->constrained('knowledge_contexts')->nullOnDelete();
            $table->string('title');
            $table->string('type')->default('fact');
            $table->text('summary')->nullable();
            $table->index(['enterprise_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_items');
    }
};