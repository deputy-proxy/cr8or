<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('command_webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('key_id', 128);
            $table->string('idempotency_key', 255);
            $table->string('capability', 150);
            $table->foreignId('actor_id')->constrained('users');
            $table->foreignId('organization_id')->constrained();
            $table->foreignId('enterprise_id')->constrained();
            $table->string('correlation_id', 255);
            $table->json('payload');
            $table->json('response')->nullable();
            $table->string('status', 32);
            $table->string('failure_code', 100)->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['key_id', 'idempotency_key'], 'command_webhook_delivery_dedupe');
            $table->index(['enterprise_id', 'capability']);
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('command_webhook_deliveries');
    }
};