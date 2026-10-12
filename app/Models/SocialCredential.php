<?php

namespace App\Models;

use App\Data\Social\SocialTokenSet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property string $reference
 * @property int $organization_id
 * @property string $provider
 * @property mixed $encrypted_payload
 * @property list<string> $scopes
 * @property \Carbon\CarbonImmutable|null $access_token_expires_at
 * @property \Carbon\CarbonImmutable|null $refresh_token_expires_at
 */
class SocialCredential extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['encrypted_payload'];

    protected function casts(): array
    {
        return [
            'encrypted_payload' => 'encrypted:array',
            'scopes' => 'array',
            'access_token_expires_at' => 'immutable_datetime',
            'refresh_token_expires_at' => 'immutable_datetime',
        ];
    }

    public function tokenSet(): SocialTokenSet
    {
        $payload = $this->encrypted_payload;
        if (! is_array($payload)) {
            throw new LogicException('The encrypted social credential payload is invalid.');
        }

        return SocialTokenSet::fromEncryptedPayload($payload);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}