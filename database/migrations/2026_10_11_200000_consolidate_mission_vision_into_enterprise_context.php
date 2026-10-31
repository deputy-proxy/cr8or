<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_contexts', function (Blueprint $table): void {
            $table->text('mission')->nullable();
            $table->text('vision')->nullable();
            $table->json('values')->default(DB::raw('(JSON_ARRAY())'));
        });

        Schema::dropIfExists('missions');
        Schema::dropIfExists('visions');
    }

    public function down(): void
    {
        Schema::create('visions', function (Blueprint $table): void {
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

        Schema::create('missions', function (Blueprint $table): void {
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

        Schema::table('enterprise_contexts', function (Blueprint $table): void {
            $table->dropColumn(['mission', 'vision', 'values']);
        });
    }
};
