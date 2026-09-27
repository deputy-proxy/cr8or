<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_index_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('knowledge_source_id')->nullable()->constrained('knowledge_sources')->nullOnDelete();
            $table->foreignId('knowledge_document_id')->nullable()->constrained('knowledge_documents')->nullOnDelete();
            $table->foreignId('knowledge_item_id')->constrained('knowledge_items')->cascadeOnDelete();
            $table->foreignId('knowledge_version_id')->nullable()->constrained('knowledge_versions')->nullOnDelete();
            $table->string('unit_key')->default('root');
            $table->string('representation_key')->unique();
            $table->string('status')->default('pending');
            $table->string('content_hash')->nullable();
            $table->string('provider')->nullable();
            $table->string('provider_reference')->nullable();
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['enterprise_id', 'status']);
            $table->index(['knowledge_item_id', 'status']);
            $table->index(['knowledge_version_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_index_records');
    }
};