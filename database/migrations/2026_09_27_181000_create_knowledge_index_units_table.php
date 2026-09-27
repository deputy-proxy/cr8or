<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_index_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('knowledge_index_record_id')->constrained('knowledge_index_records')->cascadeOnDelete();
            $table->foreignId('knowledge_item_id')->constrained('knowledge_items')->cascadeOnDelete();
            $table->foreignId('knowledge_version_id')->nullable()->constrained('knowledge_versions')->nullOnDelete();
            $table->string('unit_key');
            $table->unsignedInteger('ordinal');
            $table->longText('content');
            $table->string('content_hash');
            $table->json('heading_path')->nullable();
            $table->json('references')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['knowledge_index_record_id', 'unit_key']);
            $table->index(['enterprise_id', 'knowledge_item_id']);
            $table->index(['knowledge_version_id', 'ordinal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_index_units');
    }
};