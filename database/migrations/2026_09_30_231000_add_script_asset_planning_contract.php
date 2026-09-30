<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scripts', function (Blueprint $table): void {
            $table->json('asset_requirements')->nullable()->after('body');
        });

        Schema::table('assets', function (Blueprint $table): void {
            $table->foreignId('script_id')->nullable()->after('content_item_id')->constrained('scripts')->nullOnDelete();
            $table->foreignId('agent_assignment_id')->nullable()->after('script_id')->constrained('agent_assignments')->nullOnDelete();
            $table->foreignId('agent_execution_id')->nullable()->after('agent_assignment_id')->constrained('agent_executions')->nullOnDelete();
            $table->string('purpose')->nullable()->after('status');
            $table->string('channel')->nullable()->after('purpose');
            $table->string('platform')->nullable()->after('channel');
            $table->string('format')->nullable()->after('platform');
            $table->json('dimensions')->nullable()->after('format');
            $table->decimal('duration_seconds', 12, 3)->nullable()->after('dimensions');
            $table->text('creative_brief')->nullable()->after('duration_seconds');
            $table->index(['script_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->dropForeign(['agent_execution_id']);
            $table->dropForeign(['agent_assignment_id']);
            $table->dropForeign(['script_id']);
            $table->dropIndex(['script_id', 'status']);
            $table->dropColumn(['script_id', 'agent_assignment_id', 'agent_execution_id', 'purpose', 'channel', 'platform', 'format', 'dimensions', 'duration_seconds', 'creative_brief']);
        });

        Schema::table('scripts', function (Blueprint $table): void {
            $table->dropColumn('asset_requirements');
        });
    }
};