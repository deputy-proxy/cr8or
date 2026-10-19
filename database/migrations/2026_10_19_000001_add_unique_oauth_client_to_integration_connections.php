<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('integration_connections', 'integration_connections_oauth_client_id_unique')) {
            Schema::table('integration_connections', function (Blueprint $table): void {
                $table->unique('oauth_client_id', 'integration_connections_oauth_client_id_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('integration_connections', 'integration_connections_oauth_client_id_unique')) {
            Schema::table('integration_connections', function (Blueprint $table): void {
                $table->dropUnique('integration_connections_oauth_client_id_unique');
            });
        }
    }
};