# Native social authorization and credential lifecycle

## Scope and security boundary

CR8OR owns authorization initiation, callback validation, identity discovery, destination selection, encrypted credential storage, refresh, disconnect, reauthorization, and safe audit records. Publishing adapters remain a separate boundary. A connected identity is represented by `SocialIdentity`; publishable network destinations are `SocialAccount` records; the authorization grant is represented by an `IntegrationConnection`; raw token material is stored only in the encrypted `SocialCredential` payload and linked by an opaque `credential_reference`.

The web entry points are authenticated and verified-user routes:

- `POST /social/authorization/{network}/start`
- `GET /social/authorization/callback/{provider}`
- `GET /social/authorization/attempts/{attempt}/destinations`
- `POST /social/authorization/attempts/{attempt}/destinations`
- `POST /social/connections/{connection}/disconnect`

The initiating actor, organization, optional enterprise/channel, provider and network are persisted on a short-lived authorization attempt. State is generated from cryptographically secure random bytes; only its SHA-256 hash is persisted. State is single-use and consumed under a database lock. PKCE is enabled for providers whose configuration requires it, with the verifier encrypted at rest. Callback handling rechecks actor identity and current organization/enterprise authorization, verifies provider and state, and uses the exact configured redirect URI. Provider endpoints must use HTTPS, and HTTP redirects are disabled for token/discovery calls.

Access and refresh tokens are never placed in `IntegrationConnection.configuration`, metadata, authorization audit metadata, MCP responses, or the destination-selection view. `SocialCredential.encrypted_payload` uses Laravel's encrypted-array cast and is hidden from ordinary model serialization. `SocialCredential`, `SocialAuthorizationAttempt`, and `SocialAuthorizationAudit` deliberately have deny-all CRUD policies and no generic Filament resource. Use the authorization service and connection lifecycle methods rather than direct CRUD.

Refresh runs under a cache lock keyed by a hash of the credential reference. Rotated access/refresh tokens are stored together. Invalid grants and other definitive refresh failures mark the connection `reconnect_required`; a caller must explicitly reauthorize it. Disconnect disables local use before attempting remote revocation. The result distinguishes confirmed revocation, unsupported revocation, and failed revocation. The credential is deleted after confirmed remote revocation; a failed or unsupported remote revocation leaves the credential retained but the local connection disabled so it cannot be used for publishing.

## Provider setup

Register an application with the network provider, enable the required API products/permissions, configure the secrets in the deployment secret store, and register the callback URI exactly. Redirect URI values must not be user-controlled. Never put production credentials in `.env.example` or source control.

| Network / auth provider | Environment variables | Callback URI default | Current implementation notes |
|---|---|---|---|
| LinkedIn | `SOCIAL_LINKEDIN_CLIENT_ID`, `SOCIAL_LINKEDIN_CLIENT_SECRET`, optional `SOCIAL_LINKEDIN_REDIRECT_URI`, optional `SOCIAL_LINKEDIN_API_VERSION` | `/social/authorization/callback/linkedin` | OAuth 2.0; discovers the member profile and organizations for which the member has an ACL. Publishing requires approved app access and eligible scopes/roles. |
| Facebook Login | `META_APP_ID`, `META_APP_SECRET`, optional `META_SOCIAL_REDIRECT_URI`, optional `META_GRAPH_API_VERSION` | `/social/authorization/callback/meta` | Facebook Login discovers eligible Facebook Pages and stores Page tokens encrypted per destination. Facebook publishing requires `pages_show_list`, `pages_read_engagement` and `pages_manage_posts`; Instagram permissions are not requested on this flow. App review/Advanced Access and Page task requirements apply. |
| Instagram Login | `META_APP_ID`, `META_APP_SECRET`, optional `INSTAGRAM_SOCIAL_REDIRECT_URI`, optional `META_GRAPH_API_VERSION` | `/social/authorization/callback/instagram` | Dedicated Instagram Login flow. Uses `https://www.instagram.com/oauth/authorize`, exchanges the code at `https://api.instagram.com/oauth/access_token`, upgrades/refreshes tokens through `graph.instagram.com`, and discovers the authorized Business or Creator account without requiring a Facebook Page. Requests `instagram_business_basic` and `instagram_business_content_publish`. Register this callback in the Instagram Login / Business Login settings of the Meta app. Existing Facebook Login Instagram grants remain supported for already-connected accounts; new Instagram authorization uses this dedicated flow. |
| TikTok | `SOCIAL_TIKTOK_CLIENT_KEY`, `SOCIAL_TIKTOK_CLIENT_SECRET`, optional `SOCIAL_TIKTOK_REDIRECT_URI` | `/social/authorization/callback/tiktok` | OAuth 2.0 with PKCE and TikTok's `client_key` parameter convention. Product approval and scopes depend on the TikTok developer application. |
| YouTube | `SOCIAL_YOUTUBE_CLIENT_ID`, `SOCIAL_YOUTUBE_CLIENT_SECRET`, optional `SOCIAL_YOUTUBE_REDIRECT_URI` | `/social/authorization/callback/youtube` | Google OAuth 2.0 with PKCE, offline access and refresh support. API quota and project verification rules apply. |
| Threads | `SOCIAL_THREADS_CLIENT_ID`, `SOCIAL_THREADS_CLIENT_SECRET`, optional `SOCIAL_THREADS_REDIRECT_URI` | `/social/authorization/callback/threads` | Threads OAuth 2.0 and configured long-lived token refresh. API permissions depend on the Meta developer application. |

