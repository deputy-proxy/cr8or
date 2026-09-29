<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_requests', function (Blueprint $table): void {
            $table->string('expert_slug', 100)->nullable()->after('agent_runtime_class');
            $table->string('expert_runtime_class')->nullable()->after('expert_slug');
        });
    }

    public function down(): void
    {
        Schema::table('approval_requests', function (Blueprint $table): void {
            $table->dropColumn(['expert_slug', 'expert_runtime_class']);
        });
    }
};
