<?php

namespace App\Models;

use Database\Factories\EnterpriseDecisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'enterprise_id',
    'actor_id',
    'actor_name',
    'title',
    'summary',
    'rationale',
    'decided_at',
])]
class EnterpriseDecision extends Model
{
    /** @use HasFactory<EnterpriseDecisionFactory> */
    use HasFactory;

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }
}