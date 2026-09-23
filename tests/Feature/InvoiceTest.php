<?php

use App\Models\Customer;
use App\Models\Enterprise;
use App\Models\Invoice;
use App\Models\Partner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use LogicException;

it('creates an invoice with a customer snapshot', function () {
    $enterprise = Enterprise::factory()->create();
    $customer = Customer::factory()->create(['enterprise_id' => $enterprise, 'name' => 'Original Customer', 'email' => 'original@example.test']);

    $invoice = Invoice::factory()->create([
        'enterprise_id' => $enterprise, 'customer_id' => $customer, 'invoice_number' => 'INV-1001',
        'issue_date' => '2026-09-23', 'due_date' => '2026-10-23', 'total' => '1234.5678',
    ]);

    expect($invoice->enterprise->is($enterprise))->toBeTrue()
        ->and($invoice->customer->is($customer))->toBeTrue()
        ->and($invoice->counterparty_name_snapshot)->toBe('Original Customer')
        ->and($invoice->counterparty_email_snapshot)->toBe('original@example.test')
        ->and($invoice->total)->toBe('1234.5678')
        ->and($invoice->issue_date->equalTo(Carbon::parse('2026-09-23')))->toBeTrue();
});

it('supports a partner instead of a customer', function () {
    $enterprise = Enterprise::factory()->create();
    $partner = Partner::factory()->create(['enterprise_id' => $enterprise, 'name' => 'Original Partner', 'email' => 'partner@example.test']);

    $invoice = Invoice::factory()->partner()->create(['enterprise_id' => $enterprise, 'partner_id' => $partner]);

    expect($invoice->partner->is($partner))->toBeTrue()
        ->and($invoice->customer_id)->toBeNull()
        ->and($invoice->counterparty_name_snapshot)->toBe('Original Partner');
});

it('rejects foreign customer and partner relationships', function () {
    $enterprise = Enterprise::factory()->create();
    expect(fn () => Invoice::factory()->create(['enterprise_id' => $enterprise, 'customer_id' => Customer::factory()->create()]))->toThrow(LogicException::class);
    expect(fn () => Invoice::factory()->create(['enterprise_id' => $enterprise, 'partner_id' => Partner::factory()->create()]))->toThrow(LogicException::class);
});

it('rejects invoices that reference both customer and partner', function () {
    $enterprise = Enterprise::factory()->create();
    expect(fn () => Invoice::factory()->create([
        'enterprise_id' => $enterprise,
        'customer_id' => Customer::factory()->create(['enterprise_id' => $enterprise]),
        'partner_id' => Partner::factory()->create(['enterprise_id' => $enterprise]),
    ]))->toThrow(LogicException::class);
});

it('preserves invoice identity and issue-time facts while allowing lifecycle status changes', function () {
    $invoice = Invoice::factory()->create(['invoice_number' => 'INV-2001', 'issue_date' => '2026-09-01', 'due_date' => '2026-10-01', 'total' => '500.2500', 'currency' => 'EUR']);

    $invoice->update(['invoice_number' => 'INV-CHANGED', 'issue_date' => '2026-09-02', 'due_date' => '2026-10-02', 'total' => '700.0000', 'currency' => 'USD', 'status' => Invoice::STATUS_ISSUED]);
    $invoice->refresh();

    expect($invoice->invoice_number)->toBe('INV-2001')
        ->and($invoice->issue_date->toDateString())->toBe('2026-09-01')
        ->and($invoice->due_date->toDateString())->toBe('2026-10-01')
        ->and($invoice->total)->toBe('500.2500')
        ->and($invoice->currency)->toBe('EUR')
        ->and($invoice->status)->toBe(Invoice::STATUS_ISSUED);
});

it('does not represent payment as an invoice lifecycle status', function () {
    expect([Invoice::STATUS_DRAFT, Invoice::STATUS_ISSUED, Invoice::STATUS_OVERDUE, Invoice::STATUS_CANCELLED])->not->toContain('paid');
});

it('rejects invalid invoice dates, currency and totals', function () {
    $enterprise = Enterprise::factory()->create();
    expect(fn () => Invoice::factory()->create(['enterprise_id' => $enterprise, 'issue_date' => '2026-10-01', 'due_date' => '2026-09-30']))->toThrow(LogicException::class);
    expect(fn () => Invoice::factory()->create(['enterprise_id' => $enterprise, 'currency' => 'euro']))->toThrow(LogicException::class);
    expect(fn () => Invoice::factory()->create(['enterprise_id' => $enterprise, 'total' => '-1.0000']))->toThrow(LogicException::class);
});

it('rejects duplicate invoice numbers within an enterprise', function () {
    $enterprise = Enterprise::factory()->create();
    Invoice::factory()->create(['enterprise_id' => $enterprise, 'invoice_number' => 'INV-3001']);
    expect(fn () => Invoice::factory()->create(['enterprise_id' => $enterprise, 'invoice_number' => 'INV-3001']))->toThrow(QueryException::class);
});

it('keeps the invoice schema focused on current requirements', function () {
    expect(Schema::getColumnListing('invoices'))->toBe([
        'id', 'enterprise_id', 'customer_id', 'partner_id', 'invoice_number', 'issue_date', 'due_date',
        'total', 'currency', 'status', 'counterparty_name_snapshot', 'counterparty_email_snapshot',
        'created_at', 'updated_at',
    ]);
});
