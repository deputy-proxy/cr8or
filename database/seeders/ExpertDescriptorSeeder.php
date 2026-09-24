<?php

namespace Database\Seeders;

use App\Experts\BusinessAnalysisExpert;
use App\Experts\FinanceExpert;
use App\Experts\MarketingExpert;
use App\Experts\OperationsExpert;
use App\Experts\ProductExpert;
use App\Models\ExpertDescriptor;
use Illuminate\Database\Seeder;

class ExpertDescriptorSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'business-analysis' => BusinessAnalysisExpert::class,
            'marketing' => MarketingExpert::class,
            'finance' => FinanceExpert::class,
            'product' => ProductExpert::class,
            'operations' => OperationsExpert::class,
        ] as $slug => $runtimeClass) {
            ExpertDescriptor::query()->updateOrCreate(
                ['slug' => $slug],
                ['runtime_class' => $runtimeClass, 'enabled' => true],
            );
        }
    }
}
