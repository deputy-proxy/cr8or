<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_executions', function (Blueprint $table): void {
            $table->string('failure_category', 64)->nullable()->after('failure_code');
            $table->unsignedInteger('retry_count')->default(0)->after('failure_category');
            $table->unsignedInteger('max_retries')->default(3)->after('retry_count');
            $table->index(['status', 'failure_category']);
        });
    }

    public function down(): void
    {
        Schema::table('agent_executions', function (Blueprint $table): void {
            $table->dropIndex(['status', 'failure_category']);
            $table->dropColumn(['failure_category', 'retry_count', 'max_retries']);
        });
    }
};