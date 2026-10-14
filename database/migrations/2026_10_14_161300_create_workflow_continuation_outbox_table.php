<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_continuation_outbox', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_key')->unique();
            $table->string('event_type', 128);
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('enterprise_id')->index();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->unsignedBigInteger('aggregate_id')->index();
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->timestamp('processed_at')->nullable()->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['processed_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_continuation_outbox');
    }
};
