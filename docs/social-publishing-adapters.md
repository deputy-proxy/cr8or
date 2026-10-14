# Social Publishing Adapter Contracts

Native adapter enablement is controlled by the `SOCIAL_PUBLISHING_<NETWORK>_ENABLED` flags (for example, `SOCIAL_PUBLISHING_LINKEDIN_ENABLED`). Every flag defaults to `false`. Follow [the production-readiness and release gates](social-publishing-readiness.md) and run `php artisan social:publishing-readiness` before enabling any network; configuration preflight does not replace provider app approval or a live-account test.

## Purpose and boundaries

CR8OR owns publication authorization, destination validation, scheduling, idempotency, lifecycle state, reconciliation and history. A publishing adapter performs one network's external publishing operation; it does not own business authorization, tenancy, publication state or workflow orchestration.

OAuth authorization and connected-account discovery remain in the existing `SocialAuthorizationProvider` boundary. Publishing adapters receive an organization-scoped `IntegrationConnection` identifier and an opaque credential reference. They must never receive or persist raw access/refresh tokens through publishing request DTOs or result payloads.

No MCP-specific adapter layer is introduced. MCP tools continue to delegate to the existing capability/operation boundary and must not contain provider logic.

## Current governed public surface

The authenticated web lifecycle owns OAuth initiation, callback validation, provider identity/credential creation, destination discovery and attachment, refresh, and provider revocation. These actions are not exposed as generic MCP connection/account CRUD. Social destinations are read through enterprise-scoped list/get tools; metadata updates and local destination disablement use the governed `SocialAccountUpdate` and `SocialAccountDisable` Operations. Local disablement is explicitly distinct from provider revocation.

Publishing is exposed through `publication.publish` / `PublishContent`; status reconciliation is exposed through `publication.status.reconcile` / `ReconcilePublicationStatus`. Publication and result discovery returns only scoped, safe fields and omits raw provider payloads, access tokens and credential references. Both Capabilities can be configured in persisted, published `WorkflowVersion` stages with normal stage-contract validation. Deterministic and interactive publishing does not require a model provider.

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


## Facebook Pages adapter (issue #526)

`App\Services\Publishing\FacebookPublishingAdapter` supports immediate posts to a selected Facebook Page through the configured Meta Graph API version. Supported formats are text posts, link posts (the first HTTPS URL in the message is also supplied as the Graph `link` field), and one JPEG/PNG image stored in CR8OR's canonical R2 media layer. Text/link posts use `POST /{page-id}/feed`; images use the single-request `POST /{page-id}/photos` URL upload flow. Images are published directly, so there is no separate unpublished-upload phase to leave partially completed.

The destination must be a numeric Facebook Page ID. Personal profiles, Page names, video, multi-image posts, native scheduling, editing, deletion and analytics are unsupported and fail closed. The adapter requires the destination-specific Page token stored in the encrypted `SocialTokenSet.destinationTokens` entry keyed by `facebook:{page-id}`; it never falls back to the parent Meta user token. The shared authorization discovery requests Page `tasks` and only offers Pages with `CREATE_CONTENT` or `MANAGE`. When the granted scope list is unavailable, the adapter checks `/me/permissions` before submission. Missing Page tokens and expired tokens map to authentication failure; insufficient scopes, Page tasks or app access map to authorization failure without automatically disabling the entire Meta connection.

R2 images must exist on the `r2` disk, be JPEG/PNG, and be no larger than 10 MiB. The adapter generates a 15-minute HTTPS R2 URL for Meta to fetch and never stores that signed URL or the Page token in publication metadata. Only the returned Page post ID is accepted as the external publication ID. Network timeouts, server errors, and successful responses without a valid post ID are ambiguous and block blind retries through CR8OR's publication lifecycle. Meta rate-limit responses are retryable but not ambiguous. Known post IDs can be reconciled through Graph object lookup; 404 returns unknown status.

