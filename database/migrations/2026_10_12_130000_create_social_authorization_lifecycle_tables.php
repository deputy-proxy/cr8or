<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 80)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 100);
            $table->text('encrypted_payload');
            $table->json('scopes')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->timestamp('refresh_token_expires_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'provider'], 'social_credentials_scope_index');
        });

        Schema::create('social_authorization_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enterprise_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('social_identity_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('integration_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 100);
            $table->string('destination_network', 100);
            $table->char('state_hash', 64)->unique();
            $table->text('code_verifier_encrypted');
            $table->string('redirect_uri', 2048);
            $table->json('requested_scopes');
            $table->string('status', 40)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('discovered_destinations')->nullable();
            $table->json('selected_destinations')->nullable();
            $table->timestamp('destinations_attached_at')->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->uuid('correlation_id')->unique();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'created_at'], 'social_authorization_attempt_scope_status_index');
            $table->index(['user_id', 'status', 'created_at'], 'social_authorization_attempt_actor_status_index');
        });

        Schema::create('social_authorization_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enterprise_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('integration_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('attempt_id', 64)->nullable();
            $table->string('provider', 100);
            $table->string('destination_network', 100)->nullable();
            $table->string('action', 100);
            $table->string('correlation_id', 64);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['organization_id', 'created_at'], 'social_authorization_audit_org_created_index');
            $table->index(['provider', 'action', 'created_at'], 'social_authorization_audit_provider_action_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_authorization_audits');
        Schema::dropIfExists('social_authorization_attempts');
        Schema::dropIfExists('social_credentials');
    }
};