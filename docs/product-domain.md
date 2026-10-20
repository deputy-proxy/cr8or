# Product Domain Contract

`Product` represents an offering and its current sellable/commercial configuration. Version one stores one inline commercial configuration on each Product; it does not introduce ProductOffer, SKU, package, or variant models. Product belongs to one Enterprise, and that ownership remains immutable. `Persona` / Persona stays a separate domain and may be associated through the existing same-Enterprise pivot.

## Identity and lifecycle

- `enterprise_id`, `name`, `slug`, and timestamps retain their existing meaning. Slugs remain unique within an Enterprise and are generated from the name when omitted.
- `status` is one of `draft`, `active`, `archived`, `discontinued`. Existing `active` and `archived` rows are preserved. New minimal calls default to `active`.
- `product_type` is one of `service`, `digital_product`, `subscription`, `course`, `editorial`, `physical_product`, `other`. Existing rows are backfilled as `other` because the former schema did not record type.

## Positioning and definition

- `tagline` is a short memorable phrase (255 characters maximum).
- `summary` is a concise description (1,000 characters maximum); `description` is the longer canonical description.
- `problem_statement`, `value_proposition`, `primary_outcome`, and `positioning` capture distinct product-level meaning: the problem addressed, value promised, principal customer outcome, and market position.
- `differentiators`, `features`, `benefits`, `deliverables`, `requirements`, `limitations`, `required_disclosures`, and `restrictions` are nullable JSON lists of strings. Each list is bounded to 50 non-empty strings, each at most 500 characters. `null` means not supplied; an empty list means explicitly no items recorded.
- `delivery_model` is one of `digital_self_service`, `editorial_content_access`, `delivered_service`, `subscription_recurring_access`, `physical_fulfillment`, `hybrid`, `other`. It describes the broad delivery pattern.
- `fulfillment_method` provides a concrete fulfillment detail only when it adds meaning beyond delivery model. `support_model` describes ongoing support.

## Inline commercial configuration

- `monetization_model`: `free`, `one_time_purchase`, `subscription`, `service_fee`, `affiliate_supported`, `hybrid`, `quote_based`, `other`.
- `pricing_model`: `free`, `fixed`, `usage_based`, `subscription`, `quote_based`, `other`.
- `price_amount` is nullable decimal(12,2). When present it must be non-negative and have a valid ISO 4217 `currency`.
- `currency` is nullable until a price is supplied and is normalized to uppercase. Codes that mean “no currency” (`XXX`) or are reserved for testing (`XTS`) are rejected for prices.
- `billing_interval` is `one_time`, `weekly`, `monthly`, `quarterly`, or `yearly`. Subscription pricing or monetization requires a recurring interval (`weekly`, `monthly`, `quarterly`, or `yearly`).
- `commercial_terms` and `commercial_notes` are optional text. `is_commercially_available` is an explicit boolean independent of lifecycle status and defaults to `false`; an active product is not assumed to be commercially offered.
- `availability_start_at` and `availability_end_at` are optional timestamps. If both exist, the end must not precede the start.
- A free `pricing_model` or `monetization_model` cannot have a positive price. A quote-based product may omit a fixed amount. No existing product is assigned an invented price, currency, or availability window.

The same controlled values and cross-field rules are enforced by the governed `product.create` operation and the Product model's persistence guard. Filament provides editing controls but is not the source of business rules.

## MCP contract

`create-product` requires `enterprise_id` and `name`. All other fields are optional for backward compatibility. Omitted status defaults to `active`; omitted type/delivery/monetization/pricing models default to `other`; omitted commercial availability defaults to `false`; descriptive and commercial detail fields remain `null` unless supplied. The tool delegates through `product.create` → `ProductCreate` → `DomainResourceService::createProduct`; it does not persist independently. Its JSON schema, Laravel validation rules, capability input contract, and structured result expose the same field set.

The result includes the persisted identity, lifecycle, positioning, definition/delivery, commercial, and governance fields. Dates are returned as ISO 8601 strings. Existing minimal callers remain supported.

## Ownership and relationships

- Enterprise assignment is immutable after creation and is authorized through the Enterprise's Organization.
- Slug uniqueness remains `(enterprise_id, slug)`.
- `Product::enterprise()` and `Product::personas()` are preserved. The Persona pivot continues to reject cross-Enterprise associations.
- No existing Product association was found on Campaign, ContentItem, Asset, or Workflow, so this issue adds none without a demonstrated use case. Product does not duplicate persona demographics, persona motivations, campaign/content/workflow/asset data, or a new Knowledge/evidence subsystem. Product claims are not marked verified merely by adding a free-text field. CR8OR has Enterprise-scoped KnowledgeItem/KnowledgeSource/KnowledgeReference abstractions, but no authoritative Product-claim-to-evidence relationship or provenance workflow was found. This version adds no `approved_claims` field and does not imply claim verification; a future evidence workflow must define the link and provenance explicitly.