<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_executions', function (Blueprint $table): void {
            $table->string('mode')->default('autonomous')->after('status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('agent_executions', function (Blueprint $table): void {
            $table->dropColumn('mode');
        });
    }
};