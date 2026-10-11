<?php

use Filament\Actions\CreateAction;

it('registers a create action for every resource with a create page', function () {
    $listPageFiles = glob(app_path('Filament/Resources/*/Pages/List*.php')) ?: [];

    foreach ($listPageFiles as $file) {
        $contents = file_get_contents($file);
        preg_match('/namespace\s+([^;]+);/', $contents, $namespaceMatch);
        preg_match('/class\s+(\w+)/', $contents, $classMatch);

        expect($namespaceMatch)->not->toBeEmpty();
        expect($classMatch)->not->toBeEmpty();

        $listPage = $namespaceMatch[1].chr(92).$classMatch[1];
        $resource = $listPage::getResource();

        if (! array_key_exists('create', $resource::getPages())) {
            continue;
        }

        $page = app($listPage);
        $method = new ReflectionMethod($page, 'getHeaderActions');
        $method->setAccessible(true);
        $actions = $method->invoke($page);

        expect(collect($actions)->contains(fn ($action): bool => $action instanceof CreateAction))
            ->toBeTrue("{$listPage} must register a CreateAction when its resource exposes a create page.");
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
        \App\Filament\Resources\EnterpriseContexts\EnterpriseContextResource::class,
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