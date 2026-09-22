<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_permissions', function (Blueprint $table) {
            $table->boolean('requires_approval')->default(false)->after('capability');
        });
    }

    public function down(): void
    {
        Schema::table('agent_permissions', function (Blueprint $table) {
            $table->dropColumn('requires_approval');
        });
    }
};
