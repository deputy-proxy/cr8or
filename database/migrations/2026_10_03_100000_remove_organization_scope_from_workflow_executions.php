<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_executions', function (Blueprint $table): void {
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_executions', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->after('workflow_version');
        });

        DB::table('workflow_executions')
            ->join('enterprises', 'workflow_executions.enterprise_id', '=', 'enterprises.id')
            ->update(['workflow_executions.organization_id' => DB::raw('enterprises.organization_id')]);

        Schema::table('workflow_executions', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id')->nullable(false)->change();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
        });
    }
};