The adapter remains **disabled** in `config/publishing.php`. Production availability requires a Meta app with the appropriate Pages permissions and any required Advanced Access/App Review, plus a Page where the authorizing person has content-creation tasks. Configure `META_GRAPH_API_VERSION` consistently across authorization, discovery and publishing; the current default is `v26.0` (released July 29, 2026). Verify the [Graph API version schedule](https://developers.facebook.com/docs/graph-api/changelog/versions/), [Page Feed API](https://developers.facebook.com/docs/graph-api/reference/page/feed/), [Page Photos API](https://developers.facebook.com/docs/graph-api/reference/page/photos/), [Post API](https://developers.facebook.com/docs/graph-api/reference/post/), and [Permissions reference](https://developers.facebook.com/docs/permissions/).

## Instagram professional-account adapter (issue #527)

`App\Services\Publishing\InstagramPublishingAdapter` supports immediate single-image posts for eligible Instagram professional accounts discovered through the existing Meta authorization flow. The destination identifier is the Instagram professional-account ID, not the linked Facebook Page ID. The shared Meta connection stores a destination-specific token under `instagram:{instagram-user-id}`; the adapter never falls back to the parent user token or treats the Page identity as the Instagram destination.

The implemented format is deliberately narrow: one JPEG image, 8 MiB maximum, dimensions known in media metadata, each side at least 320 pixels, width no greater than 1440 pixels, and aspect ratio from 4:5 through 1.91:1. Captions are limited to 2,200 characters; an optional image `alt_text` value is passed through when supplied and validated to 1,000 characters. Text-only posts, PNG, video/Reels, carousels, native scheduling, editing, deletion and analytics are unsupported and rejected before remote submission. The adapter creates an Instagram media container, polls its `status_code` with a bounded configurable window, and calls `/{ig-user-id}/media_publish` only after the container reports `FINISHED`. `ERROR`/`EXPIRED` containers and processing timeouts do not proceed to publication.

The adapter requires the Meta `instagram_content_publish` permission for publication and `instagram_basic` for known-media status lookup. Before creating a media container it queries `/{ig-user-id}/content_publishing_limit`, validates the returned usage/limit fields, and blocks submission if the quota is exhausted or cannot be verified. Meta rate-limit responses map to a retryable, non-ambiguous failure. Shared account discovery only exposes Instagram professional accounts linked to Pages where the authorizing user has `CREATE_CONTENT` or `MANAGE` tasks. It uses the configured Meta Graph API version, R2-hosted media via a short-lived HTTPS URL, and the existing active Meta `IntegrationConnection` plus opaque credential reference. A network timeout or server-side ambiguous result during final publication blocks blind retries through CR8OR's publication lifecycle. Once the media ID is known, status reconciliation fetches the media object and verifies its returned ID and media type. A lost final response before the media ID is known remains an unknown outcome requiring operational reconciliation; do not automatically resubmit it.

The adapter remains **disabled** in `config/publishing.php`. Enablement requires a Meta app with the relevant Instagram permissions and required App Review/Advanced Access, a supported professional account linked through the authorized Page flow, and a real-account verification run. Container polling can be tuned with `SOCIAL_INSTAGRAM_CONTAINER_POLL_ATTEMPTS` (default 5) and `SOCIAL_INSTAGRAM_CONTAINER_POLL_INTERVAL_MS` (default 250 ms).

Official references: [Instagram API with Facebook Login: Content Publishing](https://developers.facebook.com/docs/instagram-platform/instagram-api-with-facebook-login/content-publishing/), [Instagram User Media endpoint](https://developers.facebook.com/docs/instagram-platform/reference/ig-user/media/), [Instagram Media Publish endpoint](https://developers.facebook.com/docs/instagram-platform/reference/ig-user/media_publish/), [Content Publishing Limit endpoint](https://developers.facebook.com/docs/instagram-platform/reference/ig-user/content_publishing_limit/), and [Meta Graph API version schedule](https://developers.facebook.com/docs/graph-api/changelog/versions/). Verify current account eligibility, app access, permissions and media restrictions before enabling the adapter in production.

## Threads publishing adapter (issue #531)

`App\Services\Publishing\ThreadsPublishingAdapter` uses the dedicated Threads OAuth connection (`provider=threads`) and requires both `threads_basic` and `threads_content_publish`. It validates the canonical numeric Threads user ID against the active connection and its `SocialIdentity`; Threads is not treated as a Meta Page or Instagram destination. The adapter remains disabled by default in `config/publishing.php`.

The supported envelope is text-only posts, one JPEG/PNG image, or one MP4/MOV video. Text is limited to 500 Unicode characters. Images are capped at 8 MiB; videos are capped at 1 GiB and five minutes, and must include a known duration. Media must be stored on the canonical R2 disk and is passed to Threads through a temporary HTTPS URL with a configurable expiry. Optional metadata is read from `ContentItem.metadata.threads`: `topic_tag` (1–50 characters, without a leading `#`) and `reply_control` (`everyone`, `accounts_you_follow`, or `mentioned_only`). Image/video alt text is read from the selected media asset metadata. Carousel posts and long-form text attachments are intentionally unsupported and rejected rather than silently degraded.

Publishing follows the Threads container lifecycle: create a container, persist its ID and phase inside encrypted `PublishingJob.adapter_state_encrypted`, check container status, then call `threads_publish` only when the container is `FINISHED`. A container still processing returns `pending`; the existing bounded `ReconcilePublicationStatusJob` advances it later. The publish phase is persisted before the remote publish request. If that request times out or returns an ambiguous result, CR8OR marks the publication outcome unknown and never submits that container again automatically. This is deliberate: the container ID is not substituted for the published post ID or permalink. A known post can be reconciled through its returned ID and the provider permalink when available.

Configure `SOCIAL_THREADS_CLIENT_ID`, `SOCIAL_THREADS_CLIENT_SECRET`, and `SOCIAL_THREADS_REDIRECT_URI` for the existing Threads authorization provider. `SOCIAL_THREADS_API_VERSION` defaults to `v1.0`; `SOCIAL_THREADS_MEDIA_URL_EXPIRY_MINUTES` defaults to 60 and is clamped to a safe 15–180 minute range. Before enabling the adapter, verify the Meta app's Threads API use case, OAuth redirect URI, granted publishing scopes, and app-review/access requirements. The adapter has no native scheduling, carousel, editing, deletion, or analytics support. It is not auto-enabled when OAuth credentials are present.

Official references: [Meta Threads API collection and publishing examples](https://www.postman.com/meta/threads/documentation/dht3nzz/threads-api), [create a video container](https://www.postman.com/meta/threads/request/mev9xf8/1-3-create-video-container), and [Threads API getting started](https://developers.facebook.com/docs/threads/get-started/). Recheck current limits and app requirements against Meta's documentation before production enablement.

## YouTube channel and video adapter (issue #530)

`App\Services\Publishing\YouTubePublishingAdapter` uses the existing Google OAuth connection and encrypted `SocialCredentialStore`. It requires the `youtube.upload` and `youtube.readonly` scopes, validates the stored connection/identity/organization relationship, and verifies the authenticated channel with `channels.list(mine=true)` before every upload and status lookup. YouTube's upload API publishes to the channel represented by the authenticated Google identity; it cannot be redirected to an arbitrary channel ID. CR8OR therefore fails closed if the requested destination differs, the identity returns multiple channels, or channel identity cannot be confirmed. A video longer than 15 minutes is rejected unless YouTube reports `longUploadsStatus=allowed` for that channel.

The adapter supports one video from the canonical R2 disk. The configured size ceiling defaults to 256,000,000,000 bytes (256 GB), and the duration ceiling is 12 hours. Accepted declared containers are MP4, QuickTime/MOV, WebM, MPEG, 3GPP and AVI; the adapter checks container signatures, stream size and any registered SHA-256 checksum before upload. YouTube metadata is read from `ContentItem.metadata.youtube`: `title` (required, at most 100 characters), `description` (at most 5,000), `tags`, optional numeric `category_id`, `privacy_status` (`private`, `unlisted` or `public`), optional `made_for_kids`, `contains_synthetic_media`, `license` (`youtube` or `creativeCommon`), and `notify_subscribers`. The default privacy is `private`; this is a conservative default, not a claim that YouTube will allow the requested visibility. YouTube/API policy restrictions can keep videos private, so CR8OR confirms the returned privacy status during reconciliation rather than claiming immediate public availability.

Uploads use YouTube's resumable protocol. The initial session URL and the verified media/metadata fingerprint are stored only inside encrypted `PublishingJob.adapter_state_encrypted`. Each retry reuses the same session and queries YouTube's acknowledged byte range; it never creates a new upload merely because a chunk response timed out. A completed upload's video ID is persisted in encrypted state before status reconciliation. If the session expires before a video ID can be recovered, the adapter reports an ambiguous outcome and does not automatically create a duplicate video. Remote processing is asynchronous: the first successful upload result is `pending`, and a queued `ReconcilePublicationStatusJob` performs bounded status checks. The job records publication success/failure only after the remote processing result is definitive. Upload sessions, access tokens and provider error bodies are not included in public result payloads or logs.

The adapter remains **disabled** in `config/publishing.php`. Configure `SOCIAL_YOUTUBE_CLIENT_ID`, `SOCIAL_YOUTUBE_CLIENT_SECRET` and `SOCIAL_YOUTUBE_REDIRECT_URI` for the existing Google authorization provider. Optional publishing controls are `SOCIAL_YOUTUBE_MAX_UPLOAD_BYTES`, `SOCIAL_YOUTUBE_DEFAULT_PRIVACY_STATUS`, `SOCIAL_YOUTUBE_DEFAULT_CATEGORY_ID`, `SOCIAL_YOUTUBE_NOTIFY_SUBSCRIBERS`, `SOCIAL_YOUTUBE_UPLOAD_TIMEOUT`, and `SOCIAL_YOUTUBE_UPLOAD_CHUNK_SIZE`. Configure the queue connection and bounded status-poll delays using `PUBLISHING_STATUS_QUEUE_CONNECTION`, `PUBLISHING_STATUS_INITIAL_DELAY_SECONDS`, `PUBLISHING_STATUS_POLL_DELAY_SECONDS`, and `PUBLISHING_STATUS_MAX_POLLS`. The queue worker must process the configured connection for status reconciliation to run.

Before production enablement, enable YouTube Data API v3 in the Google Cloud project, register the exact redirect URI, configure the OAuth consent screen, and complete Google's verification/audit requirements applicable to the deployment. Google documents that unverified API clients can have uploaded videos locked as private. YouTube's current quota model has a dedicated `videos.insert` upload quota bucket (currently 100 uploads/day by default) and a separate bucket for other API methods; check the actual project limits in Google Cloud Console instead of assuming quota capacity. The adapter is intentionally not auto-enabled when credentials exist. Run real-account tests with a verified API project, a channel with long-upload eligibility where applicable, and private/unlisted/public outcomes before enabling it.

Official references: [Videos: insert and upload limits](https://developers.google.com/youtube/v3/docs/videos/insert), [resumable upload protocol](https://developers.google.com/youtube/v3/guides/using_resumable_upload_protocol), [YouTube Data API quota overview](https://developers.google.com/youtube/v3/getting-started), [OAuth scopes and verification](https://developers.google.com/youtube/v3/guides/auth/server-side-web-apps), [API revision history and quota changes](https://developers.google.com/youtube/v3/revision_history), and [videos locked as private](https://support.google.com/youtube/answer/7300965). These requirements can change; recheck official documentation and the specific project's quota/audit status before enabling the adapter.

## TikTok creator video adapter (issue #528)

`App\Services\Publishing\TikTokPublishingAdapter` implements the Content Posting API Direct Post video flow for the authenticated creator `open_id`. It queries the current creator information before each submission, validates the creator's current privacy options and interaction restrictions, initializes `POST /v2/post/publish/video/init/`, streams the video from the canonical R2 disk to TikTok's returned upload URL, and returns the stable `publish_id` as a pending result. `POST /v2/post/publish/status/fetch/` reconciles processing, successful publication and definitive failure. The adapter preserves `publish_id` as the external publication identity and records public post IDs only as provider metadata; it does not invent a permalink.

Only one video is supported per publication. Supported containers are MP4, QuickTime/MOV and WebM, with H.264/H.265 for MP4/MOV and VP8/VP9 for WebM. The adapter requires verified metadata for dimensions (360–4096 pixels on each side), duration (positive and no more than 10 minutes or the creator-specific limit, whichever is lower), codec, and frame rate (23–60 FPS). Video size must be known and no more than 4 GiB. Metadata is read from the version's `MediaMetadata` row where available, with compatible version/asset metadata as fallback; missing or incompatible codec/frame-rate metadata fails closed. File uploads use sequential byte ranges, bounded chunk sizes, and a configurable per-chunk timeout. URL-pull upload is intentionally not used because TikTok requires domain/prefix ownership verification for that transfer mode.

Direct posting requires `video.publish`, an approved Content Posting API product and Direct Post configuration, and a TikTok app audit before public visibility can be enabled. The request must carry explicit `consent_to_publish: true`, the creator-selected `privacy_level`, explicit booleans for `disable_comment`, `disable_duet`, `disable_stitch`, and `brand_content_toggle`, plus any optional supported creator settings. The adapter checks these against the latest `creator_info/query` response and rejects unavailable privacy choices or interactions the creator has disabled. It never invents a privacy level or silently enables an interaction. The consent/settings contract must be supplied by a governed CR8OR entry point; the current generic `PublishingService` request does not yet carry these TikTok-specific fields, so the adapter remains disabled until the public publishing workflow supplies and authorizes them (tracked by issue #533).

The initial submission and each chunk upload have different failure semantics. Definitive 4xx API rejection is not marked ambiguous. A timeout or server failure during initialization, a malformed initialization response, or any uncertain chunk upload is marked ambiguous so CR8OR blocks blind resubmission; reconcile the existing `publish_id` instead. The upload URL is treated as a short-lived secret, restricted to HTTPS `tiktokapis.com` hosts, never sent with an Authorization header, and never persisted in result metadata. Access tokens, upload URLs and request headers are not logged or returned.

The adapter remains **disabled** in `config/publishing.php`. Configure `SOCIAL_TIKTOK_UPLOAD_TIMEOUT` for individual upload chunks (default 120 seconds). The app must have the Content Posting API product, `video.publish` approval and user authorization, Direct Post configuration, required app audit, and a real-account verification run before enablement. Upload-to-inbox/draft (`video.upload`) is intentionally not implemented by this adapter; it has different semantics and must not be reported as a completed publication.

Official references: [Direct Post](https://developers.tiktok.com/docs/en/content-posting-api-reference-direct-post), [Query Creator Info](https://developers.tiktok.com/docs/en/content-posting-api-reference-query-creator-info), [Get Post Status](https://developers.tiktok.com/docs/en/content-posting-api-reference-get-video-status), [Media Transfer Guide and video restrictions](https://developers.tiktok.com/docs/en/content-posting-api-media-transfer-guide), and [App Review Guidelines](https://developers.tiktok.com/docs/en/app-review-guidelines). Recheck these documents and the configured app's permissions before enabling the adapter.

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

The shared contract and reusable fake conformance harness remain the baseline for every supported target network. LinkedIn, Facebook, Instagram, TikTok, YouTube and Threads have native adapter implementations, but all remain disabled until deployment-specific app permissions and real-account verification are complete. The matrix records implemented capability boundaries without claiming production readiness.

| Network key | Adapter implementation | Production state | Official API capability verification |
|---|---|---|---|
| `linkedin` | `LinkedInPublishingAdapter` (text-only) | Disabled | Posts API text-only creation and known-post status lookup implemented; media, scheduling, editing, deletion and analytics unsupported |
| `facebook` | `FacebookPublishingAdapter` (text/link + single R2 image) | Disabled | Page Feed, Photos and known-post lookup implemented; video, multi-image, scheduling, editing, deletion and analytics unsupported |
| `instagram` | `InstagramPublishingAdapter` (single JPEG image) | Disabled | Professional-account image container, bounded processing poll, publish and known-media lookup implemented; text-only, PNG, video/Reels, carousel, scheduling, editing, deletion and analytics unsupported |
| `tiktok` | `TikTokPublishingAdapter` (single direct-post video) | Disabled | Creator-info validation, sequential R2 file upload, publish ID and status reconciliation implemented; draft upload, scheduling, editing, deletion and analytics unsupported |
| `youtube` | `YouTubePublishingAdapter` (single R2 video) | Disabled | OAuth-scoped channel verification, resumable video upload, encrypted session recovery and processing-status reconciliation implemented |
| `threads` | `ThreadsPublishingAdapter` (text + one image or video) | Disabled | Container lifecycle and known-post reconciliation implemented; carousel, native scheduling, editing, deletion and analytics unsupported |

The six `Fake*PublishingAdapter` classes in tests exist only to run the same contract suite against every currently supported target key. Their limits and capabilities are test fixtures, not claims about the real networks. When a real adapter is implemented, its contract dataset entry must be switched to that adapter and its declaration reviewed against official API documentation, account-type restrictions, permissions and media limits before production enablement.

## Reusable conformance suite

Run:

```bash
php artisan test tests/Feature/PublishingAdapterContractTest.php tests/Feature/PublishingTest.php
```

The shared suite runs against all six supported-network fake adapters and covers:

- a complete capability declaration and canonical network identity;
- successful normalized results with correlation/idempotency propagation;
- deterministic idempotent retries and status lookup;
- rejection, authentication failure, rate limiting and ambiguous timeout classification;
- unknown status without fabricating a remote result;
- invalid/conditional capability declarations;
- unknown networks, duplicate adapter keys, network mismatch and optional-interface/capability mismatch.

The fake suite verifies CR8OR's adapter contract and failure handling only. It does not replace network-specific API conformance tests, OAuth permission checks, or official documentation review for a real adapter.