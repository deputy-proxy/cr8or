# Competitor profiles and competitive-analysis reports

## Architecture

CR8OR keeps competitor identity and profile history in the existing **Competitor** aggregate. It does not create separate models for products, pricing, market segments, channels, or reputation. Material profile changes create successor Competitor versions through StrategicContextService; saved descriptive profile data is immutable.

The profile is a structured summary, not a replacement for source material:

- Competitor JSON fields store normalized profile data used for comparisons.
- KnowledgeSource, KnowledgeDocument, KnowledgeItem, KnowledgeVersion, and KnowledgeReference remain the canonical Knowledge and provenance layer.
- Report stores a completed derived report and its immutable structured result_payload.
- ReportSnapshot stores normalized inputs, competitor profile version references, Knowledge evidence snapshots, methodology version, and source fingerprint.
- ReportMetricValue remains for numeric metrics and is not used to store prose findings or recommendations.

## Competitor profile data contract

The structured fields are optional lists. Older records may contain null; application serialization normalizes missing values to empty lists. Each item is a plain JSON object. Filament Builder provides a schema-driven editor but strips its internal type/data wrapper before persistence, so the stored contract is independent of Filament.

| Field | Item contract | Purpose |
|---|---|---|
| products | name required; optional description, category, features[], target_segment, differentiators[], pricing_reference | Offerings, features, segments and claims |
| target_segments | name required; optional description, industry, demographics, needs[], use_cases[] | Customer segments and buying context |
| pricing | model required; optional product_name, amount, minimum_amount, maximum_amount, currency, billing_interval, terms, package | Recorded price models without assuming unlike prices are comparable |
| markets | geography required; optional industries[], languages[], notes | Geography and market coverage |
| differentiators | claim and claim_type required; optional category | Distinguishes competitor_claim, observed_fact, and verified_fact |
| channels | channel_type and name required; optional details | Sales, distribution, acquisition and social channels |
| reputation | platform and sentiment required; optional rating, rating_scale, review_count, summary, observed_at | Dated, source-aware reputation observations |
| digital_presence | channel and observation required; optional url, observed_at | Dated website and digital-channel observations |
| source_references | knowledge_reference_id required; optional field, claim, observed_at | Links a profile claim or field to an existing Knowledge reference |

Every field is validated by StrategicContextService, regardless of entry point. A source reference must resolve to a KnowledgeReference and linked item/document belonging to the same Enterprise as the competitor. Any linked Knowledge source must also be scoped to that Enterprise. The model stores only the reference identifier and attribution metadata; Knowledge content is not copied into the profile JSON.

All nine structured fields participate in the immutable profile version contract. A material change creates a successor version; it does not mutate the prior version. The context serializer exposes the structured fields and normalizes absent legacy values to [].

## Competitive-analysis report generation

- Capability: marketing.competitor.report.generate
- Operation: App\Operations\GenerateCompetitiveAnalysisReport
- MCP tool: generate-competitive-analysis-report
- Domain service: CompetitiveAnalysisReportingService
- Report type: competitive_analysis
- Methodology version: 1.0

The MCP tool delegates to one registered Capability/Operation. The operation delegates generation to the domain service. Both the MCP authorization boundary and domain service authorize Enterprise access; selected competitor IDs are scoped to the requested Enterprise. If competitor_ids is omitted or empty, the service selects current versions. An explicit list may select current or historical profile-version IDs from that Enterprise. A cross-Enterprise selection is rejected.

The report payload is deterministic and intentionally conservative. It contains:

- executive summary and analysis scope;
- selected competitor IDs and exact profile version numbers;
- per-competitor profile summaries and structured comparison dimensions;
- data-coverage findings;
- evidence coverage and source references;
- risks and research/evidence recommendations derived from missing data;
- explicit limitations.

The generator does not invent market facts, infer missing values, rank incomparable prices, or treat a competitor's claim as independently verified. This implementation is a reproducible profile comparison, not an LLM-generated strategy opinion or automated web-research pipeline.

## Snapshot and reproducibility contract

The completed Report.result_payload stores the rendered structured result. ReportSnapshot.source_records stores the normalized inputs used for that result, including:

- Enterprise ID and scope;
- competitor IDs, exact profile version numbers, lifecycle metadata and complete profile values;
- each linked Knowledge reference's ID and attribution metadata;
- reference, item, document and source metadata where available;
- the exact selected KnowledgeVersion ID/version/content, content hash, context snapshot and recorded timestamp;
- methodology version.

Unavailable references are preserved as unavailable entries instead of silently dropped. The snapshot's SHA-256 fingerprint is calculated from recursively key-sorted normalized inputs plus the methodology version. The fingerprint excludes capture-time noise and is stable for the same input set.

Reports and snapshots are created in one database transaction. Reports persist only in the existing completed-result state. Report identity and result_payload are immutable after creation, and snapshots reject updates. Report detail views render persisted payload and snapshot data, not current competitor profiles or live Knowledge records. Later profile revisions or Knowledge updates therefore do not rewrite an earlier report.

## Authorization and ownership

A competitor and a competitive-analysis report belong to an Enterprise. Knowledge sources, documents, items, versions and references retain their existing ownership model; a reference may be attached to a competitor only after same-Enterprise checks. Do not infer authorization from submitted IDs or from Filament visibility.

For interactive MCP use, authorization follows the existing governed path: authenticated user, requested Enterprise context, McpCapabilityAuthorizer, Capability Registry, Operation, and domain policy/service checks. Agent-backed calls also require the existing assignment/execution/Expert authorization contract. The report operation does not add or hardcode workflow definitions.

## Tests

Regression tests cover profile data validation and serialization, same-Enterprise evidence-reference checks, immutable competitor revisions, report scope and authorization, structured result persistence, source snapshots and fingerprints, and historical report stability after later profile changes.
