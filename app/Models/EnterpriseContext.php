<?php

namespace App\Models;

use Database\Factories\EnterpriseContextFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'enterprise_id',
    'description',
    'mission',
    'vision',
    'values',
    'industry',
    'business_model',
    'target_market',
    'geography',
    'additional_context',
])]
class EnterpriseContext extends Model
{
    /** @use HasFactory<EnterpriseContextFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $context): void {
            $values = $context->getAttribute('values') ?? [];

            if (! is_array($values) || ! array_is_list($values)) {
                throw new LogicException('Enterprise values must be a list.');
            }

            foreach ($values as $value) {
                if (! is_array($value)
                    || ! is_string($value['name'] ?? null)
                    || trim($value['name']) === ''
                    || mb_strlen($value['name']) > 120
                    || array_diff(array_keys($value), ['name', 'description']) !== []) {
                    throw new LogicException('Each enterprise value must contain a non-empty name and an optional description.');
                }

                $description = $value['description'] ?? null;

                if ($description !== null && (! is_string($description) || mb_strlen($description) > 1000)) {
                    throw new LogicException('Enterprise value descriptions must be text up to 1000 characters.');
                }
            }

            $context->setAttribute('values', $values);
        });
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'additional_context' => 'array',
            'values' => 'array',
        ];
    }
}
