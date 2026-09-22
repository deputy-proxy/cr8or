<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_descriptor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enterprise_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['agent_descriptor_id', 'organization_id', 'enterprise_id']);
            $table->index(['organization_id', 'enterprise_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_assignments');
    }
};
