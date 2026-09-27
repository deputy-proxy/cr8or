<?php

use App\AI\ReasoningOutputValidator;
use InvalidArgumentException;

it('normalizes legacy Expert output into the structured reasoning contract', function () {
    $output = ReasoningOutputValidator::normalizeExpert([
        'enterprise' => 'example-enterprise',
    ]);

    expect($output['answer'])->toContain('example-enterprise')
        ->and($output['analysis'])->toBe($output['answer'])
        ->and($output['recommendations'])->toBe([])
        ->and($output['uncertainty']['level'])->toBe('medium');
});

it('rejects invalid Agent termination states', function () {
    expect(fn () => ReasoningOutputValidator::normalizeAgent([
        'answer' => 'No.',
        'decision_title' => '',
        'decision_summary' => '',
        'decision_rationale' => '',
        'capability_requests' => [],
        'termination' => 'invalid',
    ]))->toThrow(InvalidArgumentException::class);
});

it('accepts an explicit structured Agent reasoning result', function () {
    $output = ReasoningOutputValidator::normalizeAgent([
        'answer' => 'Proceed.',
        'decision_title' => 'Proceed',
        'decision_summary' => 'The evidence supports proceeding.',
        'decision_rationale' => 'Authorized context supports the decision.',
        'evidence_references' => ['strategy'],
        'selected_experts' => ['marketing'],
        'capability_requests' => [],
        'delegation_requests' => [],
        'termination' => 'completed',
        'termination_reason' => 'task_complete',
        'next_step' => '',
    ]);

    expect($output['termination'])->toBe('completed')
        ->and($output['selected_experts'])->toBe(['marketing']);
});