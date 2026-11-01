<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_connections', function (Blueprint $table): void {
            $table->unsignedBigInteger('github_installation_id')->nullable()->unique();
        });

        Schema::create('integration_repositories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enterprise_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('github_repository_id');
            $table->unsignedBigInteger('github_account_id')->nullable();
            $table->string('owner_login', 255);
            $table->string('name', 255);
            $table->string('full_name', 512);
            $table->string('url', 2048);
            $table->string('default_branch', 255)->nullable();
            $table->boolean('is_private')->default(false);
            $table->string('status', 32)->default('active');
            $table->string('sync_status', 32)->default('pending');
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_sync_error', 500)->nullable();
            $table->timestamp('github_updated_at')->nullable();
            $table->timestamps();
            $table->unique('github_repository_id', 'integration_repositories_github_repository_unique');
            $table->index(['organization_id', 'enterprise_id', 'status']);
            $table->index(['integration_connection_id', 'status', 'sync_status']);
        });

        Schema::create('github_webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('delivery_id', 128)->unique();
            $table->string('event_type', 100);
            $table->string('action', 100)->nullable();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('enterprise_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('installation_id')->nullable();
            $table->unsignedBigInteger('github_repository_id')->nullable();
            $table->foreignId('integration_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payload_hash', 64);
            $table->json('payload')->nullable();
            $table->string('status', 32)->default('received');
            $table->string('failure_reason', 500)->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['installation_id', 'event_type', 'received_at']);
            $table->index(['status', 'received_at']);
        });

        Schema::table('issues', function (Blueprint $table): void {
            $table->foreignId('integration_repository_id')->nullable()->after('enterprise_id')->constrained('integration_repositories')->nullOnDelete();
            $table->unsignedBigInteger('github_repository_id')->nullable()->after('integration_repository_id');
            $table->json('assignees')->nullable()->after('labels');
            $table->timestamp('github_deleted_at')->nullable()->after('github_closed_at');
            $table->unique(['integration_repository_id', 'external_id'], 'issues_integration_repository_external_unique');
            $table->index(['integration_repository_id', 'github_updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table): void {
            $table->dropUnique('issues_integration_repository_external_unique');
            $table->dropIndex(['integration_repository_id', 'github_updated_at']);
            $table->dropConstrainedForeignId('integration_repository_id');
            $table->dropColumn(['github_repository_id', 'assignees', 'github_deleted_at']);
        });
        Schema::dropIfExists('github_webhook_deliveries');
        Schema::dropIfExists('integration_repositories');
        Schema::table('integration_connections', function (Blueprint $table): void {
            $table->dropUnique(['github_installation_id']);
            $table->dropColumn('github_installation_id');
        });
    }
};
