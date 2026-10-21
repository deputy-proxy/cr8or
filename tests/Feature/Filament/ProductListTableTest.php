<?php

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->organization = Organization::factory()->create();
    $this->user = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->organization->id,
    ]);
    $this->enterprise = Enterprise::factory()->create([
        'organization_id' => $this->organization->id,
    ]);
    $this->actingAs($this->user);
});

it('filters products by type and status in the grouped list', function (): void {
    $course = Product::factory()->create([
        'enterprise_id' => $this->enterprise->id,
        'product_type' => 'course',
        'status' => 'active',
    ]);
    $service = Product::factory()->create([
        'enterprise_id' => $this->enterprise->id,
        'product_type' => 'service',
        'status' => 'archived',
    ]);

    Livewire::test(ListProducts::class)
        ->assertCanSeeTableRecords([$course, $service])
        ->filterTable('product_type', 'course')
        ->assertCanSeeTableRecords([$course])
        ->assertCanNotSeeTableRecords([$service])
        ->removeTableFilter('product_type')
        ->filterTable('status', 'archived')
        ->assertCanSeeTableRecords([$service])
        ->assertCanNotSeeTableRecords([$course]);
});

it('classifies free, monetized, and unconfigured pricing independently of lifecycle status', function (): void {
    $free = Product::factory()->create([
        'enterprise_id' => $this->enterprise->id,
        'pricing_model' => 'free',
        'monetization_model' => 'free',
    ]);
    $paid = Product::factory()->create([
        'enterprise_id' => $this->enterprise->id,
        'pricing_model' => 'fixed',
        'monetization_model' => 'one_time_purchase',
        'price_amount' => 25,
        'currency' => 'EUR',
        'status' => 'draft',
    ]);
    $unconfigured = Product::factory()->create([
        'enterprise_id' => $this->enterprise->id,
        'pricing_model' => 'other',
        'monetization_model' => 'other',
        'price_amount' => null,
        'currency' => null,
    ]);

    Livewire::test(ListProducts::class)
        ->filterTable('pricing', 'free')
        ->assertCanSeeTableRecords([$free])
        ->assertCanNotSeeTableRecords([$paid, $unconfigured])
        ->removeTableFilter('pricing')
        ->filterTable('pricing', 'paid')
        ->assertCanSeeTableRecords([$paid])
        ->assertCanNotSeeTableRecords([$free, $unconfigured])
        ->removeTableFilter('pricing')
        ->filterTable('pricing', 'unconfigured')
        ->assertCanSeeTableRecords([$unconfigured])
        ->assertCanNotSeeTableRecords([$free, $paid]);
});
