<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audiences', function (Blueprint $table) {
            $table->string('persona_type', 40)->nullable()->after('description');
            $table->string('age_range', 100)->nullable();
            $table->string('location', 255)->nullable();
            $table->string('job_title', 255)->nullable();
            $table->string('income_range', 100)->nullable();
            $table->string('education_level', 100)->nullable();
            $table->string('family_status', 100)->nullable();
            $table->decimal('research_confidence', 3, 2)->nullable();
            $table->date('last_reviewed_at')->nullable();
            $table->json('psychographics')->nullable();
            $table->json('goals_and_motivations')->nullable();
            $table->json('pain_points')->nullable();
            $table->json('buying_behavior')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('audiences', function (Blueprint $table) {
            $table->dropColumn([
                'persona_type', 'age_range', 'location', 'job_title', 'income_range',
                'education_level', 'family_status', 'research_confidence', 'last_reviewed_at',
                'psychographics', 'goals_and_motivations', 'pain_points', 'buying_behavior',
            ]);
        });
    }
};