<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('statement');
            $table->string('status');
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->foreignId('supersedes_id')->nullable()->constrained('visions')->nullOnDelete();
            $table->boolean('is_current')->default(true);
            $table->timestamps();

            $table->unique(['enterprise_id', 'version']);
            $table->index(['enterprise_id', 'is_current']);
        });

        Schema::create('missions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('statement');
            $table->string('status');
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->foreignId('supersedes_id')->nullable()->constrained('missions')->nullOnDelete();
            $table->boolean('is_current')->default(true);
            $table->timestamps();

            $table->unique(['enterprise_id', 'version']);
            $table->index(['enterprise_id', 'is_current']);
        });

        Schema::create('competitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('name');
            $table->string('website')->nullable();
            $table->text('positioning')->nullable();
            $table->json('strengths')->nullable();
            $table->json('weaknesses')->nullable();
            $table->string('status');
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->foreignId('supersedes_id')->nullable()->constrained('competitors')->nullOnDelete();
            $table->boolean('is_current')->default(true);
            $table->timestamps();

            $table->unique(['enterprise_id', 'name', 'version']);
            $table->index(['enterprise_id', 'name', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitors');
        Schema::dropIfExists('missions');
        Schema::dropIfExists('visions');
    }
};