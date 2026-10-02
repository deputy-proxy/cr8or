<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflows', function (Blueprint $table): void {
            $table->string('canonical_key', 150)->nullable()->after('name');
            $table->unique(['enterprise_id', 'canonical_key']);
        });
    }

    public function down(): void
    {
        Schema::table('workflows', function (Blueprint $table): void {
            $table->dropUnique(['enterprise_id', 'canonical_key']);
            $table->dropColumn('canonical_key');
        });
    }
};
