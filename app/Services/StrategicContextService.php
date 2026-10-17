<?php

namespace App\Services;

use App\Models\Competitor;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class StrategicContextService
{
    /**
     * @param  list<string>  $strengths
     * @param  list<string>  $weaknesses
     */
    public function createCompetitor(
        User $actor,
        Enterprise $enterprise,
        string $name,
        ?string $website = null,
        ?string $positioning = null,
        array $strengths = [],
        array $weaknesses = [],
    ): Competitor {
        Gate::forUser($actor)->authorize('createForEnterprise', [Competitor::class, $enterprise]);

        $name = trim($name);
        $website = $website === null || trim($website) === '' ? null : trim($website);
        $positioning = $positioning === null || trim($positioning) === '' ? null : trim($positioning);
        $validated = validator([
            'name' => $name,
            'website' => $website,
            'positioning' => $positioning,
            'strengths' => $strengths,
            'weaknesses' => $weaknesses,
        ], [
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'positioning' => ['nullable', 'string', 'max:10000'],
            'strengths' => ['array', 'list', 'max:50'],
            'strengths.*' => ['required', 'string', 'min:1', 'max:500'],
            'weaknesses' => ['array', 'list', 'max:50'],
            'weaknesses.*' => ['required', 'string', 'min:1', 'max:500'],
        ])->validate();
        $name = $validated['name'];
        $website = $validated['website'] ?? null;
        $positioning = $validated['positioning'] ?? null;
        $strengths = $validated['strengths'] ?? [];
        $weaknesses = $validated['weaknesses'] ?? [];

        return DB::transaction(function () use ($enterprise, $name, $website, $positioning, $strengths, $weaknesses): Competitor {
            // Serialize version allocation for this enterprise so concurrent creates cannot pick the same version.
            $lockedEnterprise = Enterprise::query()->whereKey($enterprise->getKey())->lockForUpdate()->firstOrFail();
            $current = $lockedEnterprise->competitors()
                ->where('name', $name)
                ->where('is_current', true)
                ->latest('version')
                ->first();

            $version = ((int) $lockedEnterprise->competitors()->where('name', $name)->max('version')) + 1;
            $now = Carbon::now();

            if ($current !== null) {
                $current->is_current = false;
                $current->status = Competitor::STATUS_ARCHIVED;
                $current->effective_to = $now;
                $current->save();
            }

            return Competitor::query()->create([
                'enterprise_id' => $lockedEnterprise->getKey(),
                'version' => $version,
                'name' => $name,
                'website' => $website,
                'positioning' => $positioning,
                'strengths' => $strengths,
                'weaknesses' => $weaknesses,
                'status' => Competitor::STATUS_ACTIVE,
                'effective_from' => $now,
                'is_current' => true,
                'supersedes_id' => $current?->getKey(),
            ]);
        });
    }

    /** @return array<string, mixed> */
    public function context(User $actor, Enterprise $enterprise): array
    {
        Gate::forUser($actor)->authorize('view', $enterprise);

        return [
            'competitors' => $enterprise->competitors()
                ->where('is_current', true)
                ->orderBy('name')
                ->get()
                ->map(fn (Competitor $competitor) => $this->serializeRecord($competitor))
                ->all(),
        ];
    }

    private function timestamp(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse((string) $value)->toIso8601String();
    }

    /** @return array<string, mixed>|null */
    private function serializeRecord(?Competitor $record): ?array
    {
        if ($record === null) {
            return null;
        }

        return [
            'id' => $record->getKey(),
            'version' => $record->version,
            'status' => $record->status,
            'effective_from' => $this->timestamp($record->effective_from),
            'effective_to' => $this->timestamp($record->effective_to),
            'name' => $record->name,
            'website' => $record->website,
            'positioning' => $record->positioning,
            'strengths' => $record->strengths ?? [],
            'weaknesses' => $record->weaknesses ?? [],
        ];
    }
}