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
        Gate::forUser($actor)->authorize('update', $enterprise);

        return DB::transaction(function () use ($enterprise, $name, $website, $positioning, $strengths, $weaknesses): Competitor {
            $current = $enterprise->competitors()
                ->where('name', $name)
                ->where('is_current', true)
                ->latest('version')
                ->first();

            $version = ((int) $enterprise->competitors()->where('name', $name)->max('version')) + 1;

            if ($current !== null) {
                $current->is_current = false;
                $current->status = Competitor::STATUS_ARCHIVED;
                $current->effective_to = Carbon::now();
                $current->save();
            }

            return Competitor::query()->create([
                'enterprise_id' => $enterprise->getKey(),
                'version' => $version,
                'name' => $name,
                'website' => $website,
                'positioning' => $positioning,
                'strengths' => $strengths,
                'weaknesses' => $weaknesses,
                'status' => Competitor::STATUS_ACTIVE,
                'effective_from' => Carbon::now(),
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