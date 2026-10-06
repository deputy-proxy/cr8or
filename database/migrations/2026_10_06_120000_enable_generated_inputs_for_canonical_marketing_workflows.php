<?php

use App\Models\User;
use App\Models\Workflow;
use App\Services\WorkflowVersionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private array $workflowKeys = [
        'marketing.strategy.full_graph.create',
        'marketing.strategy.plan_gifts.full_graph.create.v4',
    ];

    public function up(): void
    {
        $workflowIds = DB::table('workflows')
            ->whereIn('canonical_key', $this->workflowKeys)
            ->pluck('id');

        foreach ($workflowIds as $workflowId) {
            $workflow = Workflow::query()->whereKey((int) $workflowId)->first();

            if ($workflow === null) {
                continue;
            }

            DB::transaction(function () use ($workflow): void {
                $workflow = Workflow::query()->lockForUpdate()->whereKey($workflow->getKey())->first();

                if ($workflow === null) {
                    return;
                }

                $publishedVersion = $workflow->publishedVersion()->first();

                if ($publishedVersion === null) {
                    return;
                }

                $policyValue = $workflow->getAttribute('execution_policy');
                /** @var array<string, mixed> $policy */
                $policy = is_array($policyValue) ? $policyValue : [];
                $policy['requires_model_provider'] = true;
                $workflow->setAttribute('execution_policy', $policy);

                foreach ($workflow->stages()->get() as $stage) {
                    $contractValue = $stage->getAttribute('input_contract');
                    /** @var array<string, mixed> $contract */
                    $contract = is_array($contractValue) ? $contractValue : [];

                    $requiredValue = $contract['required'] ?? [];
                    $generatedValue = $contract['generated'] ?? [];
                    $mappingsValue = $contract['mappings'] ?? [];
                    $defaultsValue = $contract['defaults'] ?? [];

                    /** @var list<string> $required */
                    $required = is_array($requiredValue)
                        ? array_values(array_filter($requiredValue, 'is_string'))
                        : [];

                    /** @var list<string> $generated */
                    $generated = is_array($generatedValue)
                        ? array_values(array_filter($generatedValue, 'is_string'))
                        : [];

                    /** @var array<string, string> $mappings */
                    $mappings = is_array($mappingsValue)
                        ? array_filter($mappingsValue, static fn (mixed $value): bool => is_string($value))
                        : [];

                    /** @var array<string, mixed> $defaults */
                    $defaults = is_array($defaultsValue) ? $defaultsValue : [];

                    $generatedFields = match ($stage->key) {
                        'strategy', 'audience', 'campaign', 'content_series' => ['name', 'description'],
                        'content' => ['title', 'body'],
                        'asset' => ['name', 'type', 'purpose', 'channel', 'platform', 'format'],
                        default => [],
                    };

                    $contract['required'] = array_values(array_unique([...$required, ...$generatedFields]));
                    $contract['generated'] = array_values(array_unique([...$generated, ...$generatedFields]));

                    foreach ($generatedFields as $field) {
                        unset($mappings[$field], $defaults[$field]);
                    }

                    $contract['mappings'] = $mappings;
                    $contract['defaults'] = $defaults;

                    $stage->setAttribute('input_contract', $contract);
                    $stage->save();
                }

                $workflow->save();

                $actor = User::query()->whereKey($publishedVersion->created_by)->first();

                if ($actor === null) {
                    throw new LogicException(
                        "Cannot create the generated-input WorkflowVersion for [{$workflow->canonical_key}] without the published version creator.",
                    );
                }

                app(WorkflowVersionService::class)->publish(
                    $workflow->refresh(),
                    $actor,
                    'workflow-stage-input-generation:'.$workflow->getKey(),
                );
            });
        }
    }

    public function down(): void
    {
        throw new LogicException(
            'Generated Workflow input behavior is versioned and cannot be safely rolled back.',
        );
    }
};
