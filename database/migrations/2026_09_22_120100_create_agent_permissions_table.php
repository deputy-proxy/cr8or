<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_assignment_id')->constrained()->cascadeOnDelete();
            $table->string('capability');
            $table->timestamps();

            $table->unique(['agent_assignment_id', 'capability']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_permissions');
    }
};
