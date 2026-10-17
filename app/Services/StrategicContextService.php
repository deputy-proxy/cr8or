<?php

namespace App\Services;

use App\Models\Competitor;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

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
        $validated = $this->validateCompetitorInput($name, $website, $positioning, $strengths, $weaknesses);

        return DB::transaction(function () use ($enterprise, $validated): Competitor {
            // Locking the Enterprise serializes version allocation across all competitors in that tenant.
            $lockedEnterprise = Enterprise::query()->whereKey($enterprise->getKey())->lockForUpdate()->firstOrFail();
            $current = $lockedEnterprise->competitors()
                ->where('name', $validated['name'])
                ->where('is_current', true)
                ->latest('version')
                ->first();
            $version = ((int) $lockedEnterprise->competitors()->max('version')) + 1;
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
                ...$validated,
                'status' => Competitor::STATUS_ACTIVE,
                'effective_from' => $now,
                'is_current' => true,
                'supersedes_id' => $current?->getKey(),
            ]);
        });
    }

    /**
     * Create a successor version without mutating the historical descriptive fields.
     *
     * @param  list<string>  $strengths
     * @param  list<string>  $weaknesses
     */
    public function reviseCompetitor(
        User $actor,
        Competitor $record,
        string $name,
        ?string $website = null,
        ?string $positioning = null,
        array $strengths = [],
        array $weaknesses = [],
    ): Competitor {
        Gate::forUser($actor)->authorize('update', $record);
        $validated = $this->validateCompetitorInput($name, $website, $positioning, $strengths, $weaknesses);

        return DB::transaction(function () use ($actor, $record, $validated): Competitor {
            $enterprise = Enterprise::query()->whereKey($record->enterprise_id)->lockForUpdate()->firstOrFail();
            $current = Competitor::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('update', $current);

            if (! $current->is_current || $current->status !== Competitor::STATUS_ACTIVE) {
                throw \Illuminate\Validation\ValidationException::withMessages(['competitor' => 'Only the current active competitor version can be revised.']);
            }

            $conflict = $enterprise->competitors()
                ->where('name', $validated['name'])
                ->where('is_current', true)
                ->where('id', '!=', $current->getKey())
                ->exists();

            if ($conflict) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'name' => 'An active competitor with this name already exists for the enterprise.',
                ]);
            }

            $now = Carbon::now();
            $version = ((int) $enterprise->competitors()->max('version')) + 1;

            $current->forceFill([
                'is_current' => false,
                'status' => Competitor::STATUS_ARCHIVED,
                'effective_to' => $now,
            ])->save();

            return Competitor::query()->create([
                'enterprise_id' => $enterprise->getKey(),
                'version' => $version,
                ...$validated,
                'status' => Competitor::STATUS_ACTIVE,
                'effective_from' => $now,
                'is_current' => true,
                'supersedes_id' => $current->getKey(),
            ]);
        });
    }

    public function archiveCompetitor(User $actor, Competitor $record): Competitor
    {
        Gate::forUser($actor)->authorize('archive', $record);

        return DB::transaction(function () use ($actor, $record): Competitor {
            Enterprise::query()->whereKey($record->enterprise_id)->lockForUpdate()->firstOrFail();
            $current = Competitor::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('archive', $current);

            if (! $current->is_current || $current->status !== Competitor::STATUS_ACTIVE) {
                throw \Illuminate\Validation\ValidationException::withMessages(['competitor' => 'Only the current active competitor version can be archived.']);
            }

            $current->forceFill([
                'is_current' => false,
                'status' => Competitor::STATUS_ARCHIVED,
                'effective_to' => Carbon::now(),
            ])->save();

            return $current->refresh();
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

    /**
     * @param  list<string>  $strengths
     * @param  list<string>  $weaknesses
     * @return array{name: string, website: string|null, positioning: string|null, strengths: list<string>, weaknesses: list<string>}
     */
    private function validateCompetitorInput(
        string $name,
        ?string $website,
        ?string $positioning,
        array $strengths,
        array $weaknesses,
    ): array {
        $validated = Validator::make([
            'name' => trim($name),
            'website' => $website === null || trim($website) === '' ? null : trim($website),
            'positioning' => $positioning === null || trim($positioning) === '' ? null : trim($positioning),
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

        return [
            'name' => $validated['name'],
            'website' => $validated['website'] ?? null,
            'positioning' => $validated['positioning'] ?? null,
            'strengths' => $validated['strengths'] ?? [],
            'weaknesses' => $validated['weaknesses'] ?? [],
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
