<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'sort_order']);
        });

        Schema::create('enterprise_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'sort_order']);
        });

        Schema::table('enterprises', function (Blueprint $table): void {
            $table->foreignId('enterprise_group_id')->nullable()->after('organization_id')->constrained('enterprise_groups')->nullOnDelete();
            $table->foreignId('enterprise_category_id')->nullable()->after('enterprise_group_id')->constrained('enterprise_categories')->nullOnDelete();
            $table->json('connections')->nullable()->after('status');
            $table->string('github_repository')->nullable()->after('connections');
            $table->string('github_repository_url')->nullable()->after('github_repository');
            $table->string('github_issues_sync_status', 32)->nullable()->after('github_repository_url');
            $table->timestamp('github_issues_synced_at')->nullable()->after('github_issues_sync_status');
            $table->string('github_issues_sync_error', 500)->nullable()->after('github_issues_synced_at');
            $table->string('website_domain')->nullable()->after('github_issues_sync_error')->unique();
            $table->index(['organization_id', 'enterprise_group_id']);
            $table->index(['organization_id', 'enterprise_category_id']);
        });

        Schema::create('issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->string('repository', 255);
            $table->string('external_id', 64);
            $table->unsignedBigInteger('number');
            $table->string('title', 500);
            $table->string('state', 32);
            $table->json('labels')->nullable();
            $table->string('author_login', 255)->nullable();
            $table->timestamp('github_created_at')->nullable();
            $table->timestamp('github_updated_at')->nullable();
            $table->timestamp('github_closed_at')->nullable();
            $table->string('url', 2048);
            $table->timestamp('last_synced_at');
            $table->timestamps();
            $table->unique(['enterprise_id', 'repository', 'external_id']);
            $table->index(['enterprise_id', 'repository', 'state', 'github_updated_at']);
        });

        Schema::create('events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->string('source', 32);
            $table->string('event_type', 100);
            $table->string('description', 500);
            $table->timestamp('occurred_at');
            $table->json('payload')->nullable();
            $table->string('source_event_id', 100);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject_type', 100)->nullable();
            $table->string('subject_id', 100)->nullable();
            $table->string('correlation_id', 100)->nullable();
            $table->string('causation_id', 100)->nullable();
            $table->timestamp('received_at');
            $table->timestamps();
            $table->unique(['organization_id', 'source', 'source_event_id'], 'events_source_identity_unique');
            $table->index(['organization_id', 'enterprise_id', 'occurred_at']);
            $table->index(['enterprise_id', 'source', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
        Schema::dropIfExists('issues');
        Schema::table('enterprises', function (Blueprint $table): void {
            $table->dropUnique(['website_domain']);
            $table->dropIndex(['organization_id', 'enterprise_group_id']);
            $table->dropIndex(['organization_id', 'enterprise_category_id']);
            $table->dropConstrainedForeignId('enterprise_group_id');
            $table->dropConstrainedForeignId('enterprise_category_id');
            $table->dropColumn(['connections', 'github_repository', 'github_repository_url', 'github_issues_sync_status', 'github_issues_synced_at', 'github_issues_sync_error', 'website_domain']);
        });
        Schema::dropIfExists('enterprise_categories');
        Schema::dropIfExists('enterprise_groups');
    }
};
