<?php

use Filament\Actions\CreateAction;

it('registers a create action for every human CRUD resource with a create page', function () {
    $listPages = [
        \App\Filament\Resources\Organizations\Pages\ListOrganizations::class,
        \App\Filament\Resources\Enterprises\Pages\ListEnterprises::class,
        \App\Filament\Resources\Memberships\Pages\ListMemberships::class,
        \App\Filament\Resources\EnterpriseContexts\Pages\ListEnterpriseContexts::class,
        \App\Filament\Resources\Customers\Pages\ListCustomers::class,
        \App\Filament\Resources\Partners\Pages\ListPartners::class,
        \App\Filament\Resources\Products\Pages\ListProducts::class,
        \App\Filament\Resources\Plans\Pages\ListPlans::class,
        \App\Filament\Resources\Goals\Pages\ListGoals::class,
        \App\Filament\Resources\Kpis\Pages\ListKpis::class,
        \App\Filament\Resources\Objectives\Pages\ListObjectives::class,
        \App\Filament\Resources\Strategies\Pages\ListStrategies::class,
        \App\Filament\Resources\Initiatives\Pages\ListInitiatives::class,
        \App\Filament\Resources\Projects\Pages\ListProjects::class,
        \App\Filament\Resources\Tasks\Pages\ListTasks::class,
        \App\Filament\Resources\WorkItems\Pages\ListWorkItems::class,
        \App\Filament\Resources\Assignments\Pages\ListAssignments::class,
        \App\Filament\Resources\Dependencies\Pages\ListDependencies::class,
        \App\Filament\Resources\Milestones\Pages\ListMilestones::class,
        \App\Filament\Resources\KnowledgeContexts\Pages\ListKnowledgeContexts::class,
        \App\Filament\Resources\KnowledgeDocuments\Pages\ListKnowledgeDocuments::class,
        \App\Filament\Resources\KnowledgeItems\Pages\ListKnowledgeItems::class,
        \App\Filament\Resources\KnowledgeReferences\Pages\ListKnowledgeReferences::class,
        \App\Filament\Resources\KnowledgeSources\Pages\ListKnowledgeSources::class,
        \App\Filament\Resources\KnowledgeSpecifications\Pages\ListKnowledgeSpecifications::class,
        \App\Filament\Resources\KnowledgeVersions\Pages\ListKnowledgeVersions::class,
        \App\Filament\Resources\Scripts\Pages\ListScripts::class,
        \App\Filament\Resources\Assets\Pages\ListAssets::class,
        \App\Filament\Resources\Audiences\Pages\ListAudiences::class,
        \App\Filament\Resources\Channels\Pages\ListChannels::class,
        \App\Filament\Resources\FinancialAccounts\Pages\ListFinancialAccounts::class,
        \App\Filament\Resources\FinancialPeriods\Pages\ListFinancialPeriods::class,
        \App\Filament\Resources\Budgets\Pages\ListBudgets::class,
        \App\Filament\Resources\Invoices\Pages\ListInvoices::class,
        \App\Filament\Resources\Revenues\Pages\ListRevenues::class,
        \App\Filament\Resources\Expenses\Pages\ListExpenses::class,
        \App\Filament\Resources\TransactionCategories\Pages\ListTransactionCategories::class,
        \App\Filament\Resources\Statements\Pages\ListStatements::class,
        \App\Filament\Resources\StatementEntries\Pages\ListStatementEntries::class,
        \App\Filament\Resources\Transactions\Pages\ListTransactions::class,
    ];

    foreach ($listPages as $listPage) {
        $resource = $listPage::getResource();

        expect($resource::getPages())->toHaveKey('create');

        $page = app($listPage);
        $method = new ReflectionMethod($page, 'getHeaderActions');
        $method->setAccessible(true);
        $actions = $method->invoke($page);

        expect($actions)
            ->toHaveCount(1)
            ->and($actions[0])->toBeInstanceOf(CreateAction::class);
    }
});

it('delegates resource create authorization to the model policy', function () {
    $organization = \App\Models\Organization::factory()->create();
    $owner = \App\Models\User::factory()->create();
    $member = \App\Models\User::factory()->create();

    \App\Models\Membership::factory()->owner()->create([
        'user_id' => $owner->id,
        'organization_id' => $organization->id,
    ]);
    \App\Models\Membership::factory()->create([
        'user_id' => $member->id,
        'organization_id' => $organization->id,
    ]);

    $resources = [
        \App\Filament\Resources\Enterprises\EnterpriseResource::class,
        \App\Filament\Resources\Products\ProductResource::class,
        \App\Filament\Resources\Strategies\StrategyResource::class,
    ];

    $this->actingAs($owner);

    foreach ($resources as $resource) {
        expect($resource::canCreate())->toBeTrue();
        expect(\Illuminate\Support\Facades\Gate::allows('create', $resource::getModel()))->toBeTrue();
    }

    $this->actingAs($member);

    foreach ($resources as $resource) {
        expect($resource::canCreate())->toBeFalse();
        expect(\Illuminate\Support\Facades\Gate::allows('create', $resource::getModel()))->toBeFalse();
    }
});

it('keeps the Enterprise organization field bound to its named relationship', function () {
    $schema = \Filament\Schemas\Schema::make()->model(\App\Models\Enterprise::class);
    $schema = \App\Filament\Resources\Enterprises\EnterpriseResource::form($schema);
    $organization = $schema->getComponents()[0];

    expect($organization)
        ->toBeInstanceOf(\Filament\Forms\Components\Select::class)
        ->and($organization->getRelationshipName())->toBe('organization')
        ->and($organization->getRelationship())
        ->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class)
        ->and($organization->getRelationship()->getRelated())->toBeInstanceOf(\App\Models\Organization::class);
});

it('can mount the Enterprise list create action form for an authorized owner', function () {
    $organization = \App\Models\Organization::factory()->create();
    $owner = \App\Models\User::factory()->create();
    \App\Models\Membership::factory()->owner()->create([
        'user_id' => $owner->id,
        'organization_id' => $organization->id,
    ]);

    $this->actingAs($owner);

    \Livewire\Livewire::test(\App\Filament\Resources\Enterprises\Pages\ListEnterprises::class)
        ->assertStatus(200)
        ->call('mountAction', 'create')
        ->assertStatus(200);
});
