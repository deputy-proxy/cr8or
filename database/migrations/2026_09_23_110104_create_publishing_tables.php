<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $t->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $t->string('provider');
            $t->string('name');
            $t->string('external_id');
            $t->string('status')->default('active');
            $t->timestamps();
            $t->unique(['provider', 'external_id']);
            $t->index(['enterprise_id', 'status']);
        });
        Schema::create('publications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $t->foreignId('content_item_id')->constrained()->restrictOnDelete();
            $t->foreignId('channel_id')->constrained()->restrictOnDelete();
            $t->foreignId('social_account_id')->constrained()->restrictOnDelete();
            $t->foreignId('approval_request_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status')->default('scheduled');
            $t->string('idempotency_key')->unique();
            $t->string('correlation_id')->nullable()->index();
            $t->string('external_id')->nullable()->index();
            $t->string('external_url')->nullable();
            $t->timestamp('scheduled_at')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->string('failure_code')->nullable();
            $t->text('failure_reason')->nullable();
            $t->timestamps();
            $t->index(['enterprise_id', 'status']);
        });
        Schema::create('publication_schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $t->foreignId('publication_id')->unique()->constrained()->cascadeOnDelete();
            $t->timestamp('scheduled_at');
            $t->string('status')->default('scheduled');
            $t->timestamps();
            $t->index(['enterprise_id', 'scheduled_at', 'status']);
        });
        Schema::create('publishing_jobs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $t->foreignId('publication_id')->constrained()->cascadeOnDelete();
            $t->string('idempotency_key')->unique();
            $t->unsignedInteger('attempts')->default(0);
            $t->string('status')->default('pending');
            $t->string('failure_code')->nullable();
            $t->text('failure_reason')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('publication_results', function (Blueprint $t) {
            $t->id();
            $t->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $t->foreignId('publication_id')->constrained()->restrictOnDelete();
            $t->foreignId('publishing_job_id')->nullable()->constrained()->nullOnDelete();
            $t->string('provider');
            $t->string('provider_status');
            $t->string('external_id')->nullable();
            $t->string('external_url')->nullable();
            $t->string('correlation_id')->nullable()->index();
            $t->string('failure_code')->nullable();
            $t->text('failure_reason')->nullable();
            $t->json('payload')->nullable();
            $t->timestamp('recorded_at');
            $t->timestamps();
            $t->index(['publication_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publication_results');
        Schema::dropIfExists('publishing_jobs');
        Schema::dropIfExists('publication_schedules');
        Schema::dropIfExists('publications');
        Schema::dropIfExists('social_accounts');
    }
};