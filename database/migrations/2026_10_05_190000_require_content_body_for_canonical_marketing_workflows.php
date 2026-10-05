<?php

use App\Models\User;
use App\Models\Workflow;
use App\Services\WorkflowVersionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Close the content -> script contract for the canonical marketing graph.
     *
     * Draft content remains nullable at the domain capability level. These
     * workflows, however, explicitly feed content.body into a script stage
     * that requires a body, so the workflow input contract must require it.
     */
    public function up(): void
    {
        Workflow::query()
            ->whereIn('canonical_key', [
                'marketing.strategy.full_graph.create',
                'marketing.strategy.plan_gifts.full_graph.create.v4',
            ])
            ->get()
            ->each(function (Workflow $workflow): void {
                DB::transaction(function () use ($workflow): void {
                    $workflow = Workflow::query()->lockForUpdate()->find($workflow->getKey());

                    if (! $workflow instanceof Workflow || $workflow->publishedVersion === null) {
                        return;
                    }

                    $contentStage = $workflow->stages()->where('key', 'content')->first();

                    if ($contentStage === null) {
                        return;
                    }

                    /** @var mixed $rawContract */
                    $rawContract = $contentStage->getAttribute('input_contract');
                    $contract = is_array($rawContract) ? $rawContract : [];
                    $requiredValue = $contract['required'] ?? [];
                    $required = is_array($requiredValue)
                        ? array_values(array_unique([
                            ...array_values(array_filter($requiredValue, 'is_string')),
                            'body',
                        ]))
                        : ['body'];

                    $contract['required'] = $required;
                    $contentStage->setAttribute('input_contract', $contract);
                    $contentStage->save();

                    $actor = User::query()->find($workflow->publishedVersion->created_by);

                    if ($actor === null) {
                        throw new LogicException("Cannot repair Workflow [{$workflow->getKey()}] without its version creator.");
                    }

                    app(WorkflowVersionService::class)->publish(
                        $workflow->refresh(),
                        $actor,
                        'repair:marketing-content-body-required:'.$workflow->getKey(),
                    );
                });
            });
    }

    public function down(): void
    {
        throw new LogicException(
            'The canonical marketing content-body contract is versioned and cannot be safely rolled back.',
        );
    }
};
