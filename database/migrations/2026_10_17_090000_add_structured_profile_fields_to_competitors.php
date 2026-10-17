<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $fields = [
        'products',
        'target_segments',
        'pricing',
        'markets',
        'differentiators',
        'channels',
        'reputation',
        'digital_presence',
        'source_references',
    ];

    public function up(): void
    {
        Schema::table('competitors', function (Blueprint $table): void {
            foreach ($this->fields as $field) {
                $table->json($field)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('competitors', function (Blueprint $table): void {
            foreach ($this->fields as $field) {
                $table->dropColumn($field);
            }
        });
    }
};
