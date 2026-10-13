<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('transitions destination uniqueness without removing foreign-key support and can be retried', function (): void {
    $originalConnection = config('database.default');
    config(['database.connections.social_destination_uniqueness_migration' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);
    config(['database.default' => 'social_destination_uniqueness_migration']);
    DB::purge();

    try {
        Schema::create('social_identities', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('social_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('social_identity_id')->nullable()->constrained('social_identities')->nullOnDelete();
            $table->string('provider');
            $table->string('external_id');
            $table->unique(['social_identity_id', 'external_id'], 'social_accounts_identity_destination_unique');
        });

        $migration = require database_path('migrations/2026_10_12_120100_scope_destination_uniqueness_by_network.php');
        $migration->up();
        $migration->up();

        expect(Schema::hasIndex('social_accounts', 'social_accounts_identity_destination_unique'))->toBeFalse()
            ->and(Schema::hasIndex('social_accounts', 'social_accounts_identity_provider_destination_unique'))->toBeTrue()
            ->and(DB::select("PRAGMA foreign_key_list('social_accounts')"))->not->toBeEmpty();

        DB::table('social_identities')->insert(['id' => 1]);
        DB::table('social_accounts')->insert([
            ['social_identity_id' => 1, 'provider' => 'facebook', 'external_id' => 'same-destination'],
            ['social_identity_id' => 1, 'provider' => 'linkedin', 'external_id' => 'same-destination'],
        ]);

        expect(DB::table('social_accounts')->count())->toBe(2);
        expect(fn () => DB::table('social_accounts')->insert([
            'social_identity_id' => 999,
            'provider' => 'facebook',
            'external_id' => 'invalid-identity',
        ]))->toThrow(QueryException::class);

        DB::table('social_accounts')->where('provider', 'linkedin')->delete();
        $migration->down();
        expect(Schema::hasIndex('social_accounts', 'social_accounts_identity_destination_unique'))->toBeTrue()
            ->and(Schema::hasIndex('social_accounts', 'social_accounts_identity_provider_destination_unique'))->toBeFalse();
    } finally {
        config(['database.default' => $originalConnection]);
        DB::purge('social_destination_uniqueness_migration');
    }
});
