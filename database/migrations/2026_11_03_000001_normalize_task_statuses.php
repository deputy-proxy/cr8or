<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $unsupported = DB::table('tasks')
            ->whereNotIn('status', ['todo', 'planned', 'in_progress', 'done', 'complete', 'completed', 'closed', 'cancelled', 'canceled'])
            ->distinct()
            ->pluck('status')
            ->all();

        if ($unsupported !== []) {
            throw new RuntimeException(
                'Cannot normalize task statuses. Unsupported existing values: '.implode(', ', $unsupported)
            );
        }

        DB::table('tasks')->whereIn('status', ['complete', 'completed', 'closed'])->update(['status' => 'done']);
        DB::table('tasks')->where('status', 'planned')->update(['status' => 'todo']);
        DB::table('tasks')->where('status', 'canceled')->update(['status' => 'cancelled']);
    }

    public function down(): void
    {
        // Canonicalized task statuses are intentionally not reversed.
    }
};
