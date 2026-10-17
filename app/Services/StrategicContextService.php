<?php

namespace App\Services;

use App\Models\Competitor;
use App\Models\Enterprise;
use App\Models\KnowledgeReference;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class StrategicContextService
{
    /**
     * @param  list<string>  $strengths
     * @param  list<string>  $weaknesses
     * @param  array<string, mixed>  $profile
     */
    public function createCompetitor(
        User $actor,
        Enterprise $enterprise,
        string $name,
        ?string $website = null,
        ?string $positioning = null,
        array $strengths = [],
        array $weaknesses = [],
        array $profile = [],
    ): Competitor {
        Gate::forUser($actor)->authorize('createForEnterprise', [Competitor::class, $enterprise]);
        $validated = $this->validateCompetitorInput($name, $website, $positioning, $strengths, $weaknesses, $enterprise, $profile);

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
     * @param  array<string, mixed>  $profile
     */
    public function reviseCompetitor(
        User $actor,
        Competitor $record,
        string $name,
        ?string $website = null,
        ?string $positioning = null,
        array $strengths = [],
        array $weaknesses = [],
        array $profile = [],
    ): Competitor {
        Gate::forUser($actor)->authorize('update', $record);
        $enterprise = Enterprise::query()->findOrFail($record->enterprise_id);
        $validated = $this->validateCompetitorInput($name, $website, $positioning, $strengths, $weaknesses, $enterprise, $profile);

        return DB::transaction(function () use ($actor, $record, $validated): Competitor {
            $enterprise = Enterprise::query()->whereKey($record->enterprise_id)->lockForUpdate()->firstOrFail();
            $current = Competitor::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('update', $current);

            if (! $current->is_current || $current->status !== Competitor::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['competitor' => 'Only the current active competitor version can be revised.']);
            }

            $conflict = $enterprise->competitors()
                ->where('name', $validated['name'])
                ->where('is_current', true)
                ->where('id', '!=', $current->getKey())
                ->exists();

            if ($conflict) {
                throw ValidationException::withMessages([
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
                throw ValidationException::withMessages(['competitor' => 'Only the current active competitor version can be archived.']);
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
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function validateCompetitorInput(
        string $name,
        ?string $website,
        ?string $positioning,
        array $strengths,
        array $weaknesses,
        Enterprise $enterprise,
        array $profile,
    ): array {
        $unknownFields = array_diff(array_keys($profile), Competitor::PROFILE_FIELDS);

        if ($unknownFields !== []) {
            throw ValidationException::withMessages(['profile' => 'Unsupported competitor profile fields: '.implode(', ', $unknownFields).'.']);
        }

        $input = [
            'name' => trim($name),
            'website' => $website === null || trim($website) === '' ? null : trim($website),
            'positioning' => $positioning === null || trim($positioning) === '' ? null : trim($positioning),
            'strengths' => $strengths,
            'weaknesses' => $weaknesses,
        ];
        $rules = [
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'positioning' => ['nullable', 'string', 'max:10000'],
            'strengths' => ['array', 'list', 'max:50'],
            'strengths.*' => ['required', 'string', 'min:1', 'max:500'],
            'weaknesses' => ['array', 'list', 'max:50'],
            'weaknesses.*' => ['required', 'string', 'min:1', 'max:500'],
        ];

        $blockContracts = [
            'products' => [
                'keys' => 'name,description,category,features,target_segment,differentiators,pricing_reference',
                'rules' => [
                    'name' => ['required', 'string', 'min:1', 'max:255'],
                    'description' => ['nullable', 'string', 'max:5000'],
                    'category' => ['nullable', 'string', 'max:255'],
                    'features' => ['sometimes', 'nullable', 'array', 'list', 'max:50'],
                    'features.*' => ['required', 'string', 'min:1', 'max:500'],
                    'target_segment' => ['nullable', 'string', 'max:500'],
                    'differentiators' => ['sometimes', 'nullable', 'array', 'list', 'max:50'],
                    'differentiators.*' => ['required', 'string', 'min:1', 'max:500'],
                    'pricing_reference' => ['nullable', 'string', 'max:255'],
                ],
            ],
            'target_segments' => [
                'keys' => 'name,description,industry,demographics,needs,use_cases',
                'rules' => [
                    'name' => ['required', 'string', 'min:1', 'max:255'],
                    'description' => ['nullable', 'string', 'max:5000'],
                    'industry' => ['nullable', 'string', 'max:255'],
                    'demographics' => ['nullable', 'string', 'max:2000'],
                    'needs' => ['sometimes', 'nullable', 'array', 'list', 'max:50'],
                    'needs.*' => ['required', 'string', 'min:1', 'max:500'],
                    'use_cases' => ['sometimes', 'nullable', 'array', 'list', 'max:50'],
                    'use_cases.*' => ['required', 'string', 'min:1', 'max:500'],
                ],
            ],
            'pricing' => [
                'keys' => 'product_name,model,amount,minimum_amount,maximum_amount,currency,billing_interval,terms,package',
                'rules' => [
                    'product_name' => ['nullable', 'string', 'max:255'],
                    'model' => ['required', 'string', 'min:1', 'max:100'],
                    'amount' => ['nullable', 'numeric', 'min:0'],
                    'minimum_amount' => ['nullable', 'numeric', 'min:0'],
                    'maximum_amount' => ['nullable', 'numeric', 'min:0'],
                    'currency' => ['nullable', 'string', 'size:3'],
                    'billing_interval' => ['nullable', 'string', 'max:100'],
                    'terms' => ['nullable', 'string', 'max:2000'],
                    'package' => ['nullable', 'string', 'max:255'],
                ],
            ],
            'markets' => [
                'keys' => 'geography,industries,languages,notes',
                'rules' => [
                    'geography' => ['required', 'string', 'min:1', 'max:255'],
                    'industries' => ['sometimes', 'nullable', 'array', 'list', 'max:50'],
                    'industries.*' => ['required', 'string', 'min:1', 'max:255'],
                    'languages' => ['sometimes', 'nullable', 'array', 'list', 'max:50'],
                    'languages.*' => ['required', 'string', 'min:1', 'max:100'],
                    'notes' => ['nullable', 'string', 'max:2000'],
                ],
            ],
            'differentiators' => [
                'keys' => 'claim,claim_type,category',
                'rules' => [
                    'claim' => ['required', 'string', 'min:1', 'max:2000'],
                    'claim_type' => ['required', 'string', 'in:competitor_claim,observed_fact,verified_fact'],
                    'category' => ['nullable', 'string', 'max:255'],
                ],
            ],
            'channels' => [
                'keys' => 'channel_type,name,details',
                'rules' => [
                    'channel_type' => ['required', 'string', 'in:sales,distribution,acquisition,social,other'],
                    'name' => ['required', 'string', 'min:1', 'max:255'],
                    'details' => ['nullable', 'string', 'max:2000'],
                ],
            ],
            'reputation' => [
                'keys' => 'platform,rating,rating_scale,review_count,sentiment,summary,observed_at',
                'rules' => [
                    'platform' => ['required', 'string', 'min:1', 'max:255'],
                    'rating' => ['nullable', 'numeric', 'min:0'],
                    'rating_scale' => ['nullable', 'string', 'max:100'],
                    'review_count' => ['nullable', 'integer', 'min:0'],
                    'sentiment' => ['required', 'string', 'in:positive,negative,mixed,unknown'],
                    'summary' => ['nullable', 'string', 'max:2000'],
                    'observed_at' => ['nullable', 'date_format:Y-m-d'],
                ],
            ],
            'digital_presence' => [
                'keys' => 'channel,url,observation,observed_at',
                'rules' => [
                    'channel' => ['required', 'string', 'min:1', 'max:255'],
                    'url' => ['nullable', 'url', 'max:2048'],
                    'observation' => ['required', 'string', 'min:1', 'max:2000'],
                    'observed_at' => ['nullable', 'date_format:Y-m-d'],
                ],
            ],
            'source_references' => [
                'keys' => 'knowledge_reference_id,field,claim,observed_at',
                'rules' => [
                    'knowledge_reference_id' => ['required', 'integer', 'min:1', 'exists:knowledge_references,id'],
                    'field' => ['nullable', 'string', 'in:name,website,positioning,strengths,weaknesses,products,target_segments,pricing,markets,differentiators,channels,reputation,digital_presence', 'max:100'],
                    'claim' => ['nullable', 'string', 'max:1000'],
                    'observed_at' => ['nullable', 'date_format:Y-m-d'],
                ],
            ],
        ];

        foreach (Competitor::PROFILE_FIELDS as $field) {
            $input[$field] = $profile[$field] ?? [];
            $rules[$field] = ['array', 'list', 'max:50'];
            $rules[$field.'.*'] = ['required', 'array:'.$blockContracts[$field]['keys']];

            foreach ($blockContracts[$field]['rules'] as $key => $fieldRules) {
                $rules[$field.'.*.'.$key] = $fieldRules;
            }
        }

        $validated = Validator::make($input, $rules)->validate();

        $listFields = [
            'products' => ['features', 'differentiators'],
            'target_segments' => ['needs', 'use_cases'],
            'markets' => ['industries', 'languages'],
        ];
        foreach ($listFields as $field => $keys) {
            foreach ($validated[$field] as $index => $entry) {
                foreach ($keys as $key) {
                    $validated[$field][$index][$key] = $entry[$key] ?? [];
                }
            }
        }

        foreach (Competitor::PROFILE_FIELDS as $field) {
            foreach ($validated[$field] as $index => $entry) {
                $validated[$field][$index] = array_filter($entry, static fn (mixed $value): bool => $value !== null);
            }
        }

        foreach ($validated['pricing'] as $index => $entry) {
            if (isset($entry['minimum_amount'], $entry['maximum_amount']) && (float) $entry['minimum_amount'] > (float) $entry['maximum_amount']) {
                throw ValidationException::withMessages(["pricing.{$index}.maximum_amount" => 'The maximum amount must be greater than or equal to the minimum amount.']);
            }
        }

        $referenceIds = [];
        foreach ($validated['source_references'] as $entry) {
            if (is_array($entry) && isset($entry['knowledge_reference_id'])) {
                $referenceIds[] = (int) $entry['knowledge_reference_id'];
            }
        }
        $referenceIds = array_values(array_unique($referenceIds));

        if ($referenceIds !== []) {
            $validReferenceCount = KnowledgeReference::query()
                ->whereIn('id', $referenceIds)
                ->where('enterprise_id', $enterprise->getKey())
                ->whereHas('item', function (Builder $query) use ($enterprise): void {
                    $query->where('enterprise_id', $enterprise->getKey())
                        ->where(function (Builder $sourceScope) use ($enterprise): void {
                            $sourceScope->whereNull('knowledge_source_id')
                                ->orWhereHas('source', fn (Builder $source): Builder => $source->where('enterprise_id', $enterprise->getKey()));
                        });
                })
                ->where(function (Builder $query) use ($enterprise): void {
                    $query->whereNull('knowledge_document_id')
                        ->orWhereHas('document', function (Builder $document) use ($enterprise): void {
                            $document->where('enterprise_id', $enterprise->getKey())
                                ->where(function (Builder $sourceScope) use ($enterprise): void {
                                    $sourceScope->whereNull('knowledge_source_id')
                                        ->orWhereHas('source', fn (Builder $source): Builder => $source->where('enterprise_id', $enterprise->getKey()));
                                });
                        });
                })
                ->count();

            if ($validReferenceCount !== count($referenceIds)) {
                throw ValidationException::withMessages(['source_references' => 'Every evidence reference must belong to the same Enterprise as the competitor.']);
            }
        }

        $result = [
            'name' => $validated['name'],
            'website' => $validated['website'] ?? null,
            'positioning' => $validated['positioning'] ?? null,
            'strengths' => $validated['strengths'] ?? [],
            'weaknesses' => $validated['weaknesses'] ?? [],
        ];

        foreach (Competitor::PROFILE_FIELDS as $field) {
            $result[$field] = $validated[$field] ?? [];
        }

        return $result;
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

        $serialized = [
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

        foreach (Competitor::PROFILE_FIELDS as $field) {
            $serialized[$field] = $record->{$field} ?? [];
        }

        return $serialized;
    }
}
