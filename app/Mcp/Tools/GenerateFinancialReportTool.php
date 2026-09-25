<?php

namespace App\Mcp\Tools;

use App\Models\Enterprise;
use App\Models\FinancialAccount;
use App\Models\FinancialPeriod;
use App\Models\TransactionCategory;
use App\Models\User;
use App\Services\FinancialReportingService;
use App\Services\McpCapabilityAuthorizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('generate-financial-report')]
#[Description('Generate a historical, Enterprise-scoped financial report through the governed Finance capability.')]
final class GenerateFinancialReportTool extends AuthorizedTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'financial_period_id' => $schema->integer()->min(1)->required(),
            'financial_account_id' => $schema->integer()->min(1),
            'transaction_category_id' => $schema->integer()->min(1),
            'agent_assignment_id' => $schema->integer()->min(1),
            'agent_execution_id' => $schema->integer()->min(1),
            'approval_request_id' => $schema->integer()->min(1),
        ];
    }

    public function handle(
        Request $request,
        McpCapabilityAuthorizer $authorization,
        FinancialReportingService $reports,
    ): Response|ResponseFactory {
        return $this->executeWithErrors($request, 'mcp.finance.report.generate', function () use ($request, $authorization, $reports) {
            $validated = $request->validate([
                'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
                'financial_period_id' => ['required', 'integer', 'min:1', 'exists:financial_periods,id'],
                'financial_account_id' => ['nullable', 'integer', 'min:1', 'exists:financial_accounts,id'],
                'transaction_category_id' => ['nullable', 'integer', 'min:1', 'exists:transaction_categories,id'],
                'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'],
                'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'],
                'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id'],
            ]);

            $actor = $request->user();

            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            /** @var Enterprise $enterprise */
            $enterprise = Enterprise::query()->findOrFail($validated['enterprise_id']);
            /** @var FinancialPeriod $period */
            $period = FinancialPeriod::query()->findOrFail($validated['financial_period_id']);
            /** @var FinancialAccount|null $account */
            $account = isset($validated['financial_account_id'])
                ? FinancialAccount::query()->findOrFail($validated['financial_account_id'])
                : null;
            /** @var TransactionCategory|null $category */
            $category = isset($validated['transaction_category_id'])
                ? TransactionCategory::query()->findOrFail($validated['transaction_category_id'])
                : null;

            if ((int) $period->enterprise_id !== (int) $enterprise->getKey()) {
                throw new AuthorizationException('The financial period is outside the requested Enterprise.');
            }

            if ($account !== null && (int) $account->enterprise_id !== (int) $enterprise->getKey()) {
                throw new AuthorizationException('The financial account is outside the requested Enterprise.');
            }

            if ($category !== null && (int) $category->enterprise_id !== (int) $enterprise->getKey()) {
                throw new AuthorizationException('The transaction category is outside the requested Enterprise.');
            }

            $authorization->authorizeMutation(
                $actor,
                'finance.report.generate',
                $enterprise,
                $validated['agent_assignment_id'] ?? null,
                $validated['agent_execution_id'] ?? null,
                $validated['approval_request_id'] ?? null,
                [
                    'enterprise_id' => $enterprise->getKey(),
                    'financial_period_id' => $period->getKey(),
                    'financial_account_id' => $account?->getKey(),
                    'transaction_category_id' => $category?->getKey(),
                ],
                ['createForEnterprise', [$enterprise]],
            );

            $report = $reports->generate($actor, $enterprise, $period, $account, $category);

            return Response::structured([
                'success' => true,
                'result' => [
                    'id' => $report->getKey(),
                    'enterprise_id' => $report->enterprise_id,
                    'financial_period_id' => $report->financial_period_id,
                    'financial_account_id' => $report->financial_account_id,
                    'transaction_category_id' => $report->transaction_category_id,
                    'currency' => $report->currency,
                    'metrics' => $report->metrics,
                    'generated_at' => $report->generated_at,
                ],
            ]);
        });
    }
}