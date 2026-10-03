<?php

namespace Database\Seeders;

use App\Enums\MembershipRole;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowStage;
use App\Models\WorkflowVersion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CanonicalWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ExpertDescriptorSeeder::class);

        $actor = User::query()->where('email', 'test@example.com')->firstOrFail();

        $organization = Organization::query()->firstOrCreate(
            ['slug' => 'valid-guide'],
            ['name' => 'valid.guide']
        );

        $enterprise = Enterprise::query()->firstOrCreate(
            ['slug' => 'valid.guide'],
            ['organization_id' => $organization->getKey(), 'name' => 'valid.guide', 'status' => 'active']
        );

        Membership::query()->firstOrCreate(
            ['user_id' => $actor->getKey(), 'organization_id' => $organization->getKey()],
            ['role' => MembershipRole::Owner]
        );

        $this->provision($enterprise, $actor, [
            'name' => 'Create Strategy Workflow',
            'canonical_key' => 'strategy.create',
            'purpose' => 'Create a strategy under an authorized enterprise objective.',
            'execution_policy' => [
                'template' => 'strategy.create',
                'mode' => 'deterministic',
                'new_only' => true,
                'requires_model_provider' => false,
            ],
            'stages' => [[
                'key' => 'create_strategy',
                'name' => 'Create Strategy',
                'sequence' => 1,
                'expert_slugs' => ['strategy'],
                'capability_slugs' => ['strategy.create'],
                'input_contract' => ['required' => ['objective_id', 'name']],
                'output_contract' => ['required' => ['id', 'objective_id', 'name']],
            ]],
        ]);

        $marketingStages = [];
        $sectionStages = [
            ['enterprise_context', 'Load canonical Enterprise context', 1, [], 'enterprise_context'],
            ['business_market_context', 'Analyze business and market context', 2, ['enterprise_context'], null],
            ['target_audiences', 'Define target audiences and customer segments', 3, ['business_market_context'], null],
            ['positioning', 'Define positioning', 4, ['target_audiences'], 'positioning'],
            ['value_proposition', 'Define value proposition', 5, ['positioning'], 'value_proposition'],
            ['competitive_landscape', 'Analyze competitive landscape', 6, ['value_proposition'], 'competitive_landscape'],
            ['product_service_strategy', 'Define product/service strategy', 7, ['competitive_landscape'], 'product_service_strategy'],
            ['marketing_objectives', 'Define marketing objectives', 8, ['product_service_strategy'], 'marketing_objectives'],
            ['acquisition_channels', 'Define acquisition channels', 9, ['marketing_objectives'], 'acquisition_channels'],
            ['content_strategy', 'Define content strategy', 10, ['acquisition_channels'], 'content_strategy'],
            ['seo_strategy', 'Define SEO strategy', 11, ['content_strategy'], 'seo_strategy'],
            ['social_strategy', 'Define social strategy', 12, ['seo_strategy'], 'social_strategy'],
            ['conversion_strategy', 'Define conversion strategy', 13, ['social_strategy'], 'conversion_strategy'],
            ['retention_strategy', 'Define retention strategy', 14, ['conversion_strategy'], 'retention_strategy'],
            ['measurement_kpis', 'Define measurement and KPIs', 15, ['retention_strategy'], 'measurement_kpis'],
            ['roadmap_90_days', 'Build the 90-day execution roadmap', 16, ['measurement_kpis'], 'roadmap_90_days'],
            ['completeness_validation', 'Validate completeness and consistency', 17, ['roadmap_90_days'], 'completeness_validation'],
        ];

        foreach ($sectionStages as $sectionDefinition) {
            $key = (string) $sectionDefinition[0];
            $name = (string) $sectionDefinition[1];
            $sequence = (int) $sectionDefinition[2];
            $dependencies = $sectionDefinition[3];
            $sectionKey = is_string($sectionDefinition[4]) ? $sectionDefinition[4] : null;

            $stage = [
                'key' => $key,
                'name' => $name,
                'sequence' => $sequence,
                'dependencies' => $dependencies,
                'expert_slugs' => ['marketing'],
                'capability_slugs' => [$sequence === 2 ? 'marketing.plan' : ($sequence === 3 ? 'marketing.audience.create' : 'marketing.strategy.section.define')],
                'input_contract' => [],
                'output_contract' => [],
            ];

            if ($sequence === 1 || $sectionKey !== null) {
                $stage['input_contract'] = [
                    'required' => ['section_key'],
                    'defaults' => ['section_key' => $sectionKey !== null ? $sectionKey : $key],
                ];
                $stage['output_contract'] = ['required' => ['key', 'data']];
            } elseif ($sequence === 2) {
                $stage['output_contract'] = ['required' => ['analysis']];
            } else {
                $stage['input_contract'] = [
                    'required' => ['name', 'description'],
                    'defaults' => [
                        'name' => 'Primary target audience',
                        'description' => 'Primary audience defined from the authorized enterprise context.',
                    ],
                ];
                $stage['output_contract'] = ['required' => ['id']];
            }

            $marketingStages[] = $stage;
        }

        $marketingStages[] = [
            'key' => 'persist_strategy',
            'name' => 'Persist the new Marketing Strategy',
            'sequence' => 18,
            'dependencies' => ['completeness_validation'],
            'expert_slugs' => ['marketing'],
            'capability_slugs' => ['marketing.strategy.create'],
            'input_contract' => [
                'required' => ['name', 'sections'],
                'defaults' => [
                    'name' => 'Canonical Marketing Strategy',
                    'description' => 'Deterministic marketing strategy created from authorized enterprise context.',
                    'new_only' => true,
                ],
                'mappings' => [
                    'name' => 'strategy_name',
                    'description' => 'strategy_description',
                    'sections' => 'stages',
                ],
            ],
            'output_contract' => ['required' => ['id', 'name', 'sections']],
        ];

        $this->provision($enterprise, $actor, [
            'name' => 'Canonical Marketing Strategy Workflow',
            'canonical_key' => 'marketing.strategy.create',
            'purpose' => 'Create a new deterministic marketing strategy from canonical enterprise context.',
            'execution_policy' => [
                'template' => 'marketing.strategy.create',
                'mode' => 'deterministic',
                'new_only' => true,
                'requires_model_provider' => false,
            ],
            'stages' => $marketingStages,
        ]);
    }

    /** @param array<string, mixed> $definition */
    private function provision(Enterprise $enterprise, User $actor, array $definition): void
    {
        DB::transaction(function () use ($enterprise, $actor, $definition): void {
            $workflow = Workflow::query()->firstOrCreate(
                [
                    'enterprise_id' => $enterprise->getKey(),
                    'canonical_key' => $definition['canonical_key'],
                ],
                [
                    'name' => $definition['name'],
                    'purpose' => $definition['purpose'],
                    'execution_policy' => $definition['execution_policy'],
                    'completion_criteria' => ['required_stage_keys' => array_column($definition['stages'], 'key')],
                    'status' => Workflow::STATUS_PENDING,
                ],
            );

            if ($workflow->wasRecentlyCreated) {
                foreach ($definition['stages'] as $stage) {
                    WorkflowStage::query()->create(array_merge([
                        'workflow_id' => $workflow->getKey(),
                        'dependencies' => [],
                        'input_contract' => [],
                        'output_contract' => [],
                        'capability_slugs' => [],
                        'expert_slugs' => [],
                        'repeatable' => false,
                        'completion_criteria' => ['requires_termination_completed' => true],
                    ], $stage));
                }
            }

            if ($workflow->publishedVersion?->status === WorkflowVersion::STATUS_PUBLISHED) {
                return;
            }

            $version = WorkflowVersion::query()->create([
                'workflow_id' => $workflow->getKey(),
                'enterprise_id' => $enterprise->getKey(),
                'version' => ((int) WorkflowVersion::query()->where('workflow_id', $workflow->getKey())->max('version')) + 1,
                'status' => WorkflowVersion::STATUS_DRAFT,
                'name' => $workflow->name,
                'purpose' => $workflow->purpose,
                'execution_policy' => $workflow->execution_policy ?? [],
                'completion_criteria' => $workflow->completion_criteria ?? [],
                'stage_definitions' => $workflow->stages()->get()->map(fn (WorkflowStage $stage): array => [
                    'key' => $stage->key,
                    'name' => $stage->name,
                    'sequence' => $stage->sequence,
                    'dependencies' => $stage->dependencies ?? [],
                    'expert_slugs' => $stage->expert_slugs ?? [],
                    'capability_slugs' => $stage->capability_slugs ?? [],
                    'input_contract' => $stage->input_contract ?? [],
                    'output_contract' => $stage->output_contract ?? [],
                    'repeatable' => (bool) $stage->repeatable,
                    'completion_criteria' => $stage->completion_criteria ?? [],
                ])->values()->all(),
                'idempotency_key' => 'canonical:'.$enterprise->getKey().':'.$definition['canonical_key'],
                'created_by' => $actor->getKey(),
            ]);

            WorkflowVersion::query()
                ->where('workflow_id', $workflow->getKey())
                ->where('status', WorkflowVersion::STATUS_PUBLISHED)
                ->update(['status' => WorkflowVersion::STATUS_RETIRED, 'retired_at' => now()]);

            $version->publish()->save();
            $workflow->update([
                'published_version_id' => $version->getKey(),
                'version' => $version->version,
            ]);
        });
    }
}