<?php

use App\Models\Customer;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

it('creates a customer belonging to an enterprise', function () {
    $enterprise = Enterprise::factory()->create();
    $customer = Customer::factory()->create([
        'enterprise_id' => $enterprise,
        'name' => 'Acme Customer',
        'email' => 'customer@example.com',
    ]);

    expect($customer->enterprise->is($enterprise))->toBeTrue()
        ->and($enterprise->customers->contains($customer))->toBeTrue()
        ->and($customer->status)->toBe('active');
});

it('keeps customer ownership when unrelated attributes change', function () {
    $enterprise = Enterprise::factory()->create();
    $customer = Customer::factory()->create(['enterprise_id' => $enterprise]);

    $customer->update(['name' => 'Updated Customer', 'phone' => '+40 700 000 000']);

    expect($customer->refresh()->enterprise->is($enterprise))->toBeTrue();
});

it('rejects duplicate customer emails within an enterprise', function () {
    $enterprise = Enterprise::factory()->create();
    Customer::factory()->create(['enterprise_id' => $enterprise, 'email' => 'customer@example.com']);

    expect(fn () => Customer::factory()->create([
        'enterprise_id' => $enterprise,
        'email' => 'customer@example.com',
    ]))->toThrow(QueryException::class);
});

it('allows the same customer email in different enterprises', function () {
    $enterprises = Enterprise::factory()->count(2)->create();

    Customer::factory()->create(['enterprise_id' => $enterprises[0], 'email' => 'customer@example.com']);
    Customer::factory()->create(['enterprise_id' => $enterprises[1], 'email' => 'customer@example.com']);

    expect(Customer::query()->where('email', 'customer@example.com')->count())->toBe(2);
});

it('enforces customer authorization through the enterprise organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();

    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);
    Membership::factory()->create(['user_id' => $member, 'organization_id' => $organization]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $customer = Customer::factory()->create(['enterprise_id' => $enterprise]);
    $foreignCustomer = Customer::factory()->create([
        'enterprise_id' => Enterprise::factory()->create(['organization_id' => $otherOrganization]),
    ]);

    expect(Gate::forUser($owner)->allows('view', $customer))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $customer))->toBeTrue()
        ->and(Gate::forUser($member)->allows('view', $customer))->toBeTrue()
        ->and(Gate::forUser($member)->allows('update', $customer))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('view', $foreignCustomer))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('create', [Customer::class, $enterprise]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('create', [Customer::class, $enterprise]))->toBeFalse();
});

it('keeps the customer schema limited to phase 1 fields', function () {
    expect(Schema::getColumnListing('customers'))->toBe([
        'id', 'enterprise_id', 'name', 'email', 'phone', 'status', 'created_at', 'updated_at',
    ]);
});