<?php

namespace Database\Seeders;

use App\Agents\CeoAgent;
use App\Agents\FinanceAgent;
use App\Agents\MarketingAgent;
use App\Agents\OperationsAgent;
use App\Agents\ProductAgent;
use App\Models\AgentDescriptor;
use Illuminate\Database\Seeder;

class AgentDescriptorSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'ceo' => CeoAgent::class,
            'marketing' => MarketingAgent::class,
            'finance' => FinanceAgent::class,
            'product' => ProductAgent::class,
            'operations' => OperationsAgent::class,
        ] as $slug => $runtimeClass) {
            AgentDescriptor::query()->updateOrCreate(
                ['slug' => $slug],
                ['runtime_class' => $runtimeClass, 'enabled' => true],
            );
        }
    }
}