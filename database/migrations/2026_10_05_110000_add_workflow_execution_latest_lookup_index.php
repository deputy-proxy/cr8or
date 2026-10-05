<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_executions', function (Blueprint $table): void {
            $table->index(['workflow_id', 'id'], 'workflow_executions_workflow_id_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_executions', function (Blueprint $table): void {
            $table->dropIndex('workflow_executions_workflow_id_id_index');
        });
    }
};