<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, string> */
    private const RENAMES = [
        'marketing.audience.create' => 'marketing.persona.create',
        'marketing.audience.update' => 'marketing.persona.update',
        'marketing.audience.archive' => 'marketing.persona.archive',
        'create-audience' => 'create-persona',
        'list-audiences' => 'list-personas',
        'get-audience' => 'get-persona',
        'update-audience' => 'update-persona',
        'archive-audience' => 'archive-persona',
        'audience_ids' => 'persona_ids',
        'audience_id' => 'persona_id',
        'Audience' => 'Persona',
        'audience' => 'persona',
    ];

    public function up(): void
    {
        $pivotTable = Schema::hasTable('persona_product')
            ? 'persona_product'
            : (Schema::hasTable('audience_product') ? 'audience_product' : null);

        if ($pivotTable !== null) {
            foreach (['audience_id', 'persona_id', 'product_id'] as $column) {
                if (Schema::hasColumn($pivotTable, $column)) {
                    $this->dropForeignKeysForColumn($pivotTable, $column);
                }
            }

            $this->dropPrimaryKeyIfPresent($pivotTable);
        }

        if (Schema::hasTable('content_items')) {
            foreach (['audience_id', 'persona_id'] as $column) {
                if (Schema::hasColumn('content_items', $column)) {
                    $this->dropForeignKeysForColumn('content_items', $column);
                }
            }
        }

        if (Schema::hasTable('audiences') && ! Schema::hasTable('personas')) {
            Schema::rename('audiences', 'personas');
        }

        if (Schema::hasTable('audiences') && Schema::hasTable('personas')) {
            throw new RuntimeException('Both audiences and personas tables exist; refusing to guess which table contains canonical data.');
        }

        if (Schema::hasTable('audience_product') && ! Schema::hasTable('persona_product')) {
            Schema::rename('audience_product', 'persona_product');
        }

        if (Schema::hasTable('audience_product') && Schema::hasTable('persona_product')) {
            throw new RuntimeException('Both audience_product and persona_product tables exist; refusing to guess which table contains canonical data.');
        }

        if (Schema::hasTable('personas')) {
            $this->replaceIndexWithoutBreakingForeignKeys(
                'personas',
                'audiences_enterprise_id_status_index',
                'personas_enterprise_id_status_index',
                ['enterprise_id', 'status'],
            );
        }

        if (Schema::hasTable('content_items')) {
            $this->renameColumnIfNeeded('content_items', 'audience_id', 'persona_id');

            if (Schema::hasColumn('content_items', 'persona_id')) {
                if (! Schema::hasIndex('content_items', 'content_items_persona_id_index')) {
                    Schema::table('content_items', function (Blueprint $table): void {
                        $table->index('persona_id', 'content_items_persona_id_index');
                    });
                }

                if (! $this->hasForeignKeyForColumn('content_items', 'persona_id')) {
                    Schema::table('content_items', function (Blueprint $table): void {
                        $table->foreign('persona_id', 'content_items_persona_id_foreign')
                            ->references('id')->on('personas')->nullOnDelete();
                    });
                }
            }
        }

        if (Schema::hasTable('persona_product')) {
            $this->renameColumnIfNeeded('persona_product', 'audience_id', 'persona_id');

            if (! $this->hasPrimaryKey('persona_product')) {
                Schema::table('persona_product', function (Blueprint $table): void {
                    $table->primary(['persona_id', 'product_id'], 'persona_product_persona_id_product_id_primary');
                });
            }

            if (! $this->hasForeignKeyForColumn('persona_product', 'persona_id')) {
                Schema::table('persona_product', function (Blueprint $table): void {
                    $table->foreign('persona_id', 'persona_product_persona_id_foreign')
                        ->references('id')->on('personas')->cascadeOnDelete();
                });
            }

            if (! $this->hasForeignKeyForColumn('persona_product', 'product_id')) {
                Schema::table('persona_product', function (Blueprint $table): void {
                    $table->foreign('product_id', 'persona_product_product_id_foreign')
                        ->references('id')->on('products')->cascadeOnDelete();
                });
            }
        }

        $this->replaceWorkflowReferences(self::RENAMES);
    }

    public function down(): void
    {
        $reverse = array_flip(self::RENAMES);
        $this->replaceWorkflowReferences($reverse);

        if (Schema::hasTable('persona_product')) {
            foreach (['persona_id', 'audience_id', 'product_id'] as $column) {
                if (Schema::hasColumn('persona_product', $column)) {
                    $this->dropForeignKeysForColumn('persona_product', $column);
                }
            }
            $this->dropPrimaryKeyIfPresent('persona_product');
        }

        if (Schema::hasTable('content_items') && Schema::hasColumn('content_items', 'persona_id')) {
            $this->dropForeignKeysForColumn('content_items', 'persona_id');
            if (Schema::hasIndex('content_items', 'content_items_persona_id_index')) {
                Schema::table('content_items', function (Blueprint $table): void {
                    $table->dropIndex('content_items_persona_id_index');
                });
            }
            $this->renameColumnIfNeeded('content_items', 'persona_id', 'audience_id');
        }

        if (Schema::hasTable('persona_product')) {
            $this->renameColumnIfNeeded('persona_product', 'persona_id', 'audience_id');
        }

        if (Schema::hasTable('personas')) {
            $this->replaceIndexWithoutBreakingForeignKeys(
                'personas',
                'personas_enterprise_id_status_index',
                'audiences_enterprise_id_status_index',
                ['enterprise_id', 'status'],
            );
        }

        if (Schema::hasTable('persona_product')) {
            if (! $this->hasPrimaryKey('persona_product')) {
                Schema::table('persona_product', function (Blueprint $table): void {
                    $table->primary(['audience_id', 'product_id']);
                });
            }
            if (! $this->hasForeignKeyForColumn('persona_product', 'audience_id')) {
                Schema::table('persona_product', function (Blueprint $table): void {
                    $table->foreign('audience_id')->references('id')->on('personas')->cascadeOnDelete();
                });
            }
            if (! $this->hasForeignKeyForColumn('persona_product', 'product_id')) {
                Schema::table('persona_product', function (Blueprint $table): void {
                    $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
                });
            }
            Schema::rename('persona_product', 'audience_product');
        }

        if (Schema::hasTable('content_items') && Schema::hasColumn('content_items', 'audience_id')
            && ! $this->hasForeignKeyForColumn('content_items', 'audience_id')) {
            Schema::table('content_items', function (Blueprint $table): void {
                $table->foreign('audience_id')->references('id')->on('personas')->nullOnDelete();
            });
        }

        if (Schema::hasTable('personas')) {
            Schema::rename('personas', 'audiences');
        }
    }

    /** @param array<int, string> $columns */
    private function replaceIndexWithoutBreakingForeignKeys(
        string $table,
        string $oldName,
        string $newName,
        array $columns,
    ): void {
        $hasOld = Schema::hasIndex($table, $oldName);
        $hasNew = Schema::hasIndex($table, $newName);

        if (! $hasNew) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $newName): void {
                $blueprint->index($columns, $newName);
            });
        }

        if ($hasOld) {
            Schema::table($table, function (Blueprint $blueprint) use ($oldName): void {
                $blueprint->dropIndex($oldName);
            });
        }
    }

    private function renameColumnIfNeeded(string $table, string $oldName, string $newName): void
    {
        $hasOld = Schema::hasColumn($table, $oldName);
        $hasNew = Schema::hasColumn($table, $newName);

        if ($hasOld && $hasNew) {
            throw new RuntimeException("Both {$table}.{$oldName} and {$table}.{$newName} exist; refusing to risk data loss.");
        }

        if ($hasOld) {
            Schema::table($table, function (Blueprint $blueprint) use ($oldName, $newName): void {
                $blueprint->renameColumn($oldName, $newName);
            });
        }
    }

    private function dropForeignKeysForColumn(string $table, string $column): void
    {
        $foreignKeys = array_filter(
            Schema::getForeignKeys($table),
            static fn (array $foreignKey): bool => in_array($column, $foreignKey['columns'] ?? [], true),
        );

        foreach ($foreignKeys as $foreignKey) {
            Schema::table($table, function (Blueprint $blueprint) use ($foreignKey): void {
                if (DB::connection()->getDriverName() === 'sqlite') {
                    $blueprint->dropForeign($foreignKey['columns']);

                    return;
                }

                $blueprint->dropForeign($foreignKey['name']);
            });
        }
    }

    private function hasForeignKeyForColumn(string $table, string $column): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (in_array($column, $foreignKey['columns'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    private function dropPrimaryKeyIfPresent(string $table): void
    {
        if ($this->hasPrimaryKey($table)) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropPrimary();
            });
        }
    }

    private function hasPrimaryKey(string $table): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['primary'] ?? false) === true || strtolower((string) ($index['type'] ?? '')) === 'primary') {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, string> $renames */
    private function replaceWorkflowReferences(array $renames): void
    {
        $jsonColumns = [
            'workflows' => ['execution_policy', 'completion_criteria'],
            'workflow_stages' => [
                'capability_slugs', 'capability_input_contract', 'capability_output_contract',
                'input_contract', 'output_contract', 'completion_criteria',
            ],
            'workflow_versions' => ['stage_definitions', 'execution_policy', 'completion_criteria'],
        ];

        foreach ($jsonColumns as $table => $columns) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'id')) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                foreach (DB::table($table)->select(['id', $column])->whereNotNull($column)->get() as $row) {
                    $decoded = json_decode((string) $row->{$column}, true);
                    if (! is_array($decoded)) {
                        continue;
                    }

                    $updated = $this->replaceValues($decoded, $renames);
                    if ($updated !== $decoded) {
                        DB::table($table)->where('id', $row->id)->update([
                            $column => json_encode($updated, JSON_THROW_ON_ERROR),
                        ]);
                    }
                }
            }
        }
    }

    /** @param array<mixed> $value
     * @param  array<string, string>  $renames
     * @return array<mixed>
     */
    private function replaceValues(array $value, array $renames): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            $newKey = is_string($key) ? strtr($key, $renames) : $key;
            if (is_array($item)) {
                $result[$newKey] = $this->replaceValues($item, $renames);
            } elseif (is_string($item)) {
                $result[$newKey] = strtr($item, $renames);
            } else {
                $result[$newKey] = $item;
            }
        }

        return $result;
    }
};