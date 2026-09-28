<?php

namespace App\Services;

use App\Models\Competitor;
use App\Models\Enterprise;
use App\Models\Mission;
use App\Models\User;
use App\Models\Vision;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class StrategicContextService
{
    public function createVision(User $actor, Enterprise $enterprise, string $statement): Vision
    {
        Gate::forUser($actor)->authorize('update', $enterprise);

        return DB::transaction(function () use ($enterprise, $statement): Vision {
            $current = $enterprise->visions()->where('is_current', true)->latest('version')->first();
            $version = ((int) $enterprise->visions()->max('version')) + 1;

            if ($current !== null) {
                $current->is_current = false;
                $current->status = Vision::STATUS_ARCHIVED;
                $current->effective_to = Carbon::now();
                $current->save();
            }

            return Vision::query()->create([
                'enterprise_id' => $enterprise->getKey(),
                'version' => $version,
                'statement' => $statement,
                'status' => Vision::STATUS_ACTIVE,
                'effective_from' => Carbon::now(),
                'is_current' => true,
                'supersedes_id' => $current?->getKey(),
            ]);
        });
    }

    public function createMission(User $actor, Enterprise $enterprise, string $statement): Mission
    {
        Gate::forUser($actor)->authorize('update', $enterprise);

        return DB::transaction(function () use ($enterprise, $statement): Mission {
            $current = $enterprise->missions()->where('is_current', true)->latest('version')->first();
            $version = ((int) $enterprise->missions()->max('version')) + 1;

            if ($current !== null) {
                $current->is_current = false;
                $current->status = Mission::STATUS_ARCHIVED;
                $current->effective_to = Carbon::now();
                $current->save();
            }

            return Mission::query()->create([
                'enterprise_id' => $enterprise->getKey(),
                'version' => $version,
                'statement' => $statement,
                'status' => Mission::STATUS_ACTIVE,
                'effective_from' => Carbon::now(),
                'is_current' => true,
                'supersedes_id' => $current?->getKey(),
            ]);
        });
    }

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
            'vision' => $this->serializeRecord($enterprise->visions()->where('is_current', true)->latest('version')->first()),
            'mission' => $this->serializeRecord($enterprise->missions()->where('is_current', true)->latest('version')->first()),
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
    private function serializeRecord(Vision|Mission|Competitor|null $record): ?array
    {
        if ($record === null) {
            return null;
        }

        $data = [
            'id' => $record->getKey(),
            'version' => $record->version,
            'status' => $record->status,
            'effective_from' => $this->timestamp($record->effective_from),
            'effective_to' => $this->timestamp($record->effective_to),
        ];

        if ($record instanceof Vision || $record instanceof Mission) {
            $data['statement'] = $record->statement;
        } else {
            $data += [
                'name' => $record->name,
                'website' => $record->website,
                'positioning' => $record->positioning,
                'strengths' => $record->strengths ?? [],
                'weaknesses' => $record->weaknesses ?? [],
            ];
        }

        return $data;
    }
}