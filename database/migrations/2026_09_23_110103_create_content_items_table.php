<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_series_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('audience_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->longText('body')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->index(['enterprise_id', 'status']);
            $table->index(['campaign_id', 'content_series_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_items');
    }
};
