<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_enterprise_access', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('oauth_client_id')->constrained('oauth_clients')->cascadeOnDelete();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['oauth_client_id', 'enterprise_id']);
            $table->index(['oauth_client_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_enterprise_access');
    }
};
