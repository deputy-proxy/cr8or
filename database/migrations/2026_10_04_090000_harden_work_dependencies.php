<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('dependencies')->where('type', 'relates_to')->exists()) {
            throw new \RuntimeException('Cannot harden Work dependencies while relates_to records exist. Migrate them explicitly first.');
        }

        $duplicates = DB::table('dependencies')
            ->select('enterprise_id', 'predecessor_type', 'predecessor_id', 'successor_type', 'successor_id', 'type')
            ->groupBy('enterprise_id', 'predecessor_type', 'predecessor_id', 'successor_type', 'successor_id', 'type')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicates) {
            throw new \RuntimeException('Cannot harden Work dependencies while duplicate dependency records exist. Resolve duplicates explicitly first.');
        }

        Schema::table('dependencies', function (Blueprint $table): void {
            $table->unique(
                ['enterprise_id', 'predecessor_type', 'predecessor_id', 'successor_type', 'successor_id', 'type'],
                'dependencies_semantic_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('dependencies', function (Blueprint $table): void {
            $table->dropUnique('dependencies_semantic_unique');
        });
    }
};