Default callback base URL is `APP_URL`. Configure explicit `*_REDIRECT_URI` values in each deployment where the externally visible URL differs. The provider must have the exact full URI registered, including scheme, host, path and any configured port.

## Operational settings

- `SOCIAL_AUTH_TIMEOUT`: provider HTTP timeout in seconds.
- `SOCIAL_AUTH_ATTEMPT_TTL_MINUTES`: lifetime of an unfinished authorization attempt.
- `SOCIAL_AUTH_REFRESH_LOCK_SECONDS`: lease for a refresh lock.
- `SOCIAL_AUTH_REFRESH_LOCK_WAIT_SECONDS`: maximum wait to acquire the refresh lock.
- `SOCIAL_AUTH_REFRESH_LEEWAY_SECONDS`: refresh access tokens this many seconds before expiry.
- `SOCIAL_LINKEDIN_API_VERSION`: versioned LinkedIn Marketing API header; default `202609`, which must be reviewed against LinkedIn's current version migration schedule.
- `META_GRAPH_API_VERSION`: Graph API version for Facebook Login and Instagram Login Graph API endpoints; default `v26.0`. Upgrade only after reviewing Meta's version changelog and rerunning both adapter test suites.

Use a shared cache backend with atomic lock support in multi-instance deployments. Configure the database and cache before enabling social authorization. All app instances must use the same encryption key and compatible credential storage.

## Social account metrics

Facebook Page and Instagram professional-account follower metrics are refreshed by scheduled commands, not during Enterprise Portfolio rendering. `social-accounts:sync-facebook-follower-counts` and `social-accounts:sync-instagram-follower-counts` run every six hours. The Instagram synchronizer reads `user_id`, `username`, and `followers_count` from the versioned Instagram Graph API using the Instagram Login token. It persists a canonical `https://www.instagram.com/{username}/` profile URL only when the returned username matches the allowed Instagram username format. A missing or invalid username leaves the last known URL unchanged; invalid counts and provider failures leave the last known metrics intact. The command reports per-account failures and continues processing other accounts.


## Failure and recovery semantics

- Invalid, expired, replayed, actor-mismatched, or provider-mismatched state is rejected before token exchange.
- Provider denial is recorded as a terminal denied attempt without storing tokens.
- Invalid refresh grants and revoked/expired grants require reauthorization; they are not retried indefinitely.
- Unsupported remote revocation is not reported as success. Local disablement still prevents future credential use.
- Destination selection accepts only destinations discovered for the selected network and rechecks channel/enterprise/organization access before mutation.
- Audit metadata contains safe failure codes, action names, scope counts and correlation identifiers, not authorization codes, access tokens, refresh tokens or client secrets.

## Validation status and deployment caveats

Automated tests use fake HTTP responses; they do not prove that a provider application has been approved or that its live permissions, redirect registrations, quotas, or account eligibility are correct. Before production enablement, verify each network's current official developer documentation and app configuration, perform a live consent/discovery/refresh/revocation rehearsal with a dedicated test account, and confirm secret rotation/monitoring procedures.