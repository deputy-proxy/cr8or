<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publishing_jobs', function (Blueprint $table): void {
            $table->string('adapter_key')->nullable()->after('idempotency_key');
            $table->string('network')->nullable()->after('adapter_key');
            $table->string('destination_external_id')->nullable()->after('network');
            $table->boolean('retryable')->default(false)->after('status');
        });

        Schema::table('publication_results', function (Blueprint $table): void {
            $table->string('network')->nullable()->after('provider');
            $table->string('destination_external_id')->nullable()->after('network');
        });

        DB::table('publishing_jobs')->orderBy('id')->chunkById(100, function ($jobs): void {
            foreach ($jobs as $job) {
                $publication = DB::table('publications')->where('id', $job->publication_id)->first();
                if ($publication === null) {
                    continue;
                }

                DB::table('publishing_jobs')->where('id', $job->id)->update([
                    'adapter_key' => DB::table('publication_results')->where('publishing_job_id', $job->id)->orderByDesc('id')->value('provider') ?? $publication->target_provider,
                    'network' => $publication->target_provider,
                    'destination_external_id' => $publication->target_external_id,
                ]);
            }
        });

        DB::table('publication_results')->orderBy('id')->chunkById(100, function ($results): void {
            foreach ($results as $result) {
                $publication = DB::table('publications')->where('id', $result->publication_id)->first();
                if ($publication === null) {
                    continue;
                }

                DB::table('publication_results')->where('id', $result->id)->update([
                    'network' => $publication->target_provider,
                    'destination_external_id' => $publication->target_external_id,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('publication_results', function (Blueprint $table): void {
            $table->dropColumn(['network', 'destination_external_id']);
        });

        Schema::table('publishing_jobs', function (Blueprint $table): void {
            $table->dropColumn(['adapter_key', 'network', 'destination_external_id', 'retryable']);
        });
    }
};