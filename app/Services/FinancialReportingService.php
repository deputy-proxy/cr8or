<?php

namespace App\Services;

use App\Models\BusinessHealthResult;
use App\Models\Enterprise;
use App\Models\FinancialAccount;
use App\Models\FinancialPeriod;
use App\Models\FinancialReport;
use App\Models\TransactionCategory;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

class FinancialReportingService
{
    /**
     * Generate a period-scoped financial report and its business-health result.
     *
     * @throws RuntimeException when the scoped source data is inconsistent.
     */
    public function generate(
        User $user,
        Enterprise $enterprise,
        FinancialPeriod $period,
        ?FinancialAccount $account = null,
        ?TransactionCategory $category = null,
    ): FinancialReport {
        Gate::forUser($user)->authorize('create', [FinancialReport::class, $enterprise]);

        $this->validateScope($enterprise, $period, $account, $category);

        return DB::transaction(function () use ($enterprise, $period, $account, $category): FinancialReport {
            $generatedAt = Carbon::now();
            $metrics = $this->calculateMetrics($enterprise, $period, $account, $category);

            $report = FinancialReport::query()->create([
                'enterprise_id' => $enterprise->getKey(),
                'financial_period_id' => $period->getKey(),
                'financial_account_id' => $account?->getKey(),
                'transaction_category_id' => $category?->getKey(),
                'currency' => $this->resolveCurrency($account, $enterprise->getKey()),
                'metrics' => $metrics,
                'source_snapshot' => $this->snapshot($enterprise, $period, $account, $category),
                'generated_at' => $generatedAt,
            ]);

            BusinessHealthResult::query()->create([
                'enterprise_id' => $enterprise->getKey(),
                'financial_report_id' => $report->getKey(),
                'health_status' => $this->healthStatus($metrics),
                'metrics' => $this->healthMetrics($metrics),
                'source_snapshot' => $report->source_snapshot,
                'evaluated_at' => $generatedAt,
            ]);

            return $report->load('financialPeriod', 'financialAccount', 'category');
        });
    }

    /** @return array<string, mixed> */
    public function context(Enterprise $enterprise): array
    {
        $accounts = $enterprise->financialAccounts()->orderBy('id')->get()->map(fn (FinancialAccount $account): array => [
            'id' => $account->getKey(), 'name' => $account->name, 'type' => $account->type,
            'status' => $account->status, 'currency' => $account->currency,
        ])->all();

        $periods = [];
        foreach ($enterprise->financialPeriods()->orderByDesc('period_end')->get() as $period) {
            $periods[] = [
                'id' => $period->getKey(), 'name' => $period->name,
                'start' => Carbon::parse((string) $period->period_start)->toDateString(),
                'end' => Carbon::parse((string) $period->period_end)->toDateString(),
                'status' => $period->status,
            ];
        }

        $latestPeriod = $enterprise->financialPeriods()->orderByDesc('period_end')->first();
        $currencies = $enterprise->financialAccounts()->pluck('currency')->unique()->values();

        return [
            'enterprise' => ['id' => $enterprise->getKey(), 'name' => $enterprise->name, 'slug' => $enterprise->slug],
            'accounts' => $accounts, 'periods' => $periods,
            'latest_period_metrics' => $latestPeriod !== null && $currencies->count() === 1
                ? $this->calculateMetrics($enterprise, $latestPeriod, null, null) : null,
            'currency' => $currencies->count() === 1 ? (string) $currencies->first() : null,
            'currencies' => $currencies->all(),
        ];
    }

