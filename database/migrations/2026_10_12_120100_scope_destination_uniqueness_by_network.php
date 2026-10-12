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
            Schema::table('social_accounts', function (Blueprint $table): void {
                $table->dropUnique('social_accounts_identity_destination_unique');
                $table->unique(['social_identity_id', 'provider', 'external_id'], 'social_accounts_identity_provider_destination_unique');
            });
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
            Schema::table('social_accounts', function (Blueprint $table): void {
                $table->dropUnique('social_accounts_identity_provider_destination_unique');
                $table->unique(['social_identity_id', 'external_id'], 'social_accounts_identity_destination_unique');
            });
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