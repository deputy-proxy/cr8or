<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('title');
            $table->text('summary');
            $table->text('rationale')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->index(['enterprise_id', 'decided_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_decisions');
    }
};