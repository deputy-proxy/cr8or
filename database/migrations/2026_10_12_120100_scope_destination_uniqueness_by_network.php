<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $this->withSqliteForeignKeysDisabled(function (): void {
            // Create the replacement first: the old index also supports the
            // social_identity_id foreign key on MySQL/InnoDB.
            if (! Schema::hasIndex('social_accounts', 'social_accounts_identity_provider_destination_unique')) {
                Schema::table('social_accounts', function (Blueprint $table): void {
                    $table->unique(['social_identity_id', 'provider', 'external_id'], 'social_accounts_identity_provider_destination_unique');
                });
            }

            if (Schema::hasIndex('social_accounts', 'social_accounts_identity_destination_unique')) {
                Schema::table('social_accounts', function (Blueprint $table): void {
                    $table->dropUnique('social_accounts_identity_destination_unique');
                });
            }
        });
    }

    public function down(): void
    {
        $collisions = DB::table('social_accounts')
            ->whereNotNull('social_identity_id')
            ->select('social_identity_id', 'external_id')
            ->groupBy('social_identity_id', 'external_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($collisions) {
            throw new RuntimeException('Cannot roll back network-scoped destination uniqueness while one identity has matching external IDs on multiple networks.');
        }

        $this->withSqliteForeignKeysDisabled(function (): void {
            // The rollback follows the same safe ordering: keep a suitable
            // foreign-key index present before removing the current one.
            if (! Schema::hasIndex('social_accounts', 'social_accounts_identity_destination_unique')) {
                Schema::table('social_accounts', function (Blueprint $table): void {
                    $table->unique(['social_identity_id', 'external_id'], 'social_accounts_identity_destination_unique');
                });
            }

            if (Schema::hasIndex('social_accounts', 'social_accounts_identity_provider_destination_unique')) {
                Schema::table('social_accounts', function (Blueprint $table): void {
                    $table->dropUnique('social_accounts_identity_provider_destination_unique');
                });
            }
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
