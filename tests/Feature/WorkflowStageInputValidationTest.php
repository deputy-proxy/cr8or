<?php

namespace Tests\Feature;

use App\AI\Contracts\ExecutionErrorType;
use App\AI\Contracts\FailureCode;
use App\Exceptions\WorkflowDefinitionException;
use App\Models\Enterprise;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Services\FailureTranslator;
use App\Services\WorkflowDefinitionValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class WorkflowStageInputValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_publishing_rejects_an_unsatisfied_required_stage_input_when_an_upstream_stage_exposes_it(): void
    {
        $enterprise = Enterprise::factory()->create();
        $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise->getKey()]);

        $version = WorkflowVersion::factory()->create([
            'workflow_id' => $workflow->getKey(),
            'enterprise_id' => $enterprise->getKey(),
            'created_by' => User::factory()->create()->getKey(),
            'stage_definitions' => [
                [
                    'key' => 'strategy',
                    'name' => 'Strategy',
                    'sequence' => 1,
                    'dependencies' => [],
                    'expert_slugs' => [],
                    'capability_slugs' => [],
                    'input_contract' => [],
                    'output_contract' => [
                        'properties' => [
                            'marketing_strategy_id' => ['type' => 'integer'],
                        ],
                        'required' => ['marketing_strategy_id'],
                    ],
                ],
                [
                    'key' => 'campaign',
                    'name' => 'Campaign',
                    'sequence' => 2,
                    'dependencies' => ['strategy'],
                    'expert_slugs' => [],
                    'capability_slugs' => [],
                    'input_contract' => [
                        'required' => ['marketing_strategy_id'],
                    ],
                    'output_contract' => [],
                ],
            ],
        ]);

        try {
            app(WorkflowDefinitionValidator::class)->validateVersion($version);
            $this->fail('Expected WorkflowDefinitionException was not thrown.');
        } catch (WorkflowDefinitionException $exception) {
            $this->assertContains(
                'mapping.required.missing',
                array_column($exception->errors, 'code'),
            );
            $this->assertStringContainsString(
                'marketing_strategy_id',
                $exception->getMessage() . ' ' . json_encode($exception->errors),
            );
        }
    }

    public function test_workflow_input_failures_are_not_translated_as_authorization_failures(): void
    {
        $error = app(FailureTranslator::class)->translate(
            ValidationException::withMessages([
                'workflow.campaign.marketing_strategy_id' => 'Workflow stage [campaign] is missing required input [marketing_strategy_id].',
            ]),
        );

        $this->assertSame(ExecutionErrorType::Validation, $error->type);
        $this->assertSame(FailureCode::VALIDATION_FAILED, $error->code);
    }
}
