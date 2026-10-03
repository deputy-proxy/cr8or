<?php

it('defines an explicit human-readable navigation label for every resource', function () {
    $resourceFiles = glob(app_path('Filament/Resources/*/*Resource.php'));

    expect($resourceFiles)->toHaveCount(98);

    foreach ($resourceFiles as $file) {
        $contents = file_get_contents($file);
        preg_match('/protected static \\?string \\$navigationLabel = [\'\"]([^\'\"]+)[\'\"];/', $contents, $label);
        expect($label[1] ?? null)->not->toBeNull();
        expect($label[1])->not->toMatch('/[a-z][A-Z]/');
        expect($label[1])->not->toContain(' s');
    }
});

it('ensures every resource has a meaningful effective list table', function () {
    $resourceFiles = glob(app_path('Filament/Resources/*/*Resource.php'));

    foreach ($resourceFiles as $resourceFile) {
        $contents = file_get_contents($resourceFile);
        $effective = $contents;

        if (preg_match('/return ([A-Za-z0-9_]+Table)::configure\(\$table\);/', $contents, $match)) {
            $tableFiles = glob(dirname($resourceFile).'/Tables/'.$match[1].'.php');
            expect($tableFiles)->toHaveCount(1);
            $effective = file_get_contents($tableFiles[0]);
        }

        preg_match_all('/(?:TextColumn|BadgeColumn|IconColumn|BooleanColumn|ImageColumn|DateColumn|DateTimeColumn|TextEntry)::make\([\'\"]([^\'\"]+)[\'\"]/', $effective, $columns);
        $columnNames = $columns[1] ?? [];
        $meaningful = array_filter($columnNames, fn (string $column): bool => $column !== 'id');

        expect($columnNames)->not->toBeEmpty("{$resourceFile} must define an effective list table")
            ->and($meaningful)->not->toBeEmpty("{$resourceFile} must expose at least one meaningful non-ID column");
    }
});

it('keeps known presentation labels aligned with CR8OR terminology', function () {
    $expected = [
        'AgentExecutionEventRecords' => 'Agent Execution Event Records',
        'AgentEpisodicMemories' => 'Agent Episodic Memories',
        'AgentSemanticMemories' => 'Agent Semantic Memories',
        'AgentSemanticMemoryVersions' => 'Agent Semantic Memory Versions',
        'ApprovalDecisions' => 'Approval Decisions',
        'ApprovalPolicies' => 'Approval Policies',
        'ApprovalRequests' => 'Approval Requests',
        'CommandWebhookDeliveries' => 'Command Webhook Deliveries',
        'ContentItems' => 'Content Items',
        'ContentSeries' => 'Content Series',
        'IntegrationConnections' => 'Integration Connections',
        'KnowledgeIndexRecords' => 'Knowledge Index Records',
        'KnowledgeIndexUnits' => 'Knowledge Index Units',
        'PublicationSchedules' => 'Publication Schedules',
        'Revenues' => 'Revenues',
        'WorkflowExecutions' => 'Workflow Executions',
    ];

    foreach ($expected as $directory => $label) {
        $file = glob(app_path("Filament/Resources/{$directory}/*Resource.php"));
        expect($file)->toHaveCount(1);
        preg_match('/protected static \\?string \\$navigationLabel = [\'\"]([^\'\"]+)[\'\"];/', $contents = file_get_contents($file[0]), $match);
        expect($match[1] ?? null)->toBe($label);
    }
});
