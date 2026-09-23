<?php

namespace App\Models;

use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

#[Fillable([
    'enterprise_id', 'customer_id', 'partner_id', 'invoice_number', 'issue_date',
    'due_date', 'total', 'currency', 'status', 'counterparty_name_snapshot',
    'counterparty_email_snapshot',
])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_OVERDUE = 'overdue';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    private const HISTORICAL_FIELDS = [
        'enterprise_id', 'customer_id', 'partner_id', 'invoice_number', 'issue_date',
        'due_date', 'total', 'currency', 'counterparty_name_snapshot',
        'counterparty_email_snapshot',
    ];

    protected static function booted(): void
    {
        static::saving(function (Invoice $invoice): void {
            if (! in_array($invoice->status, [
                self::STATUS_DRAFT, self::STATUS_ISSUED, self::STATUS_OVERDUE, self::STATUS_CANCELLED,
            ], true)) {
                throw new LogicException("Invalid invoice status [{$invoice->status}].");
            }

            if (! preg_match('/^[A-Z]{3}$/', $invoice->currency)) {
                throw new LogicException('Invoice currency must be a three-letter uppercase code.');
            }

            if ($invoice->total < 0) {
                throw new LogicException('Invoice total cannot be negative.');
            }

            $dueDate = $invoice->getAttribute('due_date');
            $issueDate = $invoice->getAttribute('issue_date');

            if ($dueDate !== null && $issueDate !== null && Carbon::parse($dueDate)->lt(Carbon::parse($issueDate))) {
                throw new LogicException('Invoice due date cannot precede its issue date.');
            }

            $invoice->validateScope();

            if (! $invoice->exists) {
                $invoice->snapshotCounterparty();

                return;
            }

            foreach (self::HISTORICAL_FIELDS as $field) {
                $invoice->{$field} = $invoice->getRawOriginal($field);
            }
        });
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['issue_date' => 'date', 'due_date' => 'date', 'total' => 'decimal:4'];
    }

    private function snapshotCounterparty(): void
    {
        if ($this->customer_id !== null) {
            $customer = Customer::query()->find($this->customer_id);
            if ($customer === null || (int) $customer->enterprise_id !== (int) $this->enterprise_id) {
                throw new LogicException('Invoice customer must belong to its enterprise.');
            }
            $this->counterparty_name_snapshot = $customer->name;
            $this->counterparty_email_snapshot = $customer->email;
        } elseif ($this->partner_id !== null) {
            $partner = Partner::query()->find($this->partner_id);
            if ($partner === null || (int) $partner->enterprise_id !== (int) $this->enterprise_id) {
                throw new LogicException('Invoice partner must belong to its enterprise.');
            }
            $this->counterparty_name_snapshot = $partner->name;
            $this->counterparty_email_snapshot = $partner->email;
        }
    }

    private function validateScope(): void
    {
        if (! Enterprise::query()->whereKey($this->enterprise_id)->exists()) {
            return;
        }

        if ($this->customer_id !== null && Customer::query()->whereKey($this->customer_id)->value('enterprise_id') !== $this->enterprise_id) {
            throw new LogicException('Invoice customer must belong to its enterprise.');
        }

        if ($this->partner_id !== null && Partner::query()->whereKey($this->partner_id)->value('enterprise_id') !== $this->enterprise_id) {
            throw new LogicException('Invoice partner must belong to its enterprise.');
        }

        if ($this->customer_id !== null && $this->partner_id !== null) {
            throw new LogicException('Invoice cannot reference both a customer and a partner.');
        }
    }
}