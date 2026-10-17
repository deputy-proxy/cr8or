# Native Social Publishing Production Readiness

## Release rule

All six currently supported native adapters are **code-complete candidates, disabled by default, and not production-approved by this repository alone**. Mocked tests prove CR8OR's contracts and failure handling; they do not prove provider app approval, real-account eligibility, live API behavior, quota availability, or production credentials. Do not enable a network until its row below has passed both automated checks and the deployment-specific manual gate.

Run `php artisan social:publishing-readiness` after configuring the deployment and before enabling a network. The command validates only enabled adapters, reports configuration key names and safe diagnostics, and never prints credential values. A passing preflight is necessary but not sufficient for production approval.

## Network readiness matrix

| Network | Adapter enable flag | Authorization configuration | Verified code boundary | Required manual production gate |
|---|---|---|---|---|
| LinkedIn | `SOCIAL_PUBLISHING_LINKEDIN_ENABLED` | `SOCIAL_LINKEDIN_CLIENT_ID`, `SOCIAL_LINKEDIN_CLIENT_SECRET`, `SOCIAL_LINKEDIN_REDIRECT_URI` | Immediate text-only member/organization posts; known-post lookup; canonical URN and active-connection checks | Confirm app products/scopes (`w_member_social` and, for organization posts, `w_organization_social`), eligible member role, API version, and a real-account publish/reconcile test |
| Facebook Pages | `SOCIAL_PUBLISHING_FACEBOOK_ENABLED` | `META_APP_ID`, `META_APP_SECRET`, `META_SOCIAL_REDIRECT_URI` | Page text/link and single JPEG/PNG image; destination-specific Page token; post lookup | Confirm required Pages permissions/Advanced Access, App Review, Page tasks, token behavior, Graph API version and a real Page publish/reconcile test |
| Instagram | `SOCIAL_PUBLISHING_INSTAGRAM_ENABLED` | `META_INSTAGRAM_APP_ID`, `META_INSTAGRAM_APP_SECRET`, and `META_INSTAGRAM_REDIRECT_URI` | One eligible professional-account JPEG image or 2–10 item JPEG carousel through bounded container lifecycles; known-media lookup | Confirm Instagram Login callback registration, eligible professional account, `instagram_business_basic` and `instagram_business_content_publish`, app access/review, publishing limits and a real-account test |
| TikTok | `SOCIAL_PUBLISHING_TIKTOK_ENABLED` | `SOCIAL_TIKTOK_CLIENT_KEY`, `SOCIAL_TIKTOK_CLIENT_SECRET`, `SOCIAL_TIKTOK_REDIRECT_URI` | One creator-authorized video Direct Post; bounded chunk upload and status lookup | Confirm Content Posting API product, `video.publish`, Direct Post configuration, app audit/public-visibility approval, creator settings/consent path and a real-account test |
| YouTube | `SOCIAL_PUBLISHING_YOUTUBE_ENABLED` | `SOCIAL_YOUTUBE_CLIENT_ID`, `SOCIAL_YOUTUBE_CLIENT_SECRET`, `SOCIAL_YOUTUBE_REDIRECT_URI` | Single R2 video, resumable upload recovery, channel identity check and processing reconciliation | Enable YouTube Data API v3, verify OAuth consent/app review, upload quota and channel eligibility; test private/unlisted/public policy outcomes with a verified project |
| Threads | `SOCIAL_PUBLISHING_THREADS_ENABLED` | `SOCIAL_THREADS_CLIENT_ID`, `SOCIAL_THREADS_CLIENT_SECRET`, `SOCIAL_THREADS_REDIRECT_URI` | Dedicated Threads identity/scopes; text, one image or video; container lifecycle and known-post lookup | Confirm Threads API use case, publishing scopes, redirect registration, app review/access and a real-account publish/reconcile test |

The exact format limits, unsupported features, provider endpoints and official documentation links are maintained in `docs/social-publishing-adapters.md`. Recheck official provider documentation and the actual app's granted scopes before release; limits and review rules change independently of CR8OR deployments.

## Security and reliability gates

Before enabling a network, verify the following against the current code and deployment:

