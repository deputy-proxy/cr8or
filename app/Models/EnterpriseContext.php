<?php

namespace App\Models;

use Database\Factories\EnterpriseContextFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'enterprise_id',
    'description',
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
        ];
    }
}