    /** @return array<string, int|string> */
    private function calculateMetrics(
        Enterprise $enterprise,
        FinancialPeriod $period,
        ?FinancialAccount $account,
        ?TransactionCategory $category,
    ): array {
        $periodStart = Carbon::parse((string) $period->period_start)->toDateString();
        $periodEnd = Carbon::parse((string) $period->period_end)->toDateString();

        $transactions = $enterprise->transactions()
            ->whereBetween('transaction_date', [$periodStart, $periodEnd])
            ->when($account !== null, fn ($query) => $query->where('financial_account_id', $account->getKey()))
            ->when($category !== null, fn ($query) => $query->where('transaction_category_id', $category->getKey()));

        $revenues = $enterprise->revenues()
            ->whereBetween('revenue_date', [$periodStart, $periodEnd])
            ->when($account !== null, fn ($query) => $query->where('financial_account_id', $account->getKey()))
            ->when($category !== null, fn ($query) => $query->whereHas(
                'transaction',
                fn ($query) => $query->where('transaction_category_id', $category->getKey()),
            ));

        $expenses = $enterprise->expenses()
            ->whereBetween('expense_date', [$periodStart, $periodEnd])
            ->when($account !== null, fn ($query) => $query->where('financial_account_id', $account->getKey()))
            ->when($category !== null, fn ($query) => $query->where('transaction_category_id', $category->getKey()));

        $budgetQuery = $enterprise->budgets()
            ->where('financial_period_id', $period->getKey())
            ->when($account !== null, fn ($query) => $query->where(function ($query) use ($account): void {
                $query->whereNull('financial_account_id')
                    ->orWhere('financial_account_id', $account->getKey());
            }))
            ->when($category !== null, fn ($query) => $query->where(function ($query) use ($category): void {
                $query->whereNull('transaction_category_id')
                    ->orWhere('transaction_category_id', $category->getKey());
            }));

        $transactionValues = array_values($transactions->pluck('amount')->map(fn ($value): string => (string) $value)->all());
        $revenueValues = array_values($revenues->pluck('amount')->map(fn ($value): string => (string) $value)->all());
        $expenseValues = array_values($expenses->pluck('amount')->map(fn ($value): string => (string) $value)->all());
        $budgetValues = array_values($budgetQuery->pluck('planned_amount')->map(fn ($value): string => (string) $value)->all());

        $revenueTotal = $this->sumDecimals($revenueValues);
        $expenseTotal = $this->sumDecimals($expenseValues);
        $netMovement = $this->subtractDecimals($revenueTotal, $expenseTotal);
        $transactionNet = $this->sumDecimals($transactionValues);
        $budgetTotal = $this->sumDecimals($budgetValues);
        $budgetVariance = $this->subtractDecimals($budgetTotal, $expenseTotal);

        return [
            'revenue_total' => $revenueTotal,
            'expense_total' => $expenseTotal,
            'net_movement' => $netMovement,
            'transaction_net_movement' => $transactionNet,
            'transaction_count' => $transactions->count(),
            'account_count' => $enterprise->financialAccounts()->when($account !== null, fn ($query) => $query->whereKey($account->getKey()))->count(),
            'budget_total' => $budgetTotal,
            'budget_variance' => $budgetVariance,
            'budget_count' => $budgetQuery->count(),
        ];
    }

    /** @param array<string, int|string> $metrics */
    private function healthStatus(array $metrics): string
    {
        $netMovement = (string) $metrics['net_movement'];
        $budgetVariance = (string) $metrics['budget_variance'];

        if ($this->isNegative($netMovement) || ((int) $metrics['budget_count'] > 0 && $this->isNegative($budgetVariance))) {
            return 'attention';
        }

        return 'healthy';
    }

    /**
     * @param  array<string, int|string>  $metrics
     * @return array<string, string>
     */
    private function healthMetrics(array $metrics): array
    {
        return [
            'net_movement' => (string) $metrics['net_movement'],
            'budget_variance' => (string) $metrics['budget_variance'],
            'revenue_total' => (string) $metrics['revenue_total'],
            'expense_total' => (string) $metrics['expense_total'],
        ];
    }

    private function validateScope(
        Enterprise $enterprise,
        FinancialPeriod $period,
        ?FinancialAccount $account,
        ?TransactionCategory $category,
    ): void {
        if ((int) $period->enterprise_id !== (int) $enterprise->getKey()) {
            throw new RuntimeException('Financial period must belong to the report enterprise.');
        }

        if ($account !== null && (int) $account->enterprise_id !== (int) $enterprise->getKey()) {
            throw new RuntimeException('Financial account must belong to the report enterprise.');
        }

        if ($category !== null && (int) $category->enterprise_id !== (int) $enterprise->getKey()) {
            throw new RuntimeException('Transaction category must belong to the report enterprise.');
        }
    }

    private function resolveCurrency(?FinancialAccount $account, int $enterpriseId): string
    {
        if ($account !== null) {
            return (string) $account->currency;
        }

        $currencies = FinancialAccount::query()
            ->where('enterprise_id', $enterpriseId)
            ->pluck('currency')
            ->unique()
            ->values();

        if ($currencies->count() !== 1) {
            throw new RuntimeException('A report without an account scope requires exactly one Enterprise currency.');
        }

        return (string) $currencies->first();
    }

