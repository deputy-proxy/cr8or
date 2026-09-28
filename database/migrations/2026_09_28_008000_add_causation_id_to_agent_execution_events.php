<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_execution_events', function (Blueprint $table): void {
            $table->string('causation_id', 128)->nullable()->after('correlation_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('agent_execution_events', function (Blueprint $table): void {
            $table->dropColumn('causation_id');
        });
    }
};