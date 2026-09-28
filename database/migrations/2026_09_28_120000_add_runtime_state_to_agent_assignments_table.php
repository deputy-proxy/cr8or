<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_assignments', function (Blueprint $table): void {
            $table->string('status', 32)->default('draft')->after('enabled');
            $table->text('objective')->nullable()->after('status');
            $table->json('requirements')->nullable()->after('objective');
            $table->json('context')->nullable()->after('requirements');
            $table->string('correlation_id')->nullable()->after('context');
            $table->string('idempotency_key', 255)->nullable()->after('correlation_id');
            $table->timestamp('started_at')->nullable()->after('idempotency_key');
            $table->timestamp('completed_at')->nullable()->after('started_at');
            $table->index(['enterprise_id', 'status']);
            $table->unique(['enterprise_id', 'idempotency_key'], 'agent_assignments_enterprise_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('agent_assignments', function (Blueprint $table): void {
            $table->dropUnique('agent_assignments_enterprise_idempotency_unique');
            $table->dropIndex(['enterprise_id', 'status']);
            $table->dropColumn([
                'status',
                'objective',
                'requirements',
                'context',
                'correlation_id',
                'idempotency_key',
                'started_at',
                'completed_at',
            ]);
        });
    }
};
