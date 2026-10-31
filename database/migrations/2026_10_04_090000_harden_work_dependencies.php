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

        $lengthFunction = DB::getDriverName() === 'sqlite' ? 'LENGTH' : 'CHAR_LENGTH';

        foreach (['predecessor_type', 'successor_type', 'type'] as $column) {
            if (DB::table('dependencies')->whereRaw($lengthFunction.'('.$column.') > 191')->exists()) {
                throw new \RuntimeException("Cannot harden Work dependencies: {$column} contains values longer than the MySQL-safe 191-character index limit. Resolve these values explicitly first.");
            }
        }

        Schema::table('dependencies', function (Blueprint $table): void {
            $table->string('predecessor_type', 191)->change();
            $table->string('successor_type', 191)->change();
            $table->string('type', 191)->change();
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
            $table->string('predecessor_type', 255)->change();
            $table->string('successor_type', 255)->change();
            $table->string('type', 255)->change();
        });
    }
};
