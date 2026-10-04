<?php

namespace App\Services;

use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

        $canonicalSlug = $slug === null ? null : trim($slug);


        if ($id !== null) {
            $enterprise = (clone $query)->whereKey($id)->first();

            if ($enterprise === null) {
                throw (new ModelNotFoundException)->setModel(Enterprise::class, [$id]);
            }

            if ($canonicalSlug !== null && $enterprise->slug !== $canonicalSlug) {
                throw new InvalidArgumentException(sprintf(
                    'Enterprise identity mismatch: id [%d] resolves to slug [%s], not [%s].',
                    $id,
                    $enterprise->slug,
                    $canonicalSlug,
                ));
            }

            return $enterprise;
        }

        $enterprises = $query
            ->where('slug', $canonicalSlug)
            ->orderBy('id')
            ->get();

        if ($enterprises->isEmpty()) {
            throw (new ModelNotFoundException)->setModel(Enterprise::class, [$canonicalSlug]);
        }

        if ($enterprises->count() > 1) {
            throw new InvalidArgumentException(sprintf(
                'Enterprise slug [%s] is ambiguous across the actor\'s organizations.',
                $canonicalSlug,
            ));
        }

        return $enterprises->first();
    }
}
