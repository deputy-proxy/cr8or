<?php

namespace Database\Seeders;

use App\Experts\BusinessAnalysisExpert;
use App\Experts\CopywritingExpert;
use App\Experts\FinanceExpert;
use App\Experts\MarketingExpert;
use App\Experts\OperationsExpert;
use App\Experts\ProductExpert;
use App\Experts\SeoExpert;
use App\Experts\StrategyExpert;
use App\Models\ExpertDescriptor;
use Illuminate\Database\Seeder;

class ExpertDescriptorSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'business-analysis' => BusinessAnalysisExpert::class,
            'copywriting' => CopywritingExpert::class,
            'finance' => FinanceExpert::class,
            'marketing' => MarketingExpert::class,
            'operations' => OperationsExpert::class,
            'product' => ProductExpert::class,
            'seo' => SeoExpert::class,
            'strategy' => StrategyExpert::class,
        ] as $slug => $runtimeClass) {
            ExpertDescriptor::query()->updateOrCreate(
                ['slug' => $slug],
                ['runtime_class' => $runtimeClass, 'enabled' => true],
            );
        }
    }
}