<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpis', function (Blueprint $table): void {
            $table->string('measurement_frequency', 20)->nullable()->after('current_value');
        });

        Schema::create('kpi_measurements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kpi_id')->constrained()->cascadeOnDelete();
            $table->decimal('value', 20, 4);
            $table->timestamp('measured_at');
            $table->string('source', 32);
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['kpi_id', 'measured_at', 'id'], 'kpi_measurements_kpi_measured_at_id_idx');
        });

        DB::table('kpis')
            ->whereNotNull('current_value')
            ->orderBy('id')
            ->chunkById(500, function ($kpis): void {
                $now = now();

                DB::table('kpi_measurements')->insert(
                    $kpis->map(function (object $kpi) use ($now): array {
                        $measuredAt = $kpi->updated_at ?? $kpi->created_at ?? $now;

                        return [
                            'kpi_id' => $kpi->id,
                            'value' => $kpi->current_value,
                            'measured_at' => $measuredAt,
                            'source' => 'legacy_migration',
                            'notes' => 'Baseline measurement migrated from the KPI current value.',
                            'recorded_by' => null,
                            'created_at' => $measuredAt,
                            'updated_at' => $measuredAt,
                        ];
                    })->all(),
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_measurements');

        Schema::table('kpis', function (Blueprint $table): void {
            $table->dropColumn('measurement_frequency');
        });
    }
};
