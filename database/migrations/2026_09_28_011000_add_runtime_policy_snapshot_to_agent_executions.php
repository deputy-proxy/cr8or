<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_executions', function (Blueprint $table): void {
            $table->json('runtime_policy')->nullable()->after('model_options');
            $table->string('runtime_policy_version')->nullable()->after('runtime_policy');
        });
    }

    public function down(): void
    {
        Schema::table('agent_executions', function (Blueprint $table): void {
            $table->dropColumn(['runtime_policy', 'runtime_policy_version']);
        });
    }
};