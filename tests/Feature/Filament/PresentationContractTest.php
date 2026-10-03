<?php

use Filament\Resources\Resource;

it('covers every discovered Filament resource with explicit presentation labels', function () {
    $files = glob(app_path('Filament/Resources/*/*Resource.php'));

    expect($files)->toHaveCount(96);

    foreach ($files as $file) {
        $directory = basename(dirname($file));
        $class = 'App\\Filament\\Resources\\'.$directory.'\\'.basename($file, '.php');

        expect(class_exists($class))->toBeTrue()
            ->and(is_subclass_of($class, Resource::class))->toBeTrue()
            ->and($class::getNavigationLabel())->not->toBe('')
            ->and($class::getModelLabel())->not->toBe('')
            ->and($class::getPluralModelLabel())->not->toBe('');
    }
});

it('prevents accidental id-only Filament list tables', function () {
    foreach (glob(app_path('Filament/Resources/*/*Resource.php')) as $file) {
        $source = file_get_contents($file);

        preg_match_all('/(?:TextColumn|IconColumn|ImageColumn|BadgeColumn|ToggleColumn)::make\(\x27([^\x27]+)\x27/', $source, $matches);

        preg_match_all('/use (App\\\\Filament\\\\Resources\\\\[^;]+\\\\Tables\\\\[^;]+);/', $source, $tableImports);

        foreach ($tableImports[1] ?? [] as $tableClass) {
            $reflection = new ReflectionClass($tableClass);
            $tableFile = $reflection->getFileName();

            if ($tableFile) {
                $tableSource = file_get_contents($tableFile);
                preg_match_all('/(?:TextColumn|IconColumn|ImageColumn|BadgeColumn|ToggleColumn)::make\(\x27([^\x27]+)\x27/', $tableSource, $tableMatches);
                $matches[1] = array_merge($matches[1], $tableMatches[1] ?? []);
            }
        }

        $columns = array_values(array_unique($matches[1] ?? []));
        $nonIdColumns = array_values(array_filter($columns, fn (string $column): bool => $column !== 'id'));

        expect($nonIdColumns)->not->toBeEmpty("Resource {$file} must expose a meaningful non-ID list column.");
    }
});

it('keeps the audited id-only resources on their required table fields', function () {
    $required = [
        'ApprovalPolicies' => ['policy_key', 'capability'],
        'CommandWebhookDeliveries' => ['capability', 'idempotency_key', 'status', 'processed_at'],
        'AgentEpisodicMemories' => ['agentDescriptor.slug', 'topic', 'outcome', 'occurred_at'],
        'AgentExecutionEventRecords' => ['event_type', 'agent_execution_id', 'actor_id', 'occurred_at'],
        'AgentExecutionSteps' => ['agent_execution_id', 'sequence', 'status', 'started_at', 'completed_at'],
        'AgentSemanticMemories' => ['agentDescriptor.slug', 'statement', 'confidence', 'status'],
        'AgentSemanticMemoryVersions' => ['agent_semantic_memory_id', 'statement', 'change_type', 'recorded_at'],
        'ApprovalDecisions' => ['approval_request_id', 'decision', 'actor_name', 'decided_at'],
        'IntegrationResults' => ['integration_job_id', 'provider', 'operation', 'status', 'processed_at'],
        'KnowledgeEmbeddings' => ['knowledge_index_unit_id', 'knowledge_index_record_id', 'embedding_version', 'content_hash'],
        'KnowledgeIndexRecords' => ['knowledge_source_id', 'knowledge_document_id', 'unit_key', 'status', 'indexed_at'],
        'KnowledgeIndexUnits' => ['knowledge_index_record_id', 'unit_key', 'ordinal', 'content'],
        'MetricDefinitions' => ['key', 'name', 'unit', 'status'],
        'ReportMetricValues' => ['report_id', 'metric_definition_id', 'value', 'unit'],
        'ReportSnapshots' => ['report_id', 'captured_at', 'period_start', 'period_end'],
        'Reports' => ['enterprise_id', 'report_type', 'status', 'period_start', 'generated_at'],
        'Users' => ['name', 'email', 'email_verified_at'],
        'WorkflowExecutions' => ['workflow_id', 'workflow_version_id', 'status', 'current_stage_key', 'started_at', 'completed_at'],
        'WorkflowStages' => ['workflow_id', 'key', 'name', 'sequence'],
        'WorkflowVersions' => ['workflow_id', 'enterprise_id', 'version', 'name', 'status', 'published_at'],
    ];

    foreach ($required as $directory => $columns) {
        $files = glob(app_path("Filament/Resources/{$directory}/*Resource.php"));
        expect($files)->toHaveCount(1);

        $source = file_get_contents($files[0]);

        foreach ($columns as $column) {
            expect(str_contains($source, "make('{$column}')"))->toBeTrue(
                "Resource {$directory} is missing required column {$column}.",
            );
        }
    }
});