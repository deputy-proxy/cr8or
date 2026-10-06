<?php

use App\Filament\Resources\Missions\MissionResource;
use App\Filament\Resources\Visions\VisionResource;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('keeps strategic record resource visibility aligned with the authoritative policy', function () {
    $organization = Organization::factory()->create();
    $member = User::factory()->create();

    Membership::factory()->create([
        'user_id' => $member,
        'organization_id' => $organization,
    ]);

    $resources = [MissionResource::class, VisionResource::class];

    foreach ($resources as $resource) {
        expect(Gate::forUser($member)->allows('viewAny', $resource::getModel()))->toBeTrue()
            ->and($resource::canViewAny())->toBeTrue();
    }
});

it('hides strategic record resources from users without memberships', function () {
    $user = User::factory()->create();

    foreach ([MissionResource::class, VisionResource::class] as $resource) {
        expect(Gate::forUser($user)->allows('viewAny', $resource::getModel()))->toBeFalse();

        $this->actingAs($user);

        expect($resource::canViewAny())->toBeFalse();
    }
});
