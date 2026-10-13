<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_authorization_attempts', function (Blueprint $table): void {
            $table->text('provider_context_encrypted')->nullable()->after('code_verifier_encrypted');
        });
    }

    public function down(): void
    {
        Schema::table('social_authorization_attempts', function (Blueprint $table): void {
            $table->dropColumn('provider_context_encrypted');
        });
    }
};
