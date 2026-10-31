<?php

use App\Models\Enterprise;
use App\Models\Initiative;
use App\Models\Objective;
use App\Models\Project;
use App\Models\Strategy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('restores strategy-linked Project, Decision, and Initiative references when rolling back Plan removal', function () {
    $enterprise = Enterprise::factory()->create();
    $objective = Objective::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    $strategy = Strategy::factory()->create(['objective_id' => $objective->getKey()]);
    $initiative = Initiative::factory()->forStrategy($strategy)->create();
    $project = Project::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'strategy_id' => $strategy->getKey(),
    ]);
    $decisionId = DB::table('decisions')->insertGetId([
        'enterprise_id' => $enterprise->getKey(),
        'type' => 'operational',
        'strategy_id' => $strategy->getKey(),
        'title' => 'Strategy decision',
        'summary' => 'Decision associated with the Strategy.',
        'decided_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = require database_path('migrations/2026_10_31_000001_remove_plan_domain_model.php');
    $migration->down();

    $planId = DB::table('initiatives')->where('id', $initiative->getKey())->value('plan_id');

    expect($planId)->not->toBeNull()
        ->and(DB::table('projects')->where('id', $project->getKey())->value('plan_id'))->toBe($planId)
        ->and(DB::table('decisions')->where('id', $decisionId)->value('plan_id'))->toBe($planId);

    $migration->up();

    expect(Schema::hasTable('plans'))->toBeFalse()
        ->and(Schema::hasColumn('initiatives', 'plan_id'))->toBeFalse()
        ->and(Schema::hasColumn('projects', 'plan_id'))->toBeFalse()
        ->and(Schema::hasColumn('decisions', 'plan_id'))->toBeFalse()
        ->and(DB::table('initiatives')->where('id', $initiative->getKey())->value('strategy_id'))->toBe($strategy->getKey())
        ->and(DB::table('projects')->where('id', $project->getKey())->value('strategy_id'))->toBe($strategy->getKey())
        ->and(DB::table('decisions')->where('id', $decisionId)->value('strategy_id'))->toBe($strategy->getKey());
});
