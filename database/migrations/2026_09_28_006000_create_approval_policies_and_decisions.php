<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enterprise_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('policy_key')->unique();
            $table->string('capability');
            $table->json('stages');
            $table->unsignedInteger('expires_in_minutes')->default(60);
            $table->boolean('allow_self_approval')->default(false);
            $table->json('escalation_roles')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'enterprise_id', 'capability', 'enabled'], 'ap_org_ent_cap_enabled_idx');
        });

        Schema::create('approval_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enterprise_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('stage')->default(0);
            $table->string('decision');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->unique(['approval_request_id', 'stage', 'actor_id']);
            $table->index(['approval_request_id', 'stage', 'decision']);
        });

        Schema::table('approval_requests', function (Blueprint $table) {
            $table->json('policy_snapshot')->nullable();
            $table->string('policy_key')->nullable();
            $table->unsignedInteger('current_stage')->default(0);
            $table->string('request_hash', 64)->nullable()->index();
            $table->text('stale_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->dropColumn(['policy_snapshot', 'policy_key', 'current_stage', 'request_hash', 'stale_reason']);
        });

        Schema::dropIfExists('approval_decisions');
        Schema::dropIfExists('approval_policies');
    }
};