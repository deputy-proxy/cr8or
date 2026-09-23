<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_connections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('enterprise_id')->nullable()->constrained()->nullOnDelete();
            $t->string('provider');
            $t->string('external_account_id')->nullable();
            $t->string('credential_reference');
            $t->string('status')->default('active');
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'provider', 'external_account_id']);
            $t->index(['organization_id', 'provider', 'status']);
        });

        Schema::create('external_resources', function (Blueprint $t) {
            $t->id();
            $t->foreignId('integration_connection_id')->constrained()->cascadeOnDelete();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $t->foreignId('content_item_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('agent_execution_id')->nullable()->constrained()->nullOnDelete();
            $t->string('provider');
            $t->string('resource_type');
            $t->string('external_id');
            $t->text('external_url')->nullable();
            $t->string('correlation_id', 128)->nullable()->index();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->unique(['provider', 'resource_type', 'external_id']);
            $t->index(['enterprise_id', 'provider', 'resource_type']);
        });

        Schema::create('integration_jobs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('integration_connection_id')->constrained()->cascadeOnDelete();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $t->foreignId('content_item_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('agent_execution_id')->nullable()->constrained()->nullOnDelete();
            $t->string('provider');
            $t->string('operation');
            $t->string('idempotency_key');
            $t->string('status')->default('pending');
            $t->string('external_job_id')->nullable();
            $t->string('failure_code')->nullable();
            $t->text('failure_reason')->nullable();
            $t->string('correlation_id', 128)->nullable()->index();
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamps();
            $t->unique(['provider', 'operation', 'idempotency_key']);
            $t->index(['enterprise_id', 'provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_jobs');
        Schema::dropIfExists('external_resources');
        Schema::dropIfExists('integration_connections');
    }
};