    /** @return array<string, mixed> */
    private function snapshot(
        Enterprise $enterprise,
        FinancialPeriod $period,
        ?FinancialAccount $account,
        ?TransactionCategory $category,
    ): array {
        return [
            'enterprise' => [
                'id' => $enterprise->getKey(),
                'name' => $enterprise->name,
                'slug' => $enterprise->slug,
            ],
            'period' => [
                'id' => $period->getKey(),
                'name' => $period->name,
                'start' => Carbon::parse((string) $period->period_start)->toDateString(),
                'end' => Carbon::parse((string) $period->period_end)->toDateString(),
            ],
            'account' => $account === null ? null : [
                'id' => $account->getKey(),
                'name' => $account->name,
                'type' => $account->type,
                'currency' => $account->currency,
            ],
            'category' => $category === null ? null : [
                'id' => $category->getKey(),
                'name' => $category->name,
            ],
        ];
    }

    /** @param list<string> $values */
    private function sumDecimals(array $values): string
    {
        $total = '0.0000';

        foreach ($values as $value) {
            $total = $this->addDecimals($total, $value);
        }

        return $total;
    }

    private function subtractDecimals(string $left, string $right): string
    {
        return $this->addDecimals($left, $this->negateDecimal($right));
    }

    private function addDecimals(string $left, string $right): string
    {
        [$leftSign, $leftDigits] = $this->decimalParts($left);
        [$rightSign, $rightDigits] = $this->decimalParts($right);

        if ($leftSign === $rightSign) {
            return $this->formatDecimal($leftSign, $this->addPositiveIntegers($leftDigits, $rightDigits));
        }

        $comparison = $this->comparePositiveIntegers($leftDigits, $rightDigits);

        if ($comparison === 0) {
            return '0.0000';
        }

        if ($comparison > 0) {
            return $this->formatDecimal($leftSign, $this->subtractPositiveIntegers($leftDigits, $rightDigits));
        }

        return $this->formatDecimal($rightSign, $this->subtractPositiveIntegers($rightDigits, $leftDigits));
    }

    /** @return array{0: int, 1: string} */
    private function decimalParts(string $value): array
    {
        $value = trim($value);
        $negative = str_starts_with($value, '-');

        if ($negative) {
            $value = substr($value, 1);
        }

        if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $value)) {
            throw new RuntimeException("Invalid financial decimal [{$value}].");
        }

        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $digits = ltrim($integer, '0').str_pad($fraction, 4, '0');

        return [$negative ? -1 : 1, ltrim($digits, '0') ?: '0'];
    }

    private function negateDecimal(string $value): string
    {
        return str_starts_with($value, '-') ? ltrim($value, '-') : '-'.$value;
    }

    private function formatDecimal(int $sign, string $digits): string
    {
        $digits = str_pad($digits, 5, '0', STR_PAD_LEFT);
        $integer = ltrim(substr($digits, 0, -4), '0') ?: '0';
        $fraction = substr($digits, -4);

        return ($sign < 0 && $digits !== '0' ? '-' : '').$integer.'.'.$fraction;
    }

    private function addPositiveIntegers(string $left, string $right): string
    {
        $carry = 0;
        $result = '';

        $length = max(strlen($left), strlen($right));

        for ($offset = 0; $offset < $length; $offset++) {
            $leftDigit = (int) ($left[strlen($left) - 1 - $offset] ?? '0');
            $rightDigit = (int) ($right[strlen($right) - 1 - $offset] ?? '0');
            $sum = $carry + $leftDigit + $rightDigit;
            $result = (string) ($sum % 10).$result;
            $carry = intdiv($sum, 10);
        }

        return $carry > 0 ? (string) $carry.$result : $result;
    }

    private function comparePositiveIntegers(string $left, string $right): int
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';

        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }

    private function subtractPositiveIntegers(string $left, string $right): string
    {
        $borrow = 0;
        $result = '';

        for ($offset = 0, $index = strlen($left) - 1; $index >= 0; $offset++, $index--) {
            $leftDigit = (int) $left[$index] - $borrow;
            $rightIndex = strlen($right) - 1 - $offset;
            $rightDigit = $rightIndex >= 0 ? (int) $right[$rightIndex] : 0;

            if ($leftDigit < $rightDigit) {
                $leftDigit += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }

            $result = (string) ($leftDigit - $rightDigit).$result;
        }

        return ltrim($result, '0') ?: '0';
    }

    private function isNegative(string $value): bool
    {
        return str_starts_with($value, '-');
    }
}
