# Client personas (Audience domain)

CR8OR represents a reusable client persona with the existing `App\Models\Audience` model and `audiences` table. The UI calls these records **Personas**; IDs, foreign keys, policies, domain services, MCP tools, and `ContentItem.audience_id` remain compatible. A persona is an archetype, not a real customer record. Do not store names, contact details, exact birth dates, or sensitive personal data about identifiable individuals.

## Canonical fields

| Field | Type | Meaning and validation |
|---|---|---|
| `name` | string, required, max 255 | Persona name. Existing create contract remains compatible. |
| `description` | nullable text, max 10,000 | Summary of the archetype. |
| `status` | string: `active`, `archived` | Existing lifecycle contract. |
| `persona_type` | nullable string, max 40 | One of `buyer`, `user`, `influencer`, `decision_maker`, `gatekeeper`. |
| `age_range` | nullable string, max 100 | Approximate age band, not birth date. |
| `location` | nullable string, max 255 | Geographic market or area. |
| `job_title` | nullable string, max 255 | Typical role or profession. |
| `income_range` | nullable string, max 100 | Approximate range, using a documented currency/period in the value where needed. |
| `education_level` | nullable string, max 100 | Typical education level. |
| `family_status` | nullable string, max 100 | Generalized household/family context. |
| `research_confidence` | nullable decimal (3,2), 0–1 | Confidence in the persona hypothesis; it does not imply statistical certainty. |
| `last_reviewed_at` | nullable date | Date the persona was last reviewed. |
| `psychographics` | nullable JSON object | `values`, `interests`, `lifestyle`, `attitudes`, `aspirations`: lists of strings (each max 500 characters). |
| `goals_and_motivations` | nullable JSON object | `goals`, `purchase_motivations`, `success_criteria`, `practical_motivations`, `emotional_motivations`: lists of strings (each max 500 characters). |
| `pain_points` | nullable JSON object | `problems`, `obstacles`, `current_alternatives`, `consequences_of_inaction`, `desired_improvements`: lists of strings (each max 500 characters). |
| `buying_behavior` | nullable JSON object | `preferred_channels` and `preferred_content_formats` (strings max 100); `research_habits`, `decision_criteria`, `purchase_triggers`, `objections` (strings max 500); optional `budget_sensitivity` (string max 500) and `purchase_frequency` (string max 100). |

The JSON groups are structured, extensible attributes rather than a single free-form persona blob. The demographic and lifecycle fields are typed columns because they are likely to be filtered, validated, or reported on. Multi-valued behavioral and psychographic attributes use documented JSON object shapes. Omitted attributes during an update are preserved; explicit `null` clears nullable scalar/group fields.

Treat the attributes as a mixture of research and assumptions. `research_confidence` and `last_reviewed_at` provide lightweight review metadata, not source provenance. A source/evidence model is outside the scope of this contract.

## Product relevance

`Audience.products()` and `Product.audiences()` use the `audience_product` pivot with timestamps and a unique composite key on `(audience_id, product_id)`. A persona and associated product must share the same `enterprise_id`. Domain writes validate product IDs against the authorized enterprise; the custom pivot rejects invalid cross-enterprise pairs, including relationship writes from the UI. Both foreign keys cascade on deletion.

`product_ids` is an optional list of product IDs on the `create-audience` and `update-audience` MCP contracts. On create, an omitted list creates no associations. On update, omission preserves current associations; an explicit empty list removes all associations. Product IDs must be distinct, positive integers and belong to the persona's enterprise. A normal foreign key alone cannot enforce matching `enterprise_id` values, so application validation and pivot-model validation are both used.

Product relevance is reusable persona data. It is not campaign-level targeting and does not mean that a particular `ContentItem` promotes a particular product. No `product_id` was added to `ContentItem`.

## Authorization and entry points

- Filament keeps the existing Audience resource and policy, presents it as Personas, scopes enterprise and product selectors to authorized organizations and the selected enterprise, and uses the standard relationship lifecycle.
- The canonical domain write path remains `DomainResourceService::createAudience` / `updateAudience`, reached through `MarketingAudienceCreate` / `MarketingAudienceUpdate`. The service authorizes against the existing Audience policy and validates product tenancy before synchronizing associations.
- `create-audience` and `update-audience` remain thin `DomainMutationTool` adapters. Their capability IDs and operations are unchanged; no business rules are implemented in MCP. The authenticated actor and authorized enterprise are resolved by the existing capability execution path.
- Existing minimal payloads using `enterprise_id`, `name`, `description`, or `audience_id` remain valid. Audience enterprise ownership is immutable. Existing content items keep referencing the same audience IDs.
- Existing generic marketing workflow stage continues to use `marketing.audience.create`; its request contract now advertises optional persona fields and product IDs. No duplicate workflow or hardcoded runtime mapping is introduced.

## Migration and rollback

Two additive, reversible migrations add nullable persona fields and create the pivot. Existing audience rows and content references are unchanged. Rolling back the persona-field migration necessarily removes any values written to those new columns; rolling back the pivot removes persona/product associations. Back up data before rollback in an environment where those values must be retained.

## Examples

Create (legacy-compatible):

```json
{ "enterprise_id": 12, "name": "Budget-conscious small business owner", "description": "Wants practical marketing outcomes with limited time." }
```

Create or update with structured persona data:

```json
{
  "enterprise_id": 12,
  "name": "Budget-conscious small business owner",
  "persona_type": "buyer",
  "age_range": "30-49",
  "location": "Romania, urban areas",
  "research_confidence": 0.75,
  "psychographics": { "values": ["independence", "practicality"], "interests": ["small business growth"] },
  "goals_and_motivations": { "goals": ["acquire qualified leads"], "purchase_motivations": ["save time"] },
  "pain_points": { "problems": ["inconsistent lead flow"] },
  "buying_behavior": { "preferred_channels": ["Instagram", "email"], "decision_criteria": ["price", "ease of use"] },
  "product_ids": [4, 7]
}
```

For updates, use `audience_id` instead of `enterprise_id`; do not include `enterprise_id`. Omit `product_ids` to preserve associations or provide `[]` to clear them.