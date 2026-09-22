<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('knowledge_item_id')->constrained('knowledge_items')->cascadeOnDelete();
            $table->foreignId('knowledge_document_id')->nullable()->constrained('knowledge_documents')->nullOnDelete();
            $table->string('type')->default('source');
            $table->string('label')->nullable();
            $table->string('locator')->nullable();
            $table->json('metadata')->nullable();
            $table->index(['enterprise_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_references');
    }
};
