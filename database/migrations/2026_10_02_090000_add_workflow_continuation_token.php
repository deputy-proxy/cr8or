<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_executions', function (Blueprint $table): void {
            $table->uuid('continuation_token')->nullable()->after('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_executions', function (Blueprint $table): void {
            $table->dropColumn('continuation_token');
        });
    }
};