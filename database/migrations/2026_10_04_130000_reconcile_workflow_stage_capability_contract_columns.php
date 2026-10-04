<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workflow_stages')) {
            return;
        }

        $missing = array_values(array_filter(
            ['capability_input_contract', 'capability_output_contract'],
            static fn (string $column): bool => ! Schema::hasColumn('workflow_stages', $column),
        ));

        if ($missing === []) {
            return;
        }

        Schema::table('workflow_stages', function (Blueprint $table) use ($missing): void {
            if (in_array('capability_input_contract', $missing, true)) {
                $table->json('capability_input_contract')->nullable();
            }

            if (in_array('capability_output_contract', $missing, true)) {
                $table->json('capability_output_contract')->nullable();
            }
        });
    }

    public function down(): void
    {
        // This migration reconciles potentially drifted deployments with the
        // canonical schema introduced by the preceding capability-contract
        // migration. It must never remove those canonical columns during a
        // rollback because they may have been created by that migration.
    }
};
