<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_results', function (Blueprint $t) {
            $t->id();
            $t->foreignId('integration_job_id')->constrained()->cascadeOnDelete();
            $t->foreignId('integration_connection_id')->constrained()->cascadeOnDelete();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $t->string('provider');
            $t->string('operation');
            $t->string('external_job_id');
            $t->string('external_result_id')->nullable();
            $t->string('status');
            $t->string('source');
            $t->string('dedupe_key', 64)->unique();
            $t->string('correlation_id', 128)->nullable()->index();
            $t->json('payload')->nullable();
            $t->string('failure_code')->nullable();
            $t->text('failure_reason')->nullable();
            $t->timestamp('occurred_at');
            $t->timestamp('received_at');
            $t->timestamp('processed_at')->nullable();
            $t->string('processing_status');
            $t->timestamps();
            $t->index(['provider', 'external_job_id']);
            $t->index(['enterprise_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_results');
    }
};