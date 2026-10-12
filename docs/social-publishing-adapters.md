# Social Publishing Adapter Contracts

## Purpose and boundaries

CR8OR owns publication authorization, destination validation, scheduling, idempotency, lifecycle state, reconciliation and history. A publishing adapter performs one network's external publishing operation; it does not own business authorization, tenancy, publication state or workflow orchestration.

OAuth authorization and connected-account discovery remain in the existing `SocialAuthorizationProvider` boundary. Publishing adapters receive an organization-scoped `IntegrationConnection` identifier and an opaque credential reference. They must never receive or persist raw access/refresh tokens through publishing request DTOs or result payloads.

No MCP-specific adapter layer is introduced. MCP tools continue to delegate to the existing capability/operation boundary and must not contain provider logic.

## Required contract

Every enabled adapter implements `PublishingAdapter` and declares:

- one canonical `network()` key;
- a stable `providerKey()`/adapter key;
- a `PublishingCapabilities` declaration for the same network;
- `publish(PublishingAdapterRequest): PublishingAdapterResult`.

The registry maps the network key to exactly one configured adapter. It fails closed when a network is disabled or unknown, the adapter class is invalid, the configured adapter key is duplicated, the configured key differs from the adapter's key, the adapter declares a different network, or its capability declaration does not match the configured network. Adapter keys are unique across the configured map, including disabled entries, so later enablement cannot silently introduce ambiguous routing.

`PublishingAdapterRequest` carries the canonical network, destination external ID, content/media metadata, scheduling intent, idempotency key, correlation ID, connection ID and opaque credential reference. `PublishingAdapterResult` uses normalized statuses and must return the same correlation ID and idempotency key as the request. Reconciliation rejects results that do not match the original publication identity.

`PublishingAdapterException` exposes a canonical failure code plus independent `retryable` and `ambiguous` flags. An ambiguous timeout means the remote side may have accepted the request: CR8OR records an unknown outcome and blocks another submission until reconciliation. A retryable rate-limit failure may be retried with the same idempotency key. Authentication failures move the affected connection into the existing reconnect-required lifecycle.

## LinkedIn adapter (issue #525)

`App\Services\Publishing\LinkedInPublishingAdapter` implements immediate text-only organic posts through `POST https://api.linkedin.com/rest/posts`. It resolves the access token through the existing `CredentialResolver` using the persisted, active LinkedIn `IntegrationConnection`; the request's opaque credential reference must match the stored connection. Raw tokens are never copied into request/result metadata or logs.

The adapter accepts only canonical `urn:li:person:{id}` and `urn:li:organization:{id}` destination identifiers. It rejects ambiguous plain IDs and all media before making a network request. It currently declares a 3,000-character commentary limit, immediate publishing only, and no media, native scheduling, editing, deletion or analytics support. Known-post status lookup is supported through the Posts API GET endpoint with `viewContext=AUTHOR`; the returned post ID and author URN must match the requested publication and destination. A 404 returns unknown status rather than a fabricated failure or success. The response `x-restli-id` is persisted as the external publication ID; no public URL is fabricated when LinkedIn does not return one.

