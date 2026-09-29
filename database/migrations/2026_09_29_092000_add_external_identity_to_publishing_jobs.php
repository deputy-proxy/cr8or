<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publishing_jobs', function (Blueprint $table): void {
            $table->string('external_id')->nullable()->after('idempotency_key');
            $table->string('external_url')->nullable()->after('external_id');
        });
    }

    public function down(): void
    {
        Schema::table('publishing_jobs', function (Blueprint $table): void {
            $table->dropColumn(['external_id', 'external_url']);
        });
    }
};