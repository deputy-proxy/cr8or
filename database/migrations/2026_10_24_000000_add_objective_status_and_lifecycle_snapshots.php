<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('objectives', 'status')) {
            Schema::table('objectives', function (Blueprint $table): void {
                $table->string('status')->default('active');
            });
        }

        if (! Schema::hasIndex('objectives', 'objectives_enterprise_id_status_index')) {
            Schema::table('objectives', function (Blueprint $table): void {
                $table->index(['enterprise_id', 'status'], 'objectives_enterprise_id_status_index');
            });
        }

        if (! Schema::hasTable('lifecycle_snapshots')) {
            Schema::create('lifecycle_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
                $table->date('snapshot_date');
                $table->string('boundary', 8);
                $table->string('entity_type', 32);
                $table->unsignedBigInteger('open_count');
                $table->boolean('is_complete')->default(false);
                $table->json('metadata')->nullable();
                $table->timestamp('boundary_at');
                $table->timestamp('captured_at');
                $table->timestamps();
                $table->unique(['enterprise_id', 'snapshot_date', 'boundary', 'entity_type'], 'lifecycle_snapshots_scope_boundary_unique');
                $table->index(['organization_id', 'enterprise_id', 'snapshot_date'], 'lifecycle_snapshots_org_ent_date_idx');
            });
        } else {
            if (! Schema::hasIndex('lifecycle_snapshots', 'lifecycle_snapshots_scope_boundary_unique')) {
                Schema::table('lifecycle_snapshots', function (Blueprint $table): void {
                    $table->unique(['enterprise_id', 'snapshot_date', 'boundary', 'entity_type'], 'lifecycle_snapshots_scope_boundary_unique');
                });
            }

            if (! Schema::hasIndex('lifecycle_snapshots', 'lifecycle_snapshots_org_ent_date_idx')) {
                Schema::table('lifecycle_snapshots', function (Blueprint $table): void {
                    $table->index(['organization_id', 'enterprise_id', 'snapshot_date'], 'lifecycle_snapshots_org_ent_date_idx');
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lifecycle_snapshots');

        if (Schema::hasIndex('objectives', 'objectives_enterprise_id_status_index')) {
            Schema::table('objectives', function (Blueprint $table): void {
                $table->dropIndex('objectives_enterprise_id_status_index');
            });
        }

        if (Schema::hasColumn('objectives', 'status')) {
            Schema::table('objectives', function (Blueprint $table): void {
                $table->dropColumn('status');
            });
        }
    }
};