Create and status-lookup requests use the Posts API headers `Linkedin-Version` and `X-Restli-Protocol-Version: 2.0.0`. The remote post URN is URL-encoded when used as a path segment. Configure `SOCIAL_LINKEDIN_API_VERSION`; the repository default is `202609`, the latest active version verified for this implementation. Recheck the [LinkedIn version migration table](https://learn.microsoft.com/en-us/linkedin/marketing/integrations/migrations) before upgrading the version. The shared authorization configuration discovers the member profile as a `urn:li:person:` destination and queries organization ACLs for that authenticated member before offering organization destinations. It enriches organization destinations through LinkedIn’s Organization Lookup API when the app has access; if LinkedIn denies or cannot find optional metadata, the stable organization URN remains the fallback label rather than blocking an otherwise valid authorization flow.

The adapter remains **disabled** in `config/publishing.php`. Do not enable it until the deployment's LinkedIn app has the required products/scopes and a dedicated test member can publish successfully. `w_member_social` is required for member posting; `w_organization_social` and an eligible organization role are required for organization posting. LinkedIn's API does not expose a remote idempotency-key contract for post creation. CR8OR therefore relies on its persisted publication/job identity and its fail-closed rule that an ambiguous timeout or server outcome must be reconciled before any resubmission. This prevents blind retries but does not provide remote deduplication if a caller bypasses the CR8OR publishing lifecycle.

Official references: [Posts API](https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api), [Organization Access Control](https://learn.microsoft.com/en-us/linkedin/marketing/community-management/organizations/organization-access-control-by-role), [Organization Lookup API](https://learn.microsoft.com/en-us/linkedin/marketing/community-management/organizations/organization-lookup-api), and [OAuth authorization code flow](https://learn.microsoft.com/en-us/linkedin/shared/authentication/authorization-code-flow).

## Capability declarations

`PublishingCapabilitySupport` has three values:

- `supported`: behavior is verified for this adapter and its declared destination/media scope;
- `conditional`: behavior is available only when the listed `conditionalRequirements` are satisfied and enforced by code;
- `unsupported`: callers must not assume or invoke the behavior.

Conditional declarations without explicit requirements are invalid. A conditional feature is not treated as fully supported by convenience booleans. For example, native scheduling is enabled only when its status is `supported` and the network's `native_scheduling` configuration flag is enabled. Conditional scheduling remains CR8OR-managed until runtime condition checks exist.

Each capability declaration includes:

- the single network key;
- supported account and destination types;
- supported MIME types;
- maximum text length, media count, media size, video duration and supported aspect ratios where verified;
- support status for immediate publishing, native scheduling, status lookup, editing, deletion, analytics and idempotency.

A `null` numeric limit means *no finite limit has been declared*. It must not be interpreted as proof that the external API has no limit. A production adapter must verify its constraints against current official API documentation and applicable account permissions before it is enabled. Conditional requirements should identify the actual API/account/content prerequisite, not a vague note.

The registry validates optional contracts against capability declarations. If status lookup, editing, deletion or analytics is declared supported or conditional, the adapter must implement the corresponding `PublishingStatusLookupAdapter`, `PublishingEditAdapter`, `PublishingDeleteAdapter` or `PublishingAnalyticsAdapter` interface. Unsupported operations require no stub method. This prevents every network from being forced to pretend that it supports every operation.

## Target network matrix

This issue introduces the contract and reusable fake conformance harness, not real network API implementations. Therefore no network below is represented as production-ready or as having verified API capabilities by this matrix.

| Network key | Adapter implementation | Production state | Official API capability verification |
|---|---|---|---|
| `linkedin` | `LinkedInPublishingAdapter` (text-only) | Disabled | Posts API text-only creation and known-post status lookup verified; media, scheduling, editing, deletion and analytics are not implemented |
| `facebook` | Not implemented | Disabled | Pending adapter-specific research |
| `instagram` | Not implemented | Disabled | Pending adapter-specific research |
| `tiktok` | Not implemented | Disabled | Pending adapter-specific research |
| `bsky` | Not implemented | Disabled | Pending adapter-specific research |
| `youtube` | Not implemented | Disabled | Pending adapter-specific research |
| `threads` | Not implemented | Disabled | Pending adapter-specific research |
| `x` | Not implemented | Disabled | Pending adapter-specific research |

The eight `Fake*PublishingAdapter` classes in tests exist only to run the same contract suite against every target key. Their limits and capabilities are test fixtures, not claims about the real networks. When a real adapter is implemented, its contract dataset entry must be switched to that adapter and its declaration reviewed against official API documentation, account-type restrictions, permissions and media limits before production enablement.

## Reusable conformance suite

Run:

```bash
php artisan test tests/Feature/PublishingAdapterContractTest.php tests/Feature/PublishingTest.php
```

The shared suite runs against all eight target-network fake adapters and covers:

- a complete capability declaration and canonical network identity;
- successful normalized results with correlation/idempotency propagation;
- deterministic idempotent retries and status lookup;
- rejection, authentication failure, rate limiting and ambiguous timeout classification;
- unknown status without fabricating a remote result;
- invalid/conditional capability declarations;
- unknown networks, duplicate adapter keys, network mismatch and optional-interface/capability mismatch.

The fake suite verifies CR8OR's adapter contract and failure handling only. It does not replace network-specific API conformance tests, OAuth permission checks, or official documentation review for a real adapter.