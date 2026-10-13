<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publishing_jobs', function (Blueprint $table): void {
            $table->longText('adapter_state_encrypted')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('publishing_jobs', function (Blueprint $table): void {
            $table->dropColumn('adapter_state_encrypted');
        });
    }
};