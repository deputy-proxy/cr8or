# Social Publishing Migration Audit

**Audit date:** 2026-10-12
**Repository baseline:** `main` at `6cf8dcb7` (PR #517)
**Issue:** [#519 Audit social publishing dependencies and define migration boundary](https://github.com/deputy-proxy/cr8or/issues/519)
**Parent initiative:** [#518 Native multi-network social publishing architecture](https://github.com/deputy-proxy/cr8or/issues/518)

> **Historical baseline snapshot:** This inventory describes the repository at `6cf8dcb7` before issues #520–#523. Issue #522 introduced destination-aware adapter resolution, and issue #523 removed Postiz from active runtime code, configuration, tests and current-state documentation. The historical inventory below is retained to explain the migration decision; it is not a current runtime inventory. Production data, deployed environment variables and external provider state were not accessible to this source audit and still require operational verification before retiring any deployed credentials or data.

This document records the tracked-repository audit for the Postiz removal and native social-publishing migration. It does not claim access to production databases, deployment dashboards, external provider accounts, or untracked environment variables. Those require separate operational verification before a destructive migration or credential retirement.

## Executive findings

1. The current publishing execution path is **single-provider and Postiz-specific**. `PublishingService` injects one `PublishingProvider`; `AppServiceProvider` binds it to `PostizPublishingProvider` outside testing and `FakePublishingProvider` in testing. Both expose `providerKey() === 'postiz'`.
2. CR8OR already owns the publication lifecycle and historical records. It creates `Publication`, `PublicationSchedule` and `PublishingJob` records, validates publication readiness and tenant/channel/account consistency, checks Agent capability authorization, uses idempotency keys, records provider outcomes in `PublicationResult`, and restricts lifecycle mutations. These guarantees must survive the adapter migration.
3. Social-account registration is **not OAuth**. `ConnectSocialAccountTool` and the corresponding domain operation register a provider/account identity supplied to CR8OR. The inspected code does not implement OAuth state/callback validation, provider account discovery, token refresh, revocation, or reauthorization for social networks.
4. `SocialAccount` and `IntegrationConnection` are separate models without a direct relationship or an established credential lifecycle joining them. The generic `CredentialResolver` contract is currently bound to `CanvaCredentialResolver`; it cannot resolve social publishing credentials.
5. The generic integration webhook endpoint reconciles `IntegrationJob` / `IntegrationResult` records. The inspected path does not connect that reconciliation to the separate `PublishingJob` / `PublicationResult` lifecycle. A configured Postiz webhook secret must not be mistaken for verified publication webhook reconciliation.
6. No Postiz-specific schema columns or migrations were found. Provider identifiers are stored as ordinary string values in several tables, so existing records may still contain `postiz`. Production data is not available to this audit and must be inventoried before removal.
7. The repository contains a finite, verified set of tracked Postiz references listed below. The migration/removal issue must repeat the search after its changes and separately verify deployed environment configuration and live persisted data.

## Repository inventory

The following tracked references were found with the command git grep -n -i postiz HEAD on the audit baseline.

| File | Current use | Follow-up |
|---|---|---|
| `app/Providers/PostizPublishingProvider.php` | Production HTTP adapter; reads Postiz URL/key/timeout; sends publication request with idempotency header; normalizes success and failure | Replace only after destination-aware adapter contract is implemented; then remove |
| `app/Providers/FakePublishingProvider.php` | Test fake returns provider key `postiz` and a `fake.postiz.local` URL | Make fake provider-neutral or adapter-specific as decided by the shared contract |
| `app/Providers/AppServiceProvider.php` | Binds `PublishingProvider` to fake in tests and Postiz in non-test environments | Replace single-provider binding with destination-aware adapter resolution |
| `app/Services/IntegrationRegistry.php` | Registers `postiz` as the publishing provider for `publication.publish` | Remove/replace provider registration after consumers migrate |
| `app/Services/PublishingService.php` | Hard-codes `postiz` in success and failure `PublicationResult.provider` records | Record the resolved adapter/provider identity, preserving historical values |
| `config/services.php` | `services.postiz.url`, timeout and key; `POSTIZ_WEBHOOK_SECRET` in integration webhook secret map | Retire only after runtime/deployment inventory and migration are complete |
| `database/factories/PublicationResultFactory.php` | Default result provider is `postiz` | Update test fixture defaults with the new contract |
| `database/factories/SocialAccountFactory.php` | Default account provider is `postiz`; external IDs use a Postiz-prefixed test value | Use a neutral or network-appropriate test identity |
| `tests/Feature/IntegrationBoundaryTest.php` | Asserts Postiz registry membership and current provider binding | Replace with adapter registry/resolution contract tests |
| `tests/Feature/IntegrationResultReconciliationTest.php` | Selects `publication.publish` when provider is `postiz`, otherwise a Canva operation | Separate publishing lifecycle tests from generic integration-result tests |
| `tests/Feature/PlatformEventArchitectureTest.php` | Uses `postiz` as example provider for publication events | Use a provider-neutral or explicitly network-scoped fixture |
| `tests/Feature/PublishingTest.php` | Test name and expectations describe submission through Postiz | Preserve lifecycle, authorization, idempotency, timeout and result-history assertions while changing adapter assumptions |
| `README.md` (lines 446, 1066) | Lists Postiz in provider/architecture descriptions | Update after runtime migration state is verified |
| `docs/domain-model.md` (lines 172, 200) | Describes Postiz as the publishing execution boundary | Update the verified architecture description after migration |
| `docs/integrations.md` (line 35) | Lists Postiz as a current external provider | Update provider list and document network-adapter boundary |
| `docs/phase-13-audit.md` (lines 13, 65) | Lists Postiz as a current boundary and test concern | Reconcile the historical/current-state wording; do not erase valid historical context |

The tracked `.env.example`, Composer/package manifests and lockfiles, and the only tracked workflow `.github/workflows/tests.yml` contain no Postiz-specific entry. The tracked deployment/configuration inventory returned `.env.example` and the workflow file; no Docker, Compose, Railway, Render, Fly, or Procfile deployment definition was tracked in this checkout. This does **not** establish what environment variables are set in deployed environments.

## Current execution paths

### Account identity and tenancy

- `Channel` is enterprise-owned; its enterprise cannot be changed after creation. Channel type is a free-form string, not yet a canonical social-network enum.
- `SocialAccount` is enterprise-owned and belongs to a channel in the same enterprise. Provider and display name are normalized; external IDs are preserved exactly and padded IDs are rejected. Duplicate `provider + external_id` identities are rejected, with a database unique constraint.
- PR #515's identity normalization and ownership invariants are present in `SocialAccount` and its regression tests. Follow-up work must retain exact opaque external IDs, duplicate detection, and channel/enterprise ownership checks.
- `ConnectSocialAccountTool` is an explicit registration action, not a remote authorization flow. No inspected model relationship ties a `SocialAccount` to an `IntegrationConnection`.

### Publication lifecycle and authorization

- `PublishingService::schedule()` validates the content/account target and Agent publication authorization, then creates a publication and schedule in a database transaction.
- `PublishingService::submit()` reloads the content/account/channel, repeats target and authorization checks, short-circuits already submitted/succeeded publications, and creates/reuses a publishing job by idempotency key.
- Submission sends the external account ID, content, scheduled time, idempotency key and channel type to the one injected provider. Success updates external IDs/URL and lifecycle state and records a result. Provider exceptions are translated into normalized failure state and a historical failure result.
- Publication/account/channel models enforce lifecycle, immutability and enterprise/channel consistency. Publication results are organization/enterprise scoped and preserve historical provider metadata.
- Current idempotency is CR8OR-owned, but the present provider abstraction does not resolve an adapter per network/destination. A timeout after remote acceptance remains a critical ambiguous-outcome scenario for the new adapter contract; do not add blind retries.

### Integration connections, credentials and webhooks

- `IntegrationConnection` is scoped to an organization and optionally an enterprise. It has lifecycle states (active, disabled, degraded, revoked), an opaque credential reference, configuration and metadata. The model rejects credential-like keys in configuration/metadata and checks enterprise/organization consistency.
- `CredentialResolver` exposes `resolveAccessToken(IntegrationConnection)`, but the registered implementation is `CanvaCredentialResolver`, which explicitly rejects non-Canva connections and resolves only configured Canva credentials. It is not a social credential store.
- The generic `/integrations/webhooks/{provider}` endpoint verifies HMAC signatures using provider-specific or default secrets and passes a normalized envelope to `IntegrationResultService`. That service operates on generic `IntegrationJob` records. It is not currently wired to the separate `PublishingService::reconcile()` path for `Publication` records.
- The integration boundary's result deduplication and protection against late/out-of-order results are useful existing patterns, but they must not be assumed to cover publication results without explicit integration and tests.

## Data model and migration risks

No migration defines a Postiz-specific column or table. Provider identity is persisted in generic columns and may occur in:

- `social_accounts.provider` (unique with `external_id`);
- `publication_results.provider` (historical result identity);
- `integration_connections.provider` and its `external_account_id` / `credential_reference`;
- `integration_jobs.provider`, `external_job_id`, and operation/idempotency fields;
- `integration_results.provider` and external result/job identifiers;
- `external_resources.provider` and external resource identifiers;
- publication and publishing-job external IDs/URLs, which are generic and may point to Postiz-managed resources;
- configuration/metadata JSON fields, audit/event payloads, logs, or external deployment configuration.

The listed columns are generic and are not evidence that Postiz data exists in a production database. The current schema has no dedicated Postiz migration marker or safe data rewrite. Before deleting provider code or retiring credentials, operators must inspect representative deployed databases and environment configuration, quantify rows by provider, identify references to provider-managed external IDs/URLs, and decide how to preserve historical attribution. Historical publication results should not be rewritten merely to remove a provider name.

## Required migration boundary and dependency order

1. **#519 (this audit):** establish the repository inventory, execution paths, compatibility risks and baseline.
2. **#520:** define canonical network identity, destination, connection, ownership and uniqueness invariants. Reconcile `Channel`, `SocialAccount` and `IntegrationConnection`; do not duplicate the integration boundary.
3. **#521:** implement authorized OAuth state/callback, account discovery, credential references, refresh, revocation, disconnect and reauthorization. Keep token material out of ordinary records, logs, MCP responses and Agent context.
4. **#522:** replace the singleton provider path with destination-aware adapter resolution; define content validation, provider identity, result correlation, idempotency and ambiguous-outcome reconciliation.
5. **#523 (completed in source):** remove Postiz runtime/config/current docs/tests/deployment references after the destination-aware publishing path is in place. Operational verification of deployed secrets and historical provider data remains outstanding.
6. **#524:** establish the shared adapter contract and reusable conformance suite before network adapters proliferate.
7. **#525–#532:** implement LinkedIn, Facebook, Instagram, TikTok, Bluesky, YouTube, Threads and X adapters against the shared contract and current official API documentation.
8. **#533:** expose connection and publishing actions through existing authorized Capability → Operation entry points, MCP, Filament and persisted published WorkflowVersions.
9. **#534:** perform cross-network security, reliability, migration rehearsal and production-readiness verification.

This is the dependency order defined by parent issue #518. Network work must not bypass the shared identity, authorization, adapter or conformance contracts.

## Existing guarantees to preserve

- Publication-ready content is required before scheduling/submission.
- The current actor/Agent authorization and approval requirements remain authoritative.
- Channel/account/content/publication ownership must remain tenant-safe and validated at execution time.
- Provider identifiers and external IDs are opaque and must not be normalized in ways that change identity.
- Publication/job idempotency, normalized failures, correlation IDs, and historical result records must remain available.
- Provider webhooks/results must be authenticated, deduplicated, correlated unambiguously and prevented from overwriting terminal state with late/out-of-order results.
- Raw access tokens, refresh tokens, authorization codes and client secrets must never enter ordinary domain records, MCP/Agent outputs, or logs.
- Filament remains an administrative entry point; domain invariants and authorization belong in application/domain services and policies.

## Baseline validation

Focused baseline command:

```bash
php artisan test tests/Feature/PublishingTest.php tests/Feature/IntegrationBoundaryTest.php tests/Feature/IntegrationResultReconciliationTest.php tests/Feature/SocialAccountNormalizationTest.php
```

Result on the audit branch, before this document was added: **33 tests passed, 118 assertions**.

The persistent sandbox initially had no `vendor/`, `node_modules/`, `.env`, or SQLite database, so dependencies and a local test database were prepared from the committed lockfiles and repository setup. No tracked dependency files were changed. `npm ci` reported 3 audit findings (1 high, 2 critical); this audit did not run `npm audit fix` or alter dependency versions because that is outside issue scope.

## Unresolved operational checks

- Production/deployed environment variable and secret inventory, including whether Postiz credentials or webhook secrets are still configured.
- Read-only inventory of deployed provider-valued rows and external IDs/URLs before migration.
- Whether any deployed Postiz integration is still receiving requests or webhooks.
- Whether external Postiz records need an export/archive, link retention or explicit operational retirement plan.

These are intentionally unresolved rather than inferred from repository source.

### Complete local validation

After adding this audit document, the repository's configured validation completed successfully:

- `COMPOSER_ALLOW_SUPERUSER=1 composer ci:check` — passed; Pint checked 1,435 files, PHPStan analyzed 1,211 files with no errors, and the full PHP suite passed **977 tests / 12,398 assertions**.
- `npm run test:js` — passed, **10 tests**.
- `npm run build` — passed.
- `git diff --check` — passed.

`COMPOSER_ALLOW_SUPERUSER=1` is required in this root-owned sandbox to allow Composer scripts; the initial attempt without it aborted before running validation. No dependency manifests or lockfiles were changed.