- **Authorization and tenancy:** OAuth start/callback/attach/disconnect requires the current authenticated actor and organization/enterprise policy; destination and connection identity must match provider, organization, external ID and requested network. Test actor revocation and cross-organization/cross-enterprise denial.
- **OAuth callback safety:** state is single-use and time-bound; callbacks reject missing/expired/replayed state, mismatched provider/issuer, invalid redirect configuration and untrusted destinations. Do not accept arbitrary callback URLs from requests.
- **Credential lifecycle:** raw access/refresh tokens and provider secrets live only in the encrypted credential store. Refresh uses a per-credential lock; invalid grants transition connections to reconnect-required. Local disablement is not proof of remote revocation.
- **Secret-safe diagnostics:** review Laravel logs, exception messages, audit metadata, queue failures, MCP/Agent output and traces for tokens, authorization codes, upload-session URLs, signed media URLs, client secrets and sensitive request headers. Record canonical failure codes and correlation IDs instead of provider response bodies.
- **Idempotency and ambiguity:** duplicate dispatch must reuse the durable publication/job identity. A timeout after possible remote acceptance is an unknown outcome, not permission to submit again. Verify retries, worker crashes, concurrent attempts and delayed/out-of-order reconciliation for each adapter.
- **Expiry/revocation:** test a connection disabled, expired, revoked or marked reconnect-required before scheduling and again immediately before submission. Jobs must fail safely and resume only after valid reauthorization.
- **Rate limits:** only bounded, explicitly retryable failures may be retried. Permanent validation/authentication/permission failures must not enter an unbounded retry loop. Honor each provider's own quota and backoff semantics.
- **Asynchronous state:** verify pending upload/container states, bounded poll count, terminal failures, dead-end/unknown outcomes and operator-visible correlation. Status polling requires a working queue worker on the configured `PUBLISHING_STATUS_QUEUE_CONNECTION`.
- **Webhook authenticity:** verify raw-body HMAC signatures with constant-time comparison, require stable delivery IDs, deduplicate replays and reject invalid signatures before ingestion. Never treat webhook payloads as authorization.

Relevant regression suites include `SocialAuthorizationLifecycleTest`, `IntegrationBoundaryTest`, `IntegrationResultReconciliationTest`, `PublishingTest`, `PublishingAdapterContractTest`, all six supported network adapter tests, `SocialPublishingPublicSurfaceTest`, `PublishingStatusReconciliationCapabilityTest`, and `WorkflowPublishingCapabilityTest`. Passing tests do not replace the real-account gates above.

## Configuration and operational checks

1. Keep every `SOCIAL_PUBLISHING_*_ENABLED` flag false while configuring credentials and provider-side application settings.
2. Use `php artisan social:publishing-readiness` to validate the enabled adapter's provider mapping, required credentials, callback path/URL, adapter contract, and the configured status-reconciliation queue. The command intentionally never prints secret values.
3. Ensure production `APP_URL` and OAuth callback URLs use HTTPS and the provider console has the exact callback URI registered.
4. Ensure the configured queue connection exists and workers process it. Set `PUBLISHING_STATUS_INITIAL_DELAY_SECONDS`, `PUBLISHING_STATUS_POLL_DELAY_SECONDS` and `PUBLISHING_STATUS_MAX_POLLS` within the supported bounds. Monitor terminal queue failures and unknown publication outcomes using existing operational tooling.
5. Verify Cloudflare R2 is configured as the canonical `r2` disk, that workers can read selected media, and that generated signed URLs expire within the network's documented limits. Never log signed URLs.
6. Enable one network at a time only after its app-review and real-account tests pass. Begin with a small, supervised test destination; confirm remote status reconciliation and duplicate prevention before increasing traffic.
7. Record deployment owner, network, app/project identity, approval status, test destination, test timestamp, scopes, quota/usage limits and rollback decision in the release record. Do not commit credentials or production identifiers to this repository.

## Migration and rollback rehearsal

No release may silently delete or rewrite historical publication, result, connection or identity records. Before deploying schema changes:

1. Restore a recent production-like database backup into an isolated environment and verify its integrity.
2. Run `php artisan migrate:status` and review the migration plan; use `php artisan migrate --pretend` where supported to inspect SQL before applying it.
3. Apply migrations to the restored copy, run the social identity, authorization lifecycle, publishing and reconciliation suites, and verify historical publication/result relationships and credential references remain interpretable.
4. Test forward recovery for interrupted migrations. Prefer additive/expand-contract changes; do not assume rolling back a migration can restore deleted historical data.
5. Verify the prior application version can safely read the resulting schema before using a rolling deployment. If not, require a coordinated deployment and documented forward-recovery procedure.
6. Confirm disabled adapters remain disabled after config cache rebuild and deployment rollback. Do not automatically re-enable publishing based only on the presence of OAuth credentials.

## Current release status

- Code-level adapter and shared-contract tests: maintained in CI.
- Configuration preflight: `social:publishing-readiness`.
- Deployment-specific provider approvals, credentials, live-account tests, quota checks, migration rehearsal and production sign-off: **not verifiable from source code alone; required per deployment and network**.
- Default network enablement: **all disabled**.