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
| `linkedin` | Not implemented | Disabled | Pending adapter-specific research |
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