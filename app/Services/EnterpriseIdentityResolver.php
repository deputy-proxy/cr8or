<?php

namespace App\Services;

use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class EnterpriseIdentityResolver
{
    public function resolve(User $actor, ?int $id = null, ?string $slug = null): Enterprise
    {
        if ($id === null && $slug === null) {
            throw new InvalidArgumentException('Enterprise identity requires an id or slug.');
        }

        $organizationIds = $actor->memberships()->pluck('organization_id');
        $query = Enterprise::query()->whereIn('organization_id', $organizationIds);

        $normalizedSlug = $slug === null ? null : $this->normalizeSlug($slug);

        if ($id !== null) {
            $enterprise = (clone $query)->whereKey($id)->first();

            if ($enterprise === null) {
                throw (new ModelNotFoundException)->setModel(Enterprise::class, [$id]);
            }

            if ($normalizedSlug !== null && $enterprise->slug !== $normalizedSlug) {
                throw new InvalidArgumentException(sprintf(
                    'Enterprise identity mismatch: id [%d] resolves to slug [%s], not [%s].',
                    $id,
                    $enterprise->slug,
                    trim($slug),
                ));
            }

            return $enterprise;
        }

        $enterprises = $query
            ->where('slug', $normalizedSlug)
            ->orderBy('id')
            ->get();

        if ($enterprises->isEmpty()) {
            throw (new ModelNotFoundException)->setModel(Enterprise::class, [$normalizedSlug]);
        }

        if ($enterprises->count() > 1) {
            throw new InvalidArgumentException(sprintf(
                'Enterprise slug [%s] is ambiguous across the actor\'s organizations.',
                $normalizedSlug,
            ));
        }

        return $enterprises->first();
    }

    public function normalizeSlug(string $value): string
    {
        $normalized = Str::slug(str_replace('.', ' ', trim($value)));

        if ($normalized === '') {
            throw new InvalidArgumentException('Enterprise slug cannot be empty.');
        }

        return $normalized;
    }
}