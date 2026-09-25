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

