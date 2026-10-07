<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_connections', function (Blueprint $table): void {
            $table->json('configuration')->nullable()->after('credential_reference');
        });
    }

    public function down(): void
    {
        Schema::table('integration_connections', function (Blueprint $table): void {
            $table->dropColumn('configuration');
        });
    }
};
