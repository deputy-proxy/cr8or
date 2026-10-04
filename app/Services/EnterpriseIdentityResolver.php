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

        $slugCandidates = $slug === null ? [] : $this->slugCandidates($slug);

        if ($id !== null) {
            $enterprise = (clone $query)->whereKey($id)->first();

            if ($enterprise === null) {
                throw (new ModelNotFoundException)->setModel(Enterprise::class, [$id]);
            }

            if ($slug !== null && ! in_array($enterprise->slug, $slugCandidates, true)) {
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
            ->whereIn('slug', $slugCandidates)
            ->orderByRaw('CASE WHEN slug = ? THEN 0 ELSE 1 END', [trim($slug)])
            ->orderBy('id')
            ->get();

        if ($enterprises->isEmpty()) {
            throw (new ModelNotFoundException)->setModel(Enterprise::class, [$slugCandidates[0]]);
        }

        if ($enterprises->count() > 1) {
            throw new InvalidArgumentException(sprintf(
                'Enterprise slug [%s] is ambiguous across the actor\'s organizations.',
                trim($slug),
            ));
        }

        return $enterprises->first();
    }

    private function slugCandidates(string $value): array
    {
        $trimmed = trim($value);
        $normalized = $this->normalizeSlug($trimmed);

        return array_values(array_unique([$trimmed, $normalized]));
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