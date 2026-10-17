<?php

namespace App\Services;

use App\Models\Competitor;
use App\Models\Enterprise;
use App\Models\KnowledgeReference;
use App\Models\Report;
use App\Models\ReportSnapshot;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CompetitiveAnalysisReportingService
{
    public const REPORT_TYPE = 'competitive_analysis';

    public const METHODOLOGY_VERSION = '1.0';

    /** @param array<mixed> $competitorIds */
    public function generate(User $actor, Enterprise $enterprise, array $competitorIds = []): Report
    {
        Gate::forUser($actor)->authorize('view', $enterprise);

        if (! array_is_list($competitorIds) || count($competitorIds) > 50) {
            throw ValidationException::withMessages(['competitor_ids' => 'Select a list of at most 50 competitor profile versions.']);
        }

        $normalizedIds = [];
        foreach ($competitorIds as $competitorId) {
            if ((! is_int($competitorId) && (! is_string($competitorId) || ! ctype_digit($competitorId))) || (int) $competitorId < 1) {
                throw ValidationException::withMessages(['competitor_ids' => 'Every competitor profile ID must be a positive integer.']);
            }
            $normalizedIds[] = (int) $competitorId;
        }
        $competitorIds = $normalizedIds;

        if (count(array_unique($competitorIds)) !== count($competitorIds)) {
            throw ValidationException::withMessages(['competitor_ids' => 'Select distinct competitor profile versions.']);
        }

        $query = Competitor::query()->where('enterprise_id', $enterprise->getKey());
        $query = $competitorIds === [] ? $query->where('is_current', true) : $query->whereIn('id', $competitorIds);
        $competitors = $query->orderBy('name')->orderBy('version')->get();

        if ($competitorIds !== [] && $competitors->count() !== count($competitorIds)) {
            throw new AuthorizationException('One or more competitor profiles are outside the requested Enterprise.');
        }

        if ($competitors->isEmpty()) {
            throw ValidationException::withMessages(['competitor_ids' => 'At least one accessible competitor profile version is required.']);
        }

        return DB::transaction(function () use ($enterprise, $competitors): Report {
            $generatedAt = Carbon::now();
            $competitorSnapshots = array_values($competitors->map(fn (Competitor $competitor): array => $this->snapshotCompetitor($competitor))->all());
            $inputs = [
                'enterprise_id' => $enterprise->getKey(),
                'enterprise_name' => $enterprise->name,
                'scope' => 'selected_competitor_profile_versions',
                'competitors' => $competitorSnapshots,
            ];
            $fingerprintPayload = $this->canonicalize([
                'methodology_version' => self::METHODOLOGY_VERSION,
                'inputs' => $inputs,
            ]);
            $fingerprint = hash('sha256', json_encode($fingerprintPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $result = $this->buildResult($enterprise, $competitorSnapshots, $generatedAt, $fingerprint);

            $report = Report::query()->create([
                'enterprise_id' => $enterprise->getKey(),
                'report_type' => self::REPORT_TYPE,
                'status' => Report::STATUS_COMPLETED,
                'period_start' => $generatedAt,
                'period_end' => $generatedAt,
                'generated_at' => $generatedAt,
                'methodology_version' => self::METHODOLOGY_VERSION,
                'result_payload' => $result,
            ]);

            ReportSnapshot::query()->create([
                'report_id' => $report->getKey(),
                'captured_at' => $generatedAt,
                'period_start' => $generatedAt,
                'period_end' => $generatedAt,
                'methodology_version' => self::METHODOLOGY_VERSION,
                'source_records' => [
                    'enterprise_id' => $enterprise->getKey(),
                    'scope' => $inputs['scope'],
                    'methodology_version' => self::METHODOLOGY_VERSION,
                    'inputs' => $inputs,
                ],
                'source_fingerprint' => $fingerprint,
            ]);

            return $report->load('snapshot');
        });
    }

    /** @return array<string, mixed> */
    private function snapshotCompetitor(Competitor $competitor): array
    {
        $profile = [
            'name' => $competitor->name,
            'website' => $competitor->website,
            'positioning' => $competitor->positioning,
            'strengths' => $competitor->strengths ?? [],
            'weaknesses' => $competitor->weaknesses ?? [],
        ];

        foreach (Competitor::PROFILE_FIELDS as $field) {
            $profile[$field] = $competitor->{$field} ?? [];
        }

        /** @var list<array<string, mixed>> $sourceReferences */
        $sourceReferences = array_values(array_filter($profile['source_references'], 'is_array'));
        $evidence = array_map(fn (array $reference): array => $this->snapshotEvidenceReference($competitor, $reference), $sourceReferences);
        $effectiveFrom = $competitor->getAttribute('effective_from');
        $effectiveTo = $competitor->getAttribute('effective_to');

        return [
            'competitor_id' => $competitor->getKey(),
            'profile_version' => $competitor->version,
            'status' => $competitor->status,
            'effective_from' => $effectiveFrom instanceof \DateTimeInterface ? $effectiveFrom->format(DATE_ATOM) : null,
            'effective_to' => $effectiveTo instanceof \DateTimeInterface ? $effectiveTo->format(DATE_ATOM) : null,
            'profile' => $profile,
            'evidence' => $evidence,
        ];
    }

    /** @param array<string, mixed> $reference
     * @return array<string, mixed>
     */
    private function snapshotEvidenceReference(Competitor $competitor, array $reference): array
    {
        $referenceId = (int) ($reference['knowledge_reference_id'] ?? 0);
        $knowledgeReference = KnowledgeReference::query()
            ->with(['item.latestVersion', 'item.source', 'document.source'])
            ->whereKey($referenceId)
            ->where('enterprise_id', $competitor->enterprise_id)
            ->whereHas('item', function (Builder $query) use ($competitor): void {
                $query->where('enterprise_id', $competitor->enterprise_id)
                    ->where(function (Builder $sourceScope) use ($competitor): void {
                        $sourceScope->whereNull('knowledge_source_id')
                            ->orWhereHas('source', fn (Builder $source): Builder => $source->where('enterprise_id', $competitor->enterprise_id));
                    });
            })
            ->where(function (Builder $query) use ($competitor): void {
                $query->whereNull('knowledge_document_id')
                    ->orWhereHas('document', function (Builder $document) use ($competitor): void {
                        $document->where('enterprise_id', $competitor->enterprise_id)
                            ->where(function (Builder $sourceScope) use ($competitor): void {
                                $sourceScope->whereNull('knowledge_source_id')
                                    ->orWhereHas('source', fn (Builder $source): Builder => $source->where('enterprise_id', $competitor->enterprise_id));
                            });
                    });
            })
            ->first();

        $metadata = [
            'knowledge_reference_id' => $referenceId,
            'field' => $reference['field'] ?? null,
            'claim' => $reference['claim'] ?? null,
            'observed_at' => $reference['observed_at'] ?? null,
        ];

        if ($knowledgeReference === null) {
            return [...$metadata, 'available' => false, 'unavailable_reason' => 'Knowledge reference is no longer available in this Enterprise.'];
        }

        $item = $knowledgeReference->item;
        $version = $item?->latestVersion;
        $document = $knowledgeReference->document;
        $source = $document->source ?? $item?->source;

        return [
            ...$metadata,
            'available' => true,
            'reference' => [
                'type' => $knowledgeReference->type,
                'label' => $knowledgeReference->label,
                'locator' => $knowledgeReference->locator,
                'metadata' => $knowledgeReference->metadata ?? [],
            ],
            'item' => $item === null ? null : [
                'id' => $item->getKey(),
                'title' => $item->title,
                'type' => $item->type,
                'summary' => $item->summary,
            ],
            'knowledge_version' => $version === null ? null : [
                'id' => $version->getKey(),
                'version' => $version->version,
                'recorded_at' => $version->getAttribute('recorded_at') instanceof \DateTimeInterface ? $version->getAttribute('recorded_at')->format(DATE_ATOM) : null,
                'content' => $version->content,
                'content_sha256' => hash('sha256', $version->content),
                'context_snapshot' => $version->context_snapshot ?? [],
            ],
            'document' => $document === null ? null : [
                'id' => $document->getKey(),
                'title' => $document->title,
                'identifier' => $document->identifier,
                'status' => $document->status,
                'content' => $document->content,
                'content_sha256' => $document->content === null ? null : hash('sha256', $document->content),
            ],
            'source' => $source === null ? null : [
                'id' => $source->getKey(),
                'name' => $source->name,
                'type' => $source->type,
                'uri' => $source->uri,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $competitors
     * @return array<string, mixed>
     */
    private function buildResult(Enterprise $enterprise, array $competitors, Carbon $generatedAt, string $fingerprint): array
    {
        $dimensions = [
            'positioning' => 'Positioning',
            'strengths' => 'Strengths',
            'weaknesses' => 'Weaknesses',
            'products' => 'Products and services',
            'pricing' => 'Pricing',
            'target_segments' => 'Target segments',
            'markets' => 'Markets',
            'differentiators' => 'Differentiators',
            'channels' => 'Channels',
            'reputation' => 'Reputation observations',
            'digital_presence' => 'Digital presence',
        ];
        $comparisons = [];
        $coverage = [];
        $risks = [];
        $recommendations = [];

        foreach ($dimensions as $field => $label) {
            $rows = [];
            $present = 0;

            foreach ($competitors as $competitor) {
                $profile = $competitor['profile'];
                $value = $profile[$field] ?? [];
                $hasValue = is_array($value) && $value !== [];
                $present += $hasValue ? 1 : 0;
                $rows[] = [
                    'competitor_id' => $competitor['competitor_id'],
                    'name' => $profile['name'],
                    'profile_version' => $competitor['profile_version'],
                    'value' => $value,
                    'recorded' => $hasValue,
                ];

                if (! $hasValue) {
                    $recommendations[] = [
                        'priority' => 'research',
                        'competitor_id' => $competitor['competitor_id'],
                        'recommendation' => 'Collect sourced '.$label.' information for '.$profile['name'].' before making decisions that depend on this dimension.',
                        'basis' => 'The selected profile version contains no recorded data for this dimension.',
                    ];
                }
            }

            $comparisons[$field] = ['label' => $label, 'competitors' => $rows];
            $coverage[$field] = [
                'label' => $label,
                'profiles_with_data' => $present,
                'profiles_total' => count($competitors),
                'coverage_ratio' => count($competitors) === 0 ? 0 : round($present / count($competitors), 4),
            ];

            if ($present < count($competitors)) {
                $risks[] = [
                    'type' => 'incomplete_data',
                    'dimension' => $field,
                    'description' => $label.' information is missing from one or more selected competitor profiles; comparisons in this dimension are incomplete.',
                ];
            }
        }

        foreach ($competitors as $competitor) {
            $profile = $competitor['profile'];
            if (($competitor['evidence'] ?? []) === []) {
                $risks[] = [
                    'type' => 'evidence_gap',
                    'competitor_id' => $competitor['competitor_id'],
                    'description' => $profile['name'].' has no Knowledge evidence references attached to this profile version.',
                ];
                $recommendations[] = [
                    'priority' => 'evidence',
                    'competitor_id' => $competitor['competitor_id'],
                    'recommendation' => 'Attach same-Enterprise Knowledge references to the recorded claims for '.$profile['name'].'.',
                    'basis' => 'The selected profile version has no evidence references.',
                ];
            }
        }

        if (count($competitors) < 2) {
            $risks[] = [
                'type' => 'comparison_scope',
                'description' => 'Fewer than two competitor profile versions were selected; this report summarizes the available profile but cannot provide a cross-competitor comparison.',
            ];
        }

        $evidence = collect($competitors)->flatMap(fn (array $competitor): array => $competitor['evidence'] ?? [])->values()->all();
        $availableEvidenceCount = count(array_filter($evidence, fn (array $item): bool => ($item['available'] ?? false) === true));
        $reportEvidence = array_map(static function (array $item): array {
            $version = $item['knowledge_version'] ?? null;
            if (is_array($version)) {
                $version = [
                    'id' => $version['id'] ?? null,
                    'version' => $version['version'] ?? null,
                    'recorded_at' => $version['recorded_at'] ?? null,
                    'content_sha256' => $version['content_sha256'] ?? null,
                ];
            }

            return [
                'knowledge_reference_id' => $item['knowledge_reference_id'] ?? null,
                'field' => $item['field'] ?? null,
                'claim' => $item['claim'] ?? null,
                'observed_at' => $item['observed_at'] ?? null,
                'available' => $item['available'] ?? false,
                'unavailable_reason' => $item['unavailable_reason'] ?? null,
                'reference' => $item['reference'] ?? null,
                'item' => $item['item'] ?? null,
                'knowledge_version' => $version,
                'document' => $item['document'] ?? null,
                'source' => $item['source'] ?? null,
            ];
        }, $evidence);

        $unavailableEvidenceCount = count($evidence) - $availableEvidenceCount;
        if ($unavailableEvidenceCount > 0) {
            $risks[] = [
                'type' => 'unavailable_evidence',
                'description' => $unavailableEvidenceCount.' linked Knowledge reference(s) could not be resolved within the Enterprise when this report was generated.',
            ];
            $recommendations[] = [
                'priority' => 'evidence',
                'recommendation' => 'Repair or replace unavailable Knowledge references before relying on the affected profile claims.',
                'basis' => 'One or more linked Knowledge references were unavailable during snapshot capture.',
            ];
        }

        return [
            'schema_version' => self::METHODOLOGY_VERSION,
            'report_type' => self::REPORT_TYPE,
            'enterprise' => ['id' => $enterprise->getKey(), 'name' => $enterprise->name],
            'generated_at' => $generatedAt->toISOString(),
            'methodology_version' => self::METHODOLOGY_VERSION,
            'source_fingerprint' => $fingerprint,
            'scope' => [
                'competitor_profile_versions' => array_map(fn (array $competitor): array => [
                    'competitor_id' => $competitor['competitor_id'],
                    'name' => $competitor['profile']['name'],
                    'version' => $competitor['profile_version'],
                ], $competitors),
                'competitor_count' => count($competitors),
            ],
            'executive_summary' => sprintf(
                'This report compares %d selected competitor profile version(s) using information recorded in CR8OR as of %s. It is a deterministic profile comparison, not independent verification of market claims.',
                count($competitors),
                $generatedAt->toDateString(),
            ),
            'competitors' => array_map(fn (array $competitor): array => [
                'competitor_id' => $competitor['competitor_id'],
                'version' => $competitor['profile_version'],
                'name' => $competitor['profile']['name'],
                'website' => $competitor['profile']['website'],
                'positioning' => $competitor['profile']['positioning'],
                'strengths' => $competitor['profile']['strengths'],
                'weaknesses' => $competitor['profile']['weaknesses'],
                'evidence_reference_count' => count($competitor['evidence'] ?? []),
            ], $competitors),
            'comparisons' => $comparisons,
            'profile_coverage' => array_values($coverage),
            'findings' => [
                'swot' => [
                    'strengths' => array_map(fn (array $competitor): array => ['competitor_id' => $competitor['competitor_id'], 'name' => $competitor['profile']['name'], 'items' => $competitor['profile']['strengths']], $competitors),
                    'weaknesses' => array_map(fn (array $competitor): array => ['competitor_id' => $competitor['competitor_id'], 'name' => $competitor['profile']['name'], 'items' => $competitor['profile']['weaknesses']], $competitors),
                    'opportunities' => [],
                    'threats' => [],
                    'not_assessed_reason' => 'This deterministic methodology does not infer market opportunities or threats from incomplete profile data.',
                ],
                'strengths_and_weaknesses' => array_map(fn (array $competitor): array => [
                    'competitor_id' => $competitor['competitor_id'],
                    'name' => $competitor['profile']['name'],
                    'strengths' => $competitor['profile']['strengths'],
                    'weaknesses' => $competitor['profile']['weaknesses'],
                ], $competitors),
                'evidence_coverage' => [
                    'references_total' => count($evidence),
                    'references_available' => $availableEvidenceCount,
                    'references_unavailable' => count($evidence) - $availableEvidenceCount,
                ],
            ],
            'risks' => $risks,
            'recommendations' => $recommendations,
            'limitations' => [
                'The report only uses the selected competitor profile versions and their explicitly linked Knowledge references.',
                'A recorded claim is not treated as independently verified merely because it appears in a competitor profile.',
                'Pricing is presented as recorded and is not ranked when currencies, billing periods, packages, or scope differ.',
                'No values are inferred for missing profile fields.',
            ],
            'evidence' => $reportEvidence,
        ];
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}