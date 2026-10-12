<?php

use App\Models\WorkflowStage;
use App\Services\WorkflowStageOutputValidator;
use Illuminate\Validation\ValidationException;

it('validates required output fields and primitive types from a JSON-schema-style contract', function (): void {
    $stage = WorkflowStage::factory()->create([
        'key' => 'publish',
        'output_contract' => [
            'type' => 'object',
            'required' => ['content_id', 'published'],
            'properties' => [
                'content_id' => ['type' => 'integer'],
                'published' => ['type' => 'boolean'],
            ],
        ],
    ]);
    $validator = app(WorkflowStageOutputValidator::class);

    expect(fn () => $validator->validate($stage, ['content_id' => '12']))
        ->toThrow(ValidationException::class);

    try {
        $validator->validate($stage, ['content_id' => '12']);
        test()->fail('Expected output contract validation to fail.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKeys([
            'workflow.publish.output.published',
            'workflow.publish.output.content_id',
        ]);
    }

    $validator->validate($stage, ['content_id' => 12, 'published' => true]);
});

it('validates legacy capability output contracts and leaves empty contracts opaque', function (): void {
    $validator = app(WorkflowStageOutputValidator::class);
    $stage = WorkflowStage::factory()->create([
        'key' => 'strategy',
        'output_contract' => [
            'id' => 'integer|required',
            'name' => 'string|required',
            'description' => 'string',
        ],
    ]);

    try {
        $validator->validate($stage, ['id' => 'wrong-type']);
        test()->fail('Expected legacy output contract validation to fail.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKeys([
            'workflow.strategy.output.name',
            'workflow.strategy.output.id',
        ]);
    }

    $validator->validate($stage, ['id' => 1, 'name' => 'A strategy']);

    $opaque = WorkflowStage::factory()->create(['output_contract' => []]);
    $validator->validate($opaque, ['arbitrary' => ['dynamic' => true]]);
});
