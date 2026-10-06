<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Workflow;
use App\Services\WorkflowEntryPointService;
use App\Services\WorkflowVersionService;
use Illuminate\Database\Seeder;

final class GenericMarketingWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ExpertDescriptorSeeder::class);

        $actor = User::query()->where('email', 'test@example.com')->firstOrFail();

        $workflow = Workflow::query()
            ->where('enterprise_specific', false)
            ->where('canonical_key', 'marketing.system.create')
            ->first();

        if ($workflow === null) {
            $workflow = app(WorkflowEntryPointService::class)->create(
                $actor,
                null,
                [
                    'enterprise_specific' => false,
                    'name' => 'Marketing System Creation',
                    'canonical_key' => 'marketing.system.create',
                    'purpose' => 'Create a complete, reusable marketing system graph for an authorized Enterprise from strategy through planned creative assets.',
                    'execution_policy' => [
                        'template' => 'marketing.system.create',
                        'mode' => 'interactive',
                        'new_only' => true,
                        'requires_model_provider' => false,
                        'model_provider' => null,
                        'model' => null,
                    ],
                    'completion_criteria' => [
                        'required_stage_keys' => [
                            'strategy',
                            'audience',
                            'campaign',
                            'content_series',
                            'content',
                            'script',
                            'asset',
                            'verification',
                        ],
                    ],
                    'stages' => $this->stages(),
                ],
            );
        }

        $published = $workflow->publishedVersion;
        $publishedPolicy = $published instanceof \App\Models\WorkflowVersion && is_array($published->getAttribute('execution_policy'))
            ? $published->getAttribute('execution_policy')
            : [];

        if (
            $published?->status === 'published'
            && (($publishedPolicy['requires_model_provider'] ?? null) === false)
            && $this->isCallerDriven($workflow)
        ) {
            return;
        }

        $definition = [
            'execution_policy' => [
                'template' => 'marketing.system.create',
                'mode' => 'interactive',
                'new_only' => true,
                'requires_model_provider' => false,
                'model_provider' => null,
                'model' => null,
            ],
        ];

        $workflow->update([
            'execution_policy' => $definition['execution_policy'],
            'completion_criteria' => [
                'required_stage_keys' => [
                    'strategy',
                    'audience',
                    'campaign',
                    'content_series',
                    'content',
                    'script',
                    'asset',
                    'verification',
                ],
            ],
        ]);

        foreach ($this->stages() as $stage) {
            $workflow->stages()
                ->where('key', $stage['key'])
                ->update([
                    'name' => $stage['name'],
                    'instruction' => $stage['instruction'],
                    'sequence' => $stage['sequence'],
                    'dependencies' => $stage['dependencies'],
                    'input_contract' => $stage['input_contract'],
                    'output_contract' => $stage['output_contract'],
                    'completion_criteria' => $stage['completion_criteria'],
                ]);
        }

        app(WorkflowVersionService::class)->publish(
            $workflow->refresh(),
            $actor,
            'canonical:generic:marketing.system.create:v2:caller-driven',
        );
    }

    private function isCallerDriven(Workflow $workflow): bool
    {
        foreach ($workflow->stages as $stage) {
            $contractValue = $stage->getAttribute('input_contract');
            /** @var array<string, mixed> $contract */
            $contract = is_array($contractValue) ? $contractValue : [];
            $stageKey = (string) $stage->getAttribute('key');

            if (($contract['requested'] ?? []) === [] && $this->hasSemanticRequiredInputs($stageKey)) {
                return false;
            }
            if (($contract['generated'] ?? []) !== []) {
                return false;
            }
        }

        return true;
    }

    private function hasSemanticRequiredInputs(string $stageKey): bool
    {
        return in_array($stageKey, [
            'strategy',
            'audience',
            'campaign',
            'content_series',
            'content',
            'script',
            'asset',
        ], true);
    }

    /** @return list<array<string, mixed>> */
    private function stages(): array
    {
        return [
            [
                'key' => 'strategy',
                'name' => 'Create Marketing Strategy',
                'instruction' => 'Create a coherent marketing strategy for the authorized Enterprise. Request the strategy name and description from the caller; downstream identifiers must remain deterministic.',
                'sequence' => 1,
                'dependencies' => [],
                'expert_slugs' => ['marketing'],
                'capability_slugs' => ['marketing.strategy.create'],
                'input_contract' => [
                    'required' => ['name', 'description'],
                    'requested' => ['name', 'description'],
                ],
                'output_contract' => ['required' => ['id']],
                'completion_criteria' => [
                    'requires_termination_completed' => true,
                    'required_output_keys' => ['id'],
                ],
            ],
            [
                'key' => 'audience',
                'name' => 'Create Target Audience',
                'instruction' => 'Define the primary target audience for the marketing system from the Enterprise context and strategy context. Request only semantic audience fields from the caller.',
                'sequence' => 2,
                'dependencies' => ['strategy'],
                'expert_slugs' => ['marketing'],
                'capability_slugs' => ['marketing.audience.create'],
                'input_contract' => [
                    'required' => ['name', 'description'],
                    'requested' => ['name', 'description'],
                ],
                'output_contract' => ['required' => ['id']],
                'completion_criteria' => [
                    'requires_termination_completed' => true,
                    'required_output_keys' => ['id'],
                ],
            ],
            [
                'key' => 'campaign',
                'name' => 'Create Campaign',
                'instruction' => 'Create the primary campaign aligned with the caller-supplied marketing strategy and audience. The strategy identifier is deterministic and must never be generated.',
                'sequence' => 3,
                'dependencies' => ['strategy', 'audience'],
                'expert_slugs' => ['marketing'],
                'capability_slugs' => ['marketing.campaign.create'],
                'input_contract' => [
                    'required' => ['marketing_strategy_id', 'name', 'description'],
                    'requested' => ['name', 'description'],
                    'mappings' => [
                        'marketing_strategy_id' => 'stages.strategy.id',
                    ],
                ],
                'output_contract' => ['required' => ['id']],
                'completion_criteria' => [
                    'requires_termination_completed' => true,
                    'required_output_keys' => ['id'],
                ],
            ],
            [
                'key' => 'content_series',
                'name' => 'Create Content Series',
                'instruction' => 'Create the primary content series for the campaign. Request semantic series fields from the caller; campaign identity is deterministic.',
                'sequence' => 4,
                'dependencies' => ['campaign'],
                'expert_slugs' => ['marketing'],
                'capability_slugs' => ['marketing.content-series.create'],
                'input_contract' => [
                    'required' => ['campaign_id', 'name', 'description'],
                    'requested' => ['name', 'description'],
                    'mappings' => [
                        'campaign_id' => 'stages.campaign.id',
                    ],
                ],
                'output_contract' => ['required' => ['id']],
                'completion_criteria' => [
                    'requires_termination_completed' => true,
                    'required_output_keys' => ['id'],
                ],
            ],
            [
                'key' => 'content',
                'name' => 'Create Content Item',
                'instruction' => 'Create the primary content item for the campaign and series. Request the title and body from the caller using the upstream marketing context. Preserve all upstream identifiers exactly.',
                'sequence' => 5,
                'dependencies' => ['campaign', 'content_series', 'audience'],
                'expert_slugs' => ['copywriting'],
                'capability_slugs' => ['marketing.content.create'],
                'input_contract' => [
                    'required' => ['campaign_id', 'content_series_id', 'audience_id', 'title', 'body'],
                    'requested' => ['title', 'body'],
                    'mappings' => [
                        'campaign_id' => 'stages.campaign.id',
                        'content_series_id' => 'stages.content_series.id',
                        'audience_id' => 'stages.audience.id',
                    ],
                ],
                'output_contract' => ['required' => ['id']],
                'completion_criteria' => [
                    'requires_termination_completed' => true,
                    'required_output_keys' => ['id'],
                ],
            ],
            [
                'key' => 'script',
                'name' => 'Create Script',
                'instruction' => 'Create the production script for the caller-supplied content item. Request the script title and body from the caller from the complete upstream context.',
                'sequence' => 6,
                'dependencies' => ['content'],
                'expert_slugs' => ['copywriting'],
                'capability_slugs' => ['marketing.script.create'],
                'input_contract' => [
                    'required' => ['content_item_id', 'title', 'body'],
                    'requested' => ['title', 'body'],
                    'mappings' => [
                        'content_item_id' => 'stages.content.id',
                    ],
                ],
                'output_contract' => ['required' => ['id']],
                'completion_criteria' => [
                    'requires_termination_completed' => true,
                    'required_output_keys' => ['id'],
                ],
            ],
            [
                'key' => 'asset',
                'name' => 'Create Planned Asset',
                'instruction' => 'Create the planned creative asset specification for the script. Request semantic production fields from the caller only. The script identifier is deterministic.',
                'sequence' => 7,
                'dependencies' => ['script'],
                'expert_slugs' => ['copywriting'],
                'capability_slugs' => ['marketing.asset.create'],
                'input_contract' => [
                    'required' => [
                        'script_id',
                        'name',
                        'type',
                        'purpose',
                        'channel',
                        'platform',
                        'format',
                        'creative_brief',
                    ],
                    'requested' => [
                        'name',
                        'type',
                        'purpose',
                        'channel',
                        'platform',
                        'format',
                        'creative_brief',
                    ],
                    'mappings' => [
                        'script_id' => 'stages.script.id',
                    ],
                ],
                'output_contract' => ['required' => ['id']],
                'completion_criteria' => [
                    'requires_termination_completed' => true,
                    'required_output_keys' => ['id'],
                ],
            ],
            [
                'key' => 'verification',
                'name' => 'Verify Marketing System Graph',
                'instruction' => 'Verify that the complete Enterprise-scoped marketing graph exists and that the planned asset remains pending. All graph identifiers must come from completed upstream stages.',
                'sequence' => 8,
                'dependencies' => ['strategy', 'audience', 'campaign', 'content_series', 'content', 'script', 'asset'],
                'expert_slugs' => ['marketing'],
                'capability_slugs' => ['marketing.graph.verify'],
                'input_contract' => [
                    'required' => [
                        'marketing_strategy_id',
                        'audience_ids',
                        'campaign_ids',
                        'content_series_ids',
                        'content_item_ids',
                        'script_ids',
                        'asset_ids',
                    ],
                    'mappings' => [
                        'marketing_strategy_id' => 'stages.strategy.id',
                        'audience_ids' => 'stages.audience.id[]',
                        'campaign_ids' => 'stages.campaign.id[]',
                        'content_series_ids' => 'stages.content_series.id[]',
                        'content_item_ids' => 'stages.content.id[]',
                        'script_ids' => 'stages.script.id[]',
                        'asset_ids' => 'stages.asset.id[]',
                    ],
                ],
                'output_contract' => ['required' => ['verification_passed']],
                'completion_criteria' => [
                    'requires_termination_completed' => true,
                    'required_output_keys' => ['verification_passed'],
                    'required_capability_results' => [
                        [
                            'capability' => 'marketing.graph.verify',
                            'result_key' => 'verification_passed',
                            'result_value' => true,
                        ],
                    ],
                ],
            ],
        ];
    }
}