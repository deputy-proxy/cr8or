<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('objectives', function (Blueprint $table): void {
            $table->string('status')->default('active');
            $table->index(['enterprise_id', 'status']);
        });

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
    }

    public function down(): void
    {
        Schema::dropIfExists('lifecycle_snapshots');

        Schema::table('objectives', function (Blueprint $table): void {
            $table->dropIndex(['enterprise_id', 'status']);
            $table->dropColumn('status');
        });
    }
};
