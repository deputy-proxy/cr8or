<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_stages', function (Blueprint $table): void {
            $table->json('capability_input_contract')->nullable()->after('capability_slugs');
            $table->json('capability_output_contract')->nullable()->after('capability_input_contract');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_stages', function (Blueprint $table): void {
            $table->dropColumn(['capability_input_contract', 'capability_output_contract']);
        });
    }
};