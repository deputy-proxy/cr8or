# Governed competitor creation

## Purpose and architecture

The existing `App\Models\Competitor`, `competitors` table, `Enterprise::competitors()` relationship, `CompetitorPolicy`, and `StrategicContextService::createCompetitor()` are the canonical competitor domain. This feature adds no model, table, Filament resource, or parallel persistence abstraction.

The governed path is:

`MCP identity → authenticated User → enterprise context → CompetitorPolicy::createForEnterprise → marketing.competitor.create → App\Operations\CreateCompetitor → StrategicContextService::createCompetitor`

- Capability: `marketing.competitor.create`
- Operation: `App\Operations\CreateCompetitor`
- MCP tool: `create-competitor` (`App\Mcp\Tools\CreateCompetitorTool`)
- Expert exposure: `App\Experts\MarketingExpert`

The tool validates transport input, asks the existing `McpCapabilityAuthorizer` to authorize the mutation, and invokes the registered capability. The operation delegates the business action to `StrategicContextService`; it does not persist records itself. The service remains the domain validation, authorization, versioning, and persistence boundary, including when called outside MCP.

## Input contract

| Field | Required | Validation |
|---|---|---|
| `enterprise_id` | Yes | Existing enterprise ID. It selects context but never proves permission by itself. |
| `name` | Yes | Trimmed non-empty string, maximum 255 characters. Exact trimmed names are the version identity within one enterprise. |
| `website` | No | Nullable absolute URL including scheme, maximum 255 characters. Empty strings normalize to null. |
| `positioning` | No | Nullable text, maximum 10,000 characters. Empty strings normalize to null. |
| `strengths` | No | List of at most 50 non-empty strings, each maximum 500 characters. Defaults to an empty list. |
| `weaknesses` | No | List of at most 50 non-empty strings, each maximum 500 characters. Defaults to an empty list. |
| `agent_assignment_id` | No | Existing assignment; used only for governed Agent execution context. |
| `agent_execution_id` | No | Existing execution; used only for governed Agent execution context. |
| `approval_request_id` | No | Existing approval request; used only when the capability execution contract requires it. |

The MCP schema and Laravel validation rules expose the same business fields and limits. The service validates again because it is also used by non-MCP callers. No migration is required.

## Output contract

Successful calls return `{ success: true, result: ... }` with `id`, `enterprise_id`, `version`, `name`, `website`, `positioning`, `strengths`, `weaknesses`, `status`, `effective_from`, `effective_to`, `supersedes_id`, and `is_current`. Timestamps are ISO-8601 strings or null. No credentials or unrelated tenant data are returned.

## Ownership and authorization

`enterprise_id` is resolved to an `Enterprise`, then the caller is checked against `CompetitorPolicy::createForEnterprise`. The policy delegates to `EnterprisePolicy::createForOrganization`, which permits an Owner or Admin of that enterprise's organization. A user who belongs to a different organization, or a Member without create permission, is denied before a competitor is persisted. The domain service repeats the policy check; UI visibility is not a security boundary.

Agent-backed calls must additionally pass the existing capability/assignment authorization path. `MarketingExpert` explicitly exposes `marketing.competitor.create`; the capability registry does not grant permission by itself. The tool is registered through `Cr8orServer`, and the runtime capability map binds the tool to exactly one operation.

## Version and duplicate semantics

The existing domain intentionally versions competitors by exact trimmed name within an enterprise. If no version exists, creation returns version 1. If a current version with the same name exists, creation archives that version, sets its `effective_to`, and creates a new active version with `supersedes_id` pointing to the archived record. Historical fields are not overwritten. Different enterprises have independent version histories. The service locks the enterprise row while allocating the next version so concurrent calls serialize version allocation. The database's existing unique constraint on `(enterprise_id, name, version)` remains authoritative as a final guard.

There is no separate domain-level idempotency record for competitor creation. The shared invocation layer carries correlation/idempotency metadata for execution provenance but does not deduplicate this operation. Therefore, **do not blindly retry an ambiguous create**: repeating the same name intentionally creates another version. Confirm the first result or inspect the current competitor version before retrying. This behavior is explicit rather than silently overwriting a prior record.

## Errors and retries

- Validation errors: missing/blank name, invalid URL, oversized text/list, non-list arrays, or non-string list items are rejected before persistence.
- Authorization errors: missing enterprise create permission is rejected; the tool does not disclose competitor records from other enterprises.
- Missing enterprise: the existing not-found handling applies.
- Version allocation/persistence failure: the database transaction rolls back the archive and successor creation together.
- Retry: only retry after confirming the previous invocation did not create a version. A repeated successful request is a new historical version, not an idempotent replay.

## Runtime registration and verification

No database seed or schema migration is required for the capability itself. Deploy the application code with the new operation, registry entry, Marketing Expert exposure, and `Cr8orServer` tool registration using the repository's normal release procedure. Refresh/restart the MCP application runtime only as required by the deployed environment, then verify `create-competitor` appears in the authenticated MCP tool list and that `CapabilityRegistry::forTool(CreateCompetitorTool::class)` resolves to `marketing.competitor.create` and `CreateCompetitor`. Do not claim live discoverability until verified in the target environment.

## Tests

Focused regression coverage is in `tests/Feature/Mcp/CompetitorCreationToolTest.php` and existing version-history coverage remains in `tests/Feature/StrategicContextTest.php`. Run the focused tests first, then `composer ci:check` and the frontend checks required by `.github/workflows/tests.yml`.

## Structured profile fields

Competitor creation accepts optional structured arrays for products, target_segments, pricing, markets, differentiators, channels, reputation, digital_presence, and source_references. Each item is a plain object matching the contract documented in [competitive-analysis-reporting.md](competitive-analysis-reporting.md). Domain validation runs in StrategicContextService; the Filament Builder is an editor and does not define the persistence contract. Evidence references must belong to the same Enterprise. All structured fields are versioned with the existing competitor profile and cannot be changed on a persisted version.

Competitive analysis is generated through the separate marketing.competitor.report.generate capability, GenerateCompetitiveAnalysisReport operation, and generate-competitive-analysis-report MCP tool. The tool returns a persisted competitive_analysis report and source fingerprint. It does not mutate competitor records. See the competitive-analysis document for the report payload, snapshot, methodology, and provenance contract.
