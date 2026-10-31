<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('initiatives', 'strategy_id')) {
            Schema::table('initiatives', function (Blueprint $table): void {
                $table->foreignId('strategy_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            });
        } elseif (! collect(Schema::getForeignKeys('initiatives'))->contains(
            fn (array $foreignKey): bool => ($foreignKey['columns'] ?? []) === ['strategy_id']
                && ($foreignKey['foreign_table'] ?? null) === 'strategies',
        )) {
            Schema::table('initiatives', function (Blueprint $table): void {
                $table->foreign('strategy_id')->references('id')->on('strategies')->cascadeOnDelete();
            });
        }

        foreach (['projects', 'decisions'] as $table) {
            if (! Schema::hasColumn($table, 'plan_id')) {
                continue;
            }

            if (! Schema::hasTable('plans')) {
                throw new RuntimeException('Cannot resume Plan removal: '.$table.'.plan_id still exists but the plans table is missing. Inspect the partial migration before retrying.');
            }

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

        if (Schema::hasColumn('initiatives', 'plan_id')) {
            if (! Schema::hasTable('plans')) {
                throw new RuntimeException('Cannot resume Plan removal: initiatives.plan_id still exists but the plans table is missing. Inspect the partial migration before retrying.');
            }

            DB::table('initiatives')->select(['id', 'plan_id'])->whereNotNull('plan_id')->orderBy('id')->each(function (object $initiative): void {
                $strategyId = DB::table('plans')->where('id', $initiative->plan_id)->value('strategy_id');

                if ($strategyId !== null) {
                    DB::table('initiatives')->where('id', $initiative->id)->update(['strategy_id' => $strategyId]);
                }
            });
        }

        if (DB::table('initiatives')->whereNull('strategy_id')->exists()) {
            throw new RuntimeException('Cannot remove Plans: one or more Initiatives have no resolvable Strategy.');
        }

        if (Schema::hasColumn('initiatives', 'plan_id')) {
            $hasPlanForeignKey = collect(Schema::getForeignKeys('initiatives'))->contains(
                fn (array $foreignKey): bool => ($foreignKey['columns'] ?? []) === ['plan_id'],
            );
            $hasPlanIndex = Schema::hasIndex('initiatives', 'initiatives_plan_id_index');

            Schema::table('initiatives', function (Blueprint $table) use ($hasPlanForeignKey, $hasPlanIndex): void {
                $table->foreignId('strategy_id')->nullable(false)->change();
                if ($hasPlanForeignKey) {
                    $table->dropForeign(['plan_id']);
                }
                if ($hasPlanIndex) {
                    $table->dropIndex('initiatives_plan_id_index');
                }
                $table->dropColumn('plan_id');
            });
        }

        foreach (['projects', 'decisions'] as $tableName) {
            if (! Schema::hasColumn($tableName, 'plan_id')) {
                continue;
            }

            $hasPlanForeignKey = collect(Schema::getForeignKeys($tableName))->contains(
                fn (array $foreignKey): bool => ($foreignKey['columns'] ?? []) === ['plan_id'],
            );

            if ($hasPlanForeignKey) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->dropForeign(['plan_id']);
                });
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('plan_id');
            });
        }

        if (Schema::hasTable('plans')) {
            $remainingPlanReferences = collect(['initiatives', 'projects', 'decisions'])
                ->contains(fn (string $tableName): bool => Schema::hasColumn($tableName, 'plan_id'));

            if ($remainingPlanReferences) {
                throw new RuntimeException('Cannot finish Plan removal while legacy plan_id columns remain. Inspect the partial migration before retrying.');
            }

            Schema::drop('plans');
        }
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
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->index('plan_id', 'initiatives_plan_id_index');
            $table->foreign('plan_id')->references('id')->on('plans')->cascadeOnDelete();
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('decisions', function (Blueprint $table): void {
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
        });

        DB::table('strategies')->orderBy('id')->each(function (object $strategy): void {
            $hasInitiatives = DB::table('initiatives')->where('strategy_id', $strategy->id)->exists();
            $hasProjects = DB::table('projects')->where('strategy_id', $strategy->id)->exists();
            $hasDecisions = DB::table('decisions')->where('strategy_id', $strategy->id)->exists();

            if (! $hasInitiatives && ! $hasProjects && ! $hasDecisions) {
                return;
            }

            $planId = DB::table('plans')->insertGetId([
                'strategy_id' => $strategy->id,
                'name' => 'Recovered initiatives',
                'description' => 'Generated during rollback; original Plan grouping is not recoverable.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('initiatives')->where('strategy_id', $strategy->id)->update(['plan_id' => $planId]);
            DB::table('projects')->where('strategy_id', $strategy->id)->update(['plan_id' => $planId]);
            DB::table('decisions')->where('strategy_id', $strategy->id)->update(['plan_id' => $planId]);
        });

        $hasStrategyForeignKey = collect(Schema::getForeignKeys('initiatives'))->contains(
            fn (array $foreignKey): bool => ($foreignKey['columns'] ?? []) === ['strategy_id'],
        );

        if ($hasStrategyForeignKey) {
            Schema::table('initiatives', function (Blueprint $table): void {
                $table->dropForeign(['strategy_id']);
            });
        }

        if (Schema::hasColumn('initiatives', 'strategy_id')) {
            Schema::table('initiatives', function (Blueprint $table): void {
                $table->dropColumn('strategy_id');
            });
        }
    }
};
