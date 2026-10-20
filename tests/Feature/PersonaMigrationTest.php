<?php

use App\Models\Campaign;
use App\Models\Enterprise;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('preserves persona, content-item, and product associations when renaming legacy persistence', function () {
    $rollback = Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
    expect($rollback)->toBe(0)
        ->and(Schema::hasTable('audiences'))->toBeTrue()
        ->and(Schema::hasTable('audience_product'))->toBeTrue()
        ->and(Schema::hasColumn('content_items', 'audience_id'))->toBeTrue();

    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create();
    $workflow = Workflow::factory()->create([
        'enterprise_id' => $enterprise->id,
        'execution_policy' => ['target_field' => 'audience_id'],
    ]);
    $workflowVersion = WorkflowVersion::factory()->create([
        'workflow_id' => $workflow->id,
        'enterprise_id' => $enterprise->id,
        'created_by' => $user->id,
        'status' => WorkflowVersion::STATUS_PUBLISHED,
        'stage_definitions' => [[
            'capability_slugs' => ['marketing.audience.create'],
            'input_contract' => ['required' => ['audience_id']],
        ]],
    ]);
    DB::table('workflow_stages')->insert([
        'workflow_id' => $workflow->id,
        'key' => 'legacy-persona-stage',
        'name' => 'Legacy persona stage',
        'sequence' => 1,
        'dependencies' => json_encode([], JSON_THROW_ON_ERROR),
        'expert_slugs' => json_encode(['marketing'], JSON_THROW_ON_ERROR),
        'capability_slugs' => json_encode(['marketing.audience.create'], JSON_THROW_ON_ERROR),
        'input_contract' => json_encode(['required' => ['audience_id']], JSON_THROW_ON_ERROR),
        'output_contract' => json_encode([], JSON_THROW_ON_ERROR),
        'repeatable' => false,
        'completion_criteria' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => $now = now(),
        'updated_at' => $now,
    ]);
    $campaign = Campaign::factory()->create(['enterprise_id' => $enterprise->id]);
    $product = Product::factory()->create(['enterprise_id' => $enterprise->id]);
    $now = now();

    $personaId = DB::table('audiences')->insertGetId([
        'enterprise_id' => $enterprise->id,
        'name' => 'Preserved persona',
        'description' => 'Existing persona data must survive the rename.',
        'status' => 'active',
        'persona_type' => 'buyer',
        'age_range' => '30-49',
        'psychographics' => json_encode(['values' => ['quality']], JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('audience_product')->insert([
        'audience_id' => $personaId,
        'product_id' => $product->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $contentItemId = DB::table('content_items')->insertGetId([
        'enterprise_id' => $enterprise->id,
        'campaign_id' => $campaign->id,
        'audience_id' => $personaId,
        'title' => 'Content linked to preserved persona',
        'status' => 'draft',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $migrate = Artisan::call('migrate', [
        '--path' => 'database/migrations/2026_10_20_000001_rename_audience_domain_to_persona.php',
        '--force' => true,
    ]);

    expect($migrate)->toBe(0)
        ->and(Schema::hasTable('personas'))->toBeTrue()
        ->and(Schema::hasTable('persona_product'))->toBeTrue()
        ->and(Schema::hasColumn('content_items', 'persona_id'))->toBeTrue()
        ->and(Schema::hasColumn('content_items', 'audience_id'))->toBeFalse()
        ->and(DB::table('personas')->where('id', $personaId)->value('name'))->toBe('Preserved persona')
        ->and(DB::table('personas')->where('id', $personaId)->value('psychographics'))->toBe(json_encode(['values' => ['quality']], JSON_THROW_ON_ERROR))
        ->and(DB::table('persona_product')->where('persona_id', $personaId)->where('product_id', $product->id)->exists())->toBeTrue()
        ->and(DB::table('content_items')->where('id', $contentItemId)->value('persona_id'))->toBe($personaId)
        ->and(DB::table('workflow_stages')->where('workflow_id', $workflow->id)->value('capability_slugs'))->toBe(json_encode(['marketing.persona.create'], JSON_THROW_ON_ERROR))
        ->and(DB::table('workflow_stages')->where('workflow_id', $workflow->id)->value('input_contract'))->toBe(json_encode(['required' => ['persona_id']], JSON_THROW_ON_ERROR))
        ->and(DB::table('workflow_versions')->where('id', $workflowVersion->id)->value('stage_definitions'))->toBe(json_encode([[
            'capability_slugs' => ['marketing.persona.create'],
            'input_contract' => ['required' => ['persona_id']],
        ]], JSON_THROW_ON_ERROR))
        ->and(DB::table('workflows')->where('id', $workflow->id)->value('execution_policy'))->toBe(json_encode(['target_field' => 'persona_id'], JSON_THROW_ON_ERROR));
});
it('resumes the persona migration when the tables were already renamed before failure', function () {
    expect(Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]))->toBe(0);

    Schema::table('audience_product', function (Illuminate\Database\Schema\Blueprint $table): void {
        $table->dropForeign(['audience_id']);
        $table->dropForeign(['product_id']);
        $table->dropPrimary();
    });
    Schema::table('content_items', function (Illuminate\Database\Schema\Blueprint $table): void {
        $table->dropForeign(['audience_id']);
    });
    Schema::rename('audiences', 'personas');
    Schema::rename('audience_product', 'persona_product');

    expect(Schema::hasTable('personas'))->toBeTrue()
        ->and(Schema::hasTable('persona_product'))->toBeTrue()
        ->and(Schema::hasColumn('content_items', 'audience_id'))->toBeTrue()
        ->and(Schema::hasColumn('persona_product', 'audience_id'))->toBeTrue();

    expect(Artisan::call('migrate', [
        '--path' => 'database/migrations/2026_10_20_000001_rename_audience_domain_to_persona.php',
        '--force' => true,
    ]))->toBe(0);

    expect(Schema::hasTable('audiences'))->toBeFalse()
        ->and(Schema::hasTable('audience_product'))->toBeFalse()
        ->and(Schema::hasTable('personas'))->toBeTrue()
        ->and(Schema::hasTable('persona_product'))->toBeTrue()
        ->and(Schema::hasColumn('content_items', 'persona_id'))->toBeTrue()
        ->and(Schema::hasColumn('content_items', 'audience_id'))->toBeFalse()
        ->and(Schema::hasColumn('persona_product', 'persona_id'))->toBeTrue()
        ->and(Schema::hasColumn('persona_product', 'audience_id'))->toBeFalse()
        ->and(Schema::hasIndex('personas', 'personas_enterprise_id_status_index'))->toBeTrue();
});