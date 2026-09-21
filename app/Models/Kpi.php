<?php

namespace App\Models;

use Database\Factories\KpiFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['enterprise_id', 'name', 'definition', 'unit', 'target_value', 'current_value', 'status'])]
class Kpi extends Model
{
    /** @use HasFactory<KpiFactory> */
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
            'target_value' => 'decimal:4',
            'current_value' => 'decimal:4',
        ];
    }
}