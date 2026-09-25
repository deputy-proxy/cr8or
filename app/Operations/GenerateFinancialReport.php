<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Enterprise;
use App\Models\FinancialAccount;
use App\Models\FinancialPeriod;
use App\Models\TransactionCategory;
use App\Models\User;
use App\Services\FinancialReportingService;

final class GenerateFinancialReport implements Operation
{
    public function __construct(private readonly FinancialReportingService $reports) {}

    public function execute(User $actor, array $input): mixed
    {
        $enterprise = $input['enterprise'] instanceof Enterprise
            ? $input['enterprise']
            : Enterprise::query()->findOrFail((int) $input['enterprise_id']);

        $period = $input['financial_period'] instanceof FinancialPeriod
            ? $input['financial_period']
            : FinancialPeriod::query()->findOrFail((int) $input['financial_period_id']);

        $account = $input['financial_account'] ?? (
            isset($input['financial_account_id'])
                ? FinancialAccount::query()->findOrFail((int) $input['financial_account_id'])
                : null
        );

        $category = $input['transaction_category'] ?? (
            isset($input['transaction_category_id'])
                ? TransactionCategory::query()->findOrFail((int) $input['transaction_category_id'])
                : null
        );

        return $this->reports->generate($actor, $enterprise, $period, $account, $category);
    }
}
