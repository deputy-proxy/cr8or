<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SQLite table rebuilds must run outside a transaction to temporarily disable
     * FK enforcement while preserving inbound references to social_accounts.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        $this->withSqliteForeignKeysDisabled(function (): void {
            Schema::create('social_identities', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->string('provider', 100);
                $table->string('external_id');
                $table->string('name')->nullable();
                $table->timestamps();
                $table->unique(['organization_id', 'provider', 'external_id'], 'social_identities_org_provider_external_unique');
            });

            Schema::table('social_accounts', function (Blueprint $table): void {
                $table->foreignId('social_identity_id')->nullable()->after('channel_id')->constrained('social_identities')->nullOnDelete();
                $table->dropUnique('social_accounts_provider_external_id_unique');
                $table->unique(['enterprise_id', 'provider', 'external_id'], 'social_accounts_enterprise_provider_external_unique');
                $table->unique(['social_identity_id', 'external_id'], 'social_accounts_identity_destination_unique');
            });

            Schema::table('integration_connections', function (Blueprint $table): void {
                $table->foreignId('social_identity_id')->nullable()->after('enterprise_id')->unique()->constrained('social_identities')->nullOnDelete();
            });

            Schema::table('publications', function (Blueprint $table): void {
                $table->string('target_provider')->nullable()->after('social_account_id');
                $table->string('target_external_id')->nullable()->after('target_provider');
                $table->string('target_name')->nullable()->after('target_external_id');
                $table->string('target_identity_external_id')->nullable()->after('target_name');
            });

            DB::table('publications')->orderBy('id')->chunkById(100, function ($publications): void {
                $accounts = DB::table('social_accounts')
                    ->whereIn('id', $publications->pluck('social_account_id')->unique()->all())
                    ->get()
                    ->keyBy('id');
                $identityIds = $accounts->pluck('social_identity_id')->filter()->unique()->all();
                $identities = $identityIds === []
                    ? collect()
                    : DB::table('social_identities')->whereIn('id', $identityIds)->get()->keyBy('id');

                foreach ($publications as $publication) {
                    $account = $accounts->get($publication->social_account_id);
                    if ($account === null) {
                        continue;
                    }
                    $identity = $account->social_identity_id === null ? null : $identities->get($account->social_identity_id);

                    DB::table('publications')->where('id', $publication->id)->update([
                        'target_provider' => $account->provider,
                        'target_external_id' => $account->external_id,
                        'target_name' => $account->name,
                        'target_identity_external_id' => $identity?->external_id,
                    ]);
                }
            });
        });
    }

    public function down(): void
    {
        $collisions = DB::table('social_accounts')
            ->select('provider', 'external_id')
            ->groupBy('provider', 'external_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($collisions) {
            throw new RuntimeException('Cannot roll back social identity scope: duplicate provider/external ID pairs now exist across enterprises. Resolve collisions before rollback.');
        }

        $this->withSqliteForeignKeysDisabled(function (): void {
            Schema::table('publications', function (Blueprint $table): void {
                $table->dropColumn(['target_provider', 'target_external_id', 'target_name', 'target_identity_external_id']);
            });

            Schema::table('integration_connections', function (Blueprint $table): void {
                $table->dropUnique(['social_identity_id']);
                $table->dropConstrainedForeignId('social_identity_id');
            });

            Schema::table('social_accounts', function (Blueprint $table): void {
                $table->dropUnique('social_accounts_identity_destination_unique');
                $table->dropUnique('social_accounts_enterprise_provider_external_unique');
                $table->dropConstrainedForeignId('social_identity_id');
                $table->unique(['provider', 'external_id']);
            });

            Schema::dropIfExists('social_identities');
        });
    }

    private function withSqliteForeignKeysDisabled(callable $callback): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $callback();

            return;
        }

        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement('PRAGMA legacy_alter_table = ON');

        try {
            $callback();
        } finally {
            DB::statement('PRAGMA legacy_alter_table = OFF');
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }
};
