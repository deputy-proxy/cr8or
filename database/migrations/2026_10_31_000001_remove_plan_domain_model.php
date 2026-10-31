<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('initiatives', function (Blueprint $table): void {
            $table->foreignId('strategy_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        foreach (['projects', 'decisions'] as $table) {
            DB::table($table)->select(['id', 'strategy_id', 'plan_id'])->whereNotNull('plan_id')->orderBy('id')->each(function (object $record) use ($table): void {
                $planStrategyId = DB::table('plans')->where('id', $record->plan_id)->value('strategy_id');

                if ($planStrategyId === null) {
                    throw new RuntimeException('Cannot remove Plans: '.$table.' record '.$record->id.' has no resolvable Strategy.');
                }

                if ($record->strategy_id !== null && (int) $record->strategy_id !== (int) $planStrategyId) {
                    throw new RuntimeException('Cannot remove Plans: '.$table.' record '.$record->id.' has conflicting Strategy references.');
                }

                if ($record->strategy_id === null) {
                    DB::table($table)->where('id', $record->id)->update(['strategy_id' => $planStrategyId]);
                }
            });
        }

        DB::table('initiatives')->select(['id', 'plan_id'])->orderBy('id')->each(function (object $initiative): void {
            $strategyId = DB::table('plans')->where('id', $initiative->plan_id)->value('strategy_id');

            if ($strategyId !== null) {
                DB::table('initiatives')->where('id', $initiative->id)->update(['strategy_id' => $strategyId]);
            }
        });

        if (DB::table('initiatives')->whereNull('strategy_id')->exists()) {
            throw new RuntimeException('Cannot remove Plans: one or more Initiatives have no resolvable Strategy.');
        }

        Schema::table('initiatives', function (Blueprint $table): void {
            $table->foreignId('strategy_id')->nullable(false)->change();
            $table->dropForeign(['plan_id']);
            $table->dropIndex('initiatives_plan_id_index');
            $table->dropColumn('plan_id');
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('plan_id');
        });

        Schema::table('decisions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('plan_id');
        });

        Schema::drop('plans');
    }

    public function down(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('strategy_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::table('initiatives', function (Blueprint $table): void {
            $table->foreignId('plan_id')->nullable()->constrained()->cascadeOnDelete();
        });

        DB::table('strategies')->orderBy('id')->each(function (object $strategy): void {
            $initiativeIds = DB::table('initiatives')->where('strategy_id', $strategy->id)->pluck('id');
            if ($initiativeIds->isEmpty()) {
                return;
            }

            $planId = DB::table('plans')->insertGetId([
                'strategy_id' => $strategy->id,
                'name' => 'Recovered initiatives',
                'description' => 'Generated during rollback; original Plan grouping is not recoverable.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('initiatives')->whereIn('id', $initiativeIds)->update(['plan_id' => $planId]);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('decisions', function (Blueprint $table): void {
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('initiatives', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('strategy_id');
        });
    }
};
