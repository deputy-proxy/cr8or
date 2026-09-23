<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $t->foreignId('content_item_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name');
            $t->string('type');
            $t->string('status')->default('active');
            $t->timestamps();
            $t->index(['enterprise_id', 'status']);
        });
        Schema::create('asset_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('version');
            $t->string('disk')->nullable();
            $t->text('path')->nullable();
            $t->string('mime_type')->nullable();
            $t->unsignedBigInteger('size')->nullable();
            $t->string('checksum', 128)->nullable();
            $t->json('metadata')->nullable();
            $t->string('external_reference')->nullable();
            $t->timestamps();
            $t->unique(['asset_id', 'version']);
        });
        Schema::create('generation_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $t->foreignId('content_item_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type');
            $t->json('parameters')->nullable();
            $t->string('status')->default('pending');
            $t->string('idempotency_key')->unique();
            $t->string('correlation_id', 128)->nullable()->index();
            $t->string('external_request_id')->nullable();
            $t->string('failure_code')->nullable();
            $t->text('failure_reason')->nullable();
            $t->timestamps();
            $t->index(['enterprise_id', 'status']);
        });
        Schema::create('generation_jobs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('generation_request_id')->constrained()->cascadeOnDelete();
            $t->foreignId('workflow_job_id')->constrained('workflow_jobs')->restrictOnDelete();
            $t->foreignId('execution_id')->constrained('executions')->restrictOnDelete();
            $t->string('external_job_id')->nullable();
            $t->string('status')->default('pending');
            $t->text('failure_reason')->nullable();
            $t->timestamps();
        });
        Schema::create('transformations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $t->foreignId('source_version_id')->constrained('asset_versions')->restrictOnDelete();
            $t->foreignId('output_version_id')->nullable()->constrained('asset_versions')->nullOnDelete();
            $t->string('type');
            $t->json('parameters')->nullable();
            $t->timestamps();
        });
        Schema::create('render_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $t->foreignId('content_item_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('source_version_id')->nullable()->constrained('asset_versions')->nullOnDelete();
            $t->string('type');
            $t->json('parameters')->nullable();
            $t->string('status')->default('pending');
            $t->string('idempotency_key')->unique();
            $t->string('correlation_id', 128)->nullable()->index();
            $t->string('external_request_id')->nullable();
            $t->string('failure_code')->nullable();
            $t->text('failure_reason')->nullable();
            $t->timestamps();
            $t->index(['enterprise_id', 'status']);
        });
        Schema::create('render_jobs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('render_request_id')->constrained()->cascadeOnDelete();
            $t->foreignId('workflow_job_id')->constrained('workflow_jobs')->restrictOnDelete();
            $t->foreignId('execution_id')->constrained('executions')->restrictOnDelete();
            $t->string('external_job_id')->nullable();
            $t->string('status')->default('pending');
            $t->text('failure_reason')->nullable();
            $t->timestamps();
        });
        Schema::create('render_outputs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('render_request_id')->constrained()->cascadeOnDelete();
            $t->foreignId('asset_version_id')->constrained('asset_versions')->restrictOnDelete();
            $t->string('external_output_id')->nullable();
            $t->string('disk')->nullable();
            $t->text('path')->nullable();
            $t->string('mime_type')->nullable();
            $t->unsignedBigInteger('size')->nullable();
            $t->string('checksum', 128)->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
        Schema::create('media_metadata', function (Blueprint $t) {
            $t->id();
            $t->foreignId('asset_version_id')->unique()->constrained('asset_versions')->cascadeOnDelete();
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();
            $t->decimal('duration_seconds', 12, 3)->nullable();
            $t->string('codec')->nullable();
            $t->decimal('frame_rate', 12, 3)->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['media_metadata', 'render_outputs', 'render_jobs', 'render_requests', 'transformations', 'generation_jobs', 'generation_requests', 'asset_versions', 'assets'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};