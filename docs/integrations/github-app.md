# GitHub App integration

CR8OR connects a GitHub App installation through the existing `IntegrationConnection` model. An organization-scoped connection can serve multiple Enterprises, but repository access is granted to an Enterprise only after an administrator explicitly assigns a discovered repository.

## GitHub App configuration

Create a GitHub App with:

- **Repository permissions:** Metadata: Read-only; Issues: Read-only.
- **Subscribe to events:** `issues`, `installation`, and `installation_repositories`.
- **Webhook URL:** `https://<cr8or-host>/integrations/webhooks/github`.
- **Webhook secret:** a dedicated random secret, not the App private key.
- **Projects V2:** do not grant Projects permissions for this phase. Projects V2 synchronization is a separate extension and is not required for issue synchronization.

Configure these environment variables on each CR8OR deployment:

| Variable | Purpose |
| --- | --- |
| `GITHUB_APP_ID` | GitHub App numeric ID. |
| `GITHUB_APP_PRIVATE_KEY` | GitHub App PEM private key. For dotenv, represent PEM line breaks as escaped `\n` sequences; CR8OR expands them before signing. |
| `GITHUB_APP_WEBHOOK_SECRET` | Secret configured in the GitHub App webhook settings. |
| `GITHUB_TIMEOUT` | GitHub API request timeout in seconds. |

The private key and webhook secret are environment-backed secrets. Never put them in `IntegrationConnection.configuration`, metadata, or the credential reference. The connection's `github_installation_id` is an external identifier, not a credential.

## Connect an installation

1. Install the CR8OR GitHub App on the intended GitHub user or organization and select the repositories it may access.
2. Copy the numeric installation ID from the installation settings URL (`/settings/installations/<id>` for a user or `/organizations/<org>/settings/installations/<id>` for an organization).
3. In CR8OR, create an Integration Connection for the owning organization, select provider **GitHub**, and enter the GitHub App Installation ID.
4. Keep the connection organization-scoped (`Enterprise` empty) when the installation should serve multiple Enterprises. Use an Enterprise-scoped connection only when the installation must be restricted to one Enterprise.
5. Use **Discover GitHub repositories** on the connection. Discovery is queued and only persists repositories returned by that installation's authenticated `/installation/repositories` endpoint.
6. In **GitHub Repositories**, explicitly assign each repository to one Enterprise. A repository cannot be assigned to a different Enterprise until it is unassigned first.
7. Use **Synchronize issues** on an assigned repository for a manual resync. Scheduled reconciliation rediscovers repository access for active GitHub App installations and queues active assigned repositories every 15 minutes.

A connection requires a valid GitHub App installation ID. The App ID and private key are shared deployment configuration, while installation identity and ownership are persisted per connection. Ensure the Laravel queue worker is running for discovery, webhook processing, and synchronization jobs, and ensure the Laravel scheduler is invoked every minute for the 15-minute reconciliation schedule.

## Token and webhook security

- CR8OR signs short-lived RS256 App JWTs and exchanges them for installation access tokens.
- Installation tokens are cached until five minutes before expiry and are not written to application tables.
- Token requests explicitly request only `issues: read` and `metadata: read`.
- Suspended, deleted, disabled, revoked, or reconnect-required connections are not used for GitHub API requests.
- GitHub webhooks are verified against the exact raw request body using `X-Hub-Signature-256` before JSON parsing.
- `X-GitHub-Delivery` is unique. Duplicate deliveries are acknowledged without enqueueing duplicate work.
- The GitHub `ping` event is acknowledged without further processing. Only the `issues`, `installation`, and `installation_repositories` event families are processed. The handler persists a bounded sanitized payload, not the raw webhook body.
- Webhook handlers enqueue work; they do not perform full issue synchronization in the HTTP request.
- Issue webhooks trigger a fresh API read/sync. A `deleted` issue event triggers a GET; a confirmed 404 marks the local snapshot as deleted without removing its history.

## Legacy repository migration

Existing `Enterprise.github_repository` and `github_repository_url` values remain as a compatibility source until they have been resolved safely. After configuring the GitHub App or a supported legacy credential, run:

```bash
php artisan github:migrate-legacy-repositories
```

The command resolves each configured `owner/repository` through GitHub, uses the returned stable repository ID, creates the repository association, and links existing issue snapshots without deleting them. It reports unresolved entries (for example, missing connection, revoked access, or an inaccessible repository) for administrative remediation. It does not guess repository IDs.

Run this migration before assigning additional repositories to an Enterprise that already has a legacy repository setting. Do not remove legacy configuration or static credential settings until the command reports no unresolved repositories and all deployment consumers have moved to GitHub App installations. Connections without a GitHub installation ID can still resolve their existing opaque credential references during the transition. Remove those static credentials only after every remaining legacy connection has been migrated or retired.

## Projects V2

Projects V2 is deliberately not enabled by this implementation. Its custom Status field is not GitHub's `Issue.state`; a later extension must use a separate GraphQL synchronization model and optional Projects permissions.
