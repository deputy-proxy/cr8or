<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_executions', function (Blueprint $table): void {
            $table->string('correlation_id', 128)->nullable()->index();
            $table->string('provider')->nullable();
            $table->string('external_execution_id')->nullable();
            $table->string('failure_code')->nullable();
        });

        Schema::table('approval_requests', function (Blueprint $table): void {
            $table->string('correlation_id', 128)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('approval_requests', function (Blueprint $table): void {
            $table->dropIndex(['correlation_id']);
            $table->dropColumn('correlation_id');
        });
        Schema::table('agent_executions', function (Blueprint $table): void {
            $table->dropIndex(['correlation_id']);
            $table->dropColumn(['correlation_id', 'provider', 'external_execution_id', 'failure_code']);
        });
    }
};
