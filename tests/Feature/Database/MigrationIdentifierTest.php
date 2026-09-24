<?php

use Illuminate\Support\Facades\Schema;

it('keeps migration index identifiers within MySQL limits', function () {
    $expected = [
        'agent_assignments' => [
            ['name' => 'agent_assignments_scope_unique', 'columns' => ['agent_descriptor_id', 'organization_id', 'enterprise_id'], 'unique' => true],
        ],
        'integration_connections' => [
            ['name' => 'integration_connections_account_scope_unique', 'columns' => ['organization_id', 'provider', 'external_account_id'], 'unique' => true],
        ],
        'statements' => [
            ['name' => 'statements_account_date_index', 'columns' => ['enterprise_id', 'financial_account_id', 'statement_date'], 'unique' => false],
        ],
        'statement_entries' => [
            ['name' => 'statement_entries_account_date_index', 'columns' => ['enterprise_id', 'financial_account_id', 'entry_date'], 'unique' => false],
        ],
        'transactions' => [
            ['name' => 'transactions_period_date_index', 'columns' => ['enterprise_id', 'financial_period_id', 'transaction_date'], 'unique' => false],
        ],
        'financial_reports' => [
            ['name' => 'financial_reports_period_generated_index', 'columns' => ['enterprise_id', 'financial_period_id', 'generated_at'], 'unique' => false],
            ['name' => 'financial_reports_category_period_index', 'columns' => ['transaction_category_id', 'financial_period_id'], 'unique' => false],
        ],
    ];

    foreach ($expected as $table => $indexes) {
        $actual = collect(Schema::getIndexes($table))->keyBy('name');

        foreach ($indexes as $index) {
            expect(strlen($index['name']))->toBeLessThanOrEqual(64)
                ->and($actual)->toHaveKey($index['name']);

            expect($actual[$index['name']]['columns'])->toBe($index['columns'])
                ->and($actual[$index['name']]['unique'])->toBe($index['unique']);
        }
    }
});