<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('backfills immutable publication target snapshots for pre-existing publications', function (): void {
    $originalConnection = config('database.default');
    config(['database.connections.social_identity_migration' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);
    config(['database.default' => 'social_identity_migration']);
    DB::purge();

    try {
        Schema::create('organizations', fn (Blueprint $table) => $table->id());
        Schema::create('enterprises', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained();
        });
        Schema::create('channels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enterprise_id')->constrained();
        });
        Schema::create('content_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enterprise_id')->constrained();
        });
        Schema::create('social_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('name');
            $table->string('external_id');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['provider', 'external_id']);
        });
        Schema::create('integration_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enterprise_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider');
            $table->string('external_account_id')->nullable();
            $table->string('credential_reference');
            $table->json('configuration')->nullable();
            $table->string('status')->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'provider', 'external_account_id'], 'integration_connections_account_scope_unique');
        });
        Schema::create('publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('channel_id')->constrained()->restrictOnDelete();
            $table->foreignId('social_account_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('scheduled');
            $table->string('idempotency_key')->unique();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('organizations')->insert(['id' => 1]);
        DB::table('enterprises')->insert(['id' => 1, 'organization_id' => 1]);
        DB::table('channels')->insert(['id' => 1, 'enterprise_id' => 1]);
        DB::table('content_items')->insert(['id' => 1, 'enterprise_id' => 1]);
        DB::table('social_accounts')->insert([
            'id' => 1,
            'enterprise_id' => 1,
            'channel_id' => 1,
            'provider' => 'linkedin',
            'name' => 'Legacy Company Page',
            'external_id' => 'legacy-page-opaque-1',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('publications')->insert([
            'id' => 1,
            'enterprise_id' => 1,
            'content_item_id' => 1,
            'channel_id' => 1,
            'social_account_id' => 1,
            'status' => 'scheduled',
            'idempotency_key' => 'legacy-publication-snapshot-1',
            'scheduled_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $migration = require database_path('migrations/2026_10_12_110000_define_social_identity_and_publication_target_contract.php');
        $migration->up();

        $publication = DB::table('publications')->where('id', 1)->first();

        expect($publication->target_provider)->toBe('linkedin')
            ->and($publication->target_external_id)->toBe('legacy-page-opaque-1')
            ->and($publication->target_name)->toBe('Legacy Company Page')
            ->and($publication->target_identity_external_id)->toBeNull();

        DB::table('enterprises')->insert(['id' => 2, 'organization_id' => 1]);
        DB::table('channels')->insert(['id' => 2, 'enterprise_id' => 2]);
        DB::table('social_accounts')->insert([
            'id' => 2,
            'enterprise_id' => 2,
            'channel_id' => 2,
            'provider' => 'linkedin',
            'name' => 'Second Legacy Page',
            'external_id' => 'legacy-page-opaque-1',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        expect(fn () => $migration->down())->toThrow(RuntimeException::class);
        DB::table('social_accounts')->where('id', 2)->delete();
        $migration->down();

        expect(Schema::hasColumn('social_accounts', 'social_identity_id'))->toBeFalse()
            ->and(Schema::hasColumn('publications', 'target_provider'))->toBeFalse();
    } finally {
        config(['database.default' => $originalConnection]);
        DB::purge('social_identity_migration');
    }
});
