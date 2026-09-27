<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_embeddings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('knowledge_index_unit_id')->constrained('knowledge_index_units')->cascadeOnDelete();
            $table->foreignId('knowledge_index_record_id')->constrained('knowledge_index_records')->cascadeOnDelete();
            $table->foreignId('knowledge_version_id')->nullable()->constrained('knowledge_versions')->nullOnDelete();
            $table->string('embedding_version');
            $table->string('content_hash');
            $table->json('vector');
            $table->timestamps();

            $table->unique(['knowledge_index_unit_id', 'embedding_version']);
            $table->index(['enterprise_id', 'knowledge_version_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_embeddings');
    }
};