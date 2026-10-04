<?php

use App\Filament\Resources\Workflows\WorkflowResource;

it('formats capability contracts as exact pretty JSON', function (): void {
    expect(WorkflowResource::formatJsonContract([
        'enterprise_id' => 'integer|required',
        'name' => 'string|required',
    ]))->toBe(<<<'JSON'
{
    "enterprise_id": "integer|required",
    "name": "string|required"
}
JSON
    );
});

it('derives workflow input requirements while preserving defaults and mappings', function (): void {
    $contract = WorkflowResource::workflowInputContract([
        'enterprise_id' => 'integer|required',
        'name' => 'string|required',
        'description' => 'string|nullable',
    ], [
        'required' => ['obsolete_field'],
        'defaults' => ['description' => 'Default description'],
        'mappings' => ['enterprise_id' => 'enterprise.id'],
    ]);

    expect($contract)->toBe([
        'required' => ['enterprise_id', 'name'],
        'defaults' => ['description' => 'Default description'],
        'mappings' => ['enterprise_id' => 'enterprise.id'],
    ]);
});

it('initializes workflow input orchestration state from a capability contract', function (): void {
    expect(WorkflowResource::workflowInputContract([
        'enterprise_id' => 'integer|required',
        'name' => 'string|required',
        'description' => 'string|nullable',
    ]))->toBe([
        'required' => ['enterprise_id', 'name'],
        'defaults' => [],
        'mappings' => [],
    ]);
});

it('accepts serialized workflow input orchestration state', function (): void {
    $contract = WorkflowResource::workflowInputContract(
        ['name' => 'string|required'],
        json_encode([
            'defaults' => ['name' => 'Existing'],
            'mappings' => ['name' => 'context.name'],
        ], JSON_THROW_ON_ERROR),
    );

    expect($contract)->toBe([
        'defaults' => ['name' => 'Existing'],
        'mappings' => ['name' => 'context.name'],
        'required' => ['name'],
    ]);
});