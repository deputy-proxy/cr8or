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
        if (Schema::hasTable('audience_product')) {
            Schema::table('audience_product', function (Blueprint $table): void {
                $table->dropForeign(['audience_id']);
                $table->dropForeign(['product_id']);
                $table->dropPrimary();
            });
        }

        if (Schema::hasTable('content_items') && Schema::hasColumn('content_items', 'audience_id')) {
            Schema::table('content_items', function (Blueprint $table): void {
                $table->dropForeign(['audience_id']);
            });
        }

        if (Schema::hasTable('audiences') && ! Schema::hasTable('personas')) {
            Schema::rename('audiences', 'personas');
        }

        if (Schema::hasTable('audience_product') && ! Schema::hasTable('persona_product')) {
            Schema::rename('audience_product', 'persona_product');
        }

        if (Schema::hasTable('personas') && Schema::hasIndex('personas', 'audiences_enterprise_id_status_index')) {
            Schema::table('personas', function (Blueprint $table): void {
                $table->dropIndex('audiences_enterprise_id_status_index');
                $table->index(['enterprise_id', 'status'], 'personas_enterprise_id_status_index');
            });
        }

        if (Schema::hasTable('content_items') && Schema::hasColumn('content_items', 'audience_id')) {
            Schema::table('content_items', function (Blueprint $table): void {
                $table->renameColumn('audience_id', 'persona_id');
            });
        }

        if (Schema::hasTable('content_items') && Schema::hasColumn('content_items', 'persona_id')) {
            Schema::table('content_items', function (Blueprint $table): void {
                $table->index('persona_id', 'content_items_persona_id_index');
                $table->foreign('persona_id', 'content_items_persona_id_foreign')
                    ->references('id')->on('personas')->nullOnDelete();
            });
        }

        if (Schema::hasTable('persona_product') && Schema::hasColumn('persona_product', 'audience_id')) {
            Schema::table('persona_product', function (Blueprint $table): void {
                $table->renameColumn('audience_id', 'persona_id');
            });
        }

        if (Schema::hasTable('persona_product')) {
            Schema::table('persona_product', function (Blueprint $table): void {
                $table->primary(['persona_id', 'product_id'], 'persona_product_persona_id_product_id_primary');
                $table->foreign('persona_id', 'persona_product_persona_id_foreign')
                    ->references('id')->on('personas')->cascadeOnDelete();
                $table->foreign('product_id', 'persona_product_product_id_foreign')
                    ->references('id')->on('products')->cascadeOnDelete();
            });
        }

        $this->replaceWorkflowReferences(self::RENAMES);
    }

    public function down(): void
    {
        $reverse = array_flip(self::RENAMES);
        $this->replaceWorkflowReferences($reverse);

        if (Schema::hasTable('persona_product')) {
            Schema::table('persona_product', function (Blueprint $table): void {
                $table->dropForeign(['persona_id']);
                $table->dropForeign(['product_id']);
                $table->dropPrimary();
            });
        }

        if (Schema::hasTable('content_items') && Schema::hasColumn('content_items', 'persona_id')) {
            Schema::table('content_items', function (Blueprint $table): void {
                $table->dropForeign(['persona_id']);
                $table->dropIndex('content_items_persona_id_index');
            });
        }

        if (Schema::hasTable('content_items') && Schema::hasColumn('content_items', 'persona_id')) {
            Schema::table('content_items', function (Blueprint $table): void {
                $table->renameColumn('persona_id', 'audience_id');
            });
        }

        if (Schema::hasTable('persona_product') && Schema::hasColumn('persona_product', 'persona_id')) {
            Schema::table('persona_product', function (Blueprint $table): void {
                $table->renameColumn('persona_id', 'audience_id');
            });
        }

        if (Schema::hasTable('personas')) {
            Schema::table('personas', function (Blueprint $table): void {
                $table->dropIndex('personas_enterprise_id_status_index');
                $table->index(['enterprise_id', 'status'], 'audiences_enterprise_id_status_index');
            });
        }

        if (Schema::hasTable('persona_product')) {
            Schema::table('persona_product', function (Blueprint $table): void {
                $table->primary(['audience_id', 'product_id']);
                $table->foreign('audience_id')->references('id')->on('personas')->cascadeOnDelete();
                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            });
            Schema::rename('persona_product', 'audience_product');
        }

        if (Schema::hasTable('content_items') && Schema::hasColumn('content_items', 'audience_id')) {
            Schema::table('content_items', function (Blueprint $table): void {
                $table->foreign('audience_id')->references('id')->on('personas')->nullOnDelete();
            });
        }

        if (Schema::hasTable('personas')) {
            Schema::rename('personas', 'audiences');
        }
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