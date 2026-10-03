<?php

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

function discoveredResourceClasses(): array
{
    $classes = [];

    foreach (glob(app_path('Filament/Resources/*/*Resource.php')) as $file) {
        $directory = basename(dirname($file));
        $class = basename($file, '.php');
        $classes[] = "App\\Filament\\Resources\\{$directory}\\{$class}";
    }

    return $classes;
}

it('keeps resource visibility and create availability equal to the authoritative policy', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $owner,
        'organization_id' => $organization,
    ]);

    Membership::factory()->create([
        'user_id' => $member,
        'organization_id' => $organization,
    ]);

    foreach (discoveredResourceClasses() as $resourceClass) {
        $modelClass = $resourceClass::getModel();

        foreach ([$owner, $member] as $user) {
            $this->actingAs($user);

            $policyViewAny = Gate::forUser($user)->allows('viewAny', $modelClass);
            $resourceViewAny = $resourceClass::canViewAny();
            expect($resourceViewAny === $policyViewAny)->toBeTrue();

            $policyCreate = Gate::forUser($user)->allows('create', $modelClass);
            $resourceCreate = $resourceClass::canCreate();
            if ($resourceCreate !== $policyCreate) {
                throw new RuntimeException("Create parity mismatch for {$resourceClass}: resource=".var_export($resourceCreate, true).', policy='.var_export($policyCreate, true));
            }
        }
    }
});

it('does not permit resource authorization overrides that bypass Gate', function () {
    foreach (discoveredResourceClasses() as $resourceClass) {
        $reflection = new ReflectionClass($resourceClass);

        foreach (['canViewAny', 'canCreate', 'canEdit', 'canDelete'] as $method) {
            if (! $reflection->hasMethod($method)) {
                continue;
            }

            $declaringClass = $reflection->getMethod($method)->getDeclaringClass()->getName();

            if ($declaringClass !== $resourceClass) {
                continue;
            }

            $source = file_get_contents($reflection->getMethod($method)->getFileName());

            expect($source)
                ->toContain('Gate::allows(');
        }
    }
});
