<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->foreignId('workflow_execution_id')
                ->nullable()
                ->after('script_id')
                ->constrained('workflow_executions')
                ->nullOnDelete();
            $table->index(['workflow_execution_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->dropIndex(['workflow_execution_id', 'status']);
            $table->dropForeign(['workflow_execution_id']);
            $table->dropColumn('workflow_execution_id');
        });
    }
};