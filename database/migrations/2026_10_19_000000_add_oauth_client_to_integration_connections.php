<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_connections', function (Blueprint $table): void {
            $table->foreignUuid('oauth_client_id')
                ->nullable()
                ->after('enterprise_id')
                ->constrained('oauth_clients')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('integration_connections', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('oauth_client_id');
        });
    }
};