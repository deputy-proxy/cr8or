<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('purpose')->nullable();
            $table->string('project')->nullable();
            $table->string('output_format', 32);
            $table->json('renderer_targets')->nullable();
            $table->string('ownership_scope', 32)->default('platform');
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('enterprise_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32)->default('draft');
            $table->timestamps();
            $table->index(['ownership_scope', 'status']);
            $table->index(['output_format', 'status']);
        });

        Schema::create('template_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 32)->default('draft');
            $table->string('source_repository');
            $table->string('source_revision', 64);
            $table->string('definition_path');
            $table->char('definition_sha256', 64);
            $table->string('definition_artifact_path');
            $table->string('schema_version', 64);
            $table->json('catalog_metadata')->nullable();
            $table->json('input_contract')->nullable();
            $table->json('media_contract')->nullable();
            $table->json('rendering_contract')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->unique(['template_id', 'version']);
            $table->unique(['template_id', 'source_revision', 'definition_path', 'definition_sha256'], 'template_version_source_identity_unique');
            $table->index(['template_id', 'status']);
        });

        Schema::table('templates', function (Blueprint $table): void {
            $table->foreignId('published_version_id')->nullable()->after('status')->constrained('template_versions')->nullOnDelete();
        });

        Schema::table('generation_requests', function (Blueprint $table): void {
            $table->foreignId('template_version_id')->nullable()->after('asset_id')->constrained('template_versions')->restrictOnDelete();
        });

        Schema::table('render_requests', function (Blueprint $table): void {
            $table->foreignId('template_version_id')->nullable()->after('source_version_id')->constrained('template_versions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('render_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('template_version_id');
        });
        Schema::table('generation_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('template_version_id');
        });
        Schema::table('templates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('published_version_id');
        });
        Schema::dropIfExists('template_versions');
        Schema::dropIfExists('templates');
    }
};
