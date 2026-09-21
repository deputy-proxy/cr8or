<?php

use App\Filament\Resources\Products\ProductResource;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;

it('scopes phase one resources to the authenticated users organizations', function () {
    $org = Organization::factory()->create();
    $other = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $org]);
    $visible = Product::factory()->create(['enterprise_id' => Enterprise::factory()->create(['organization_id' => $org])]);
    $hidden = Product::factory()->create(['enterprise_id' => Enterprise::factory()->create(['organization_id' => $other])]);
    $this->actingAs($user);
    expect(ProductResource::getEloquentQuery()->pluck('id')->all())->toContain($visible->id)->not->toContain($hidden->id);
});
it('allows owners to create enterprise records and members to view them', function () {
    $org = Organization::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $org]);
    Membership::factory()->create(['user_id' => $member, 'organization_id' => $org]);
    $this->actingAs($owner);
    expect(ProductResource::canCreate())->toBeTrue();
    $this->actingAs($member);
    expect(ProductResource::canCreate())->toBeFalse();
});
it('denies users without memberships access to enterprise administration', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/admin/products')->assertForbidden();
});
