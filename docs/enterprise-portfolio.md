# Enterprise Portfolio Dashboard

The Enterprise Portfolio Dashboard is a live overview of authorized CR8OR Enterprises. Dashboard cards map to `Enterprise` records, not `Project` work-management records. It does not migrate the prototype's unrelated property-management demo or use demo arrays as production data.

## Portfolio taxonomy and connections

`EnterpriseGroup` and `EnterpriseCategory` are organization-owned taxonomy records. Each Enterprise may have one of each. Existing `EnterpriseContext` remains the source for business description, industry, business model, target market, and geography.

Enterprise connections are persisted in `enterprises.connections` as a list of entries:

- `target_enterprise_id`: another Enterprise in the same organization;
- `type`: one of `depends_on`, `supports`, `integrates_with`, `related_to`, `competes_with`, or `owns`;
- `direction`: `incoming`, `outgoing`, or `bidirectional`;
- `description`: optional text up to 500 characters.

An incoming connection renders an edge from its target toward the source Enterprise. Outgoing renders source to target. Bidirectional renders both. Connections cannot cross organization boundaries or point to the same Enterprise. Work-management `Dependency` records are not used for portfolio connections.

## GitHub issue synchronization

Configure an Enterprise with both `github_repository` (`owner/repo`) and `github_repository_url` (`https://github.com/owner/repo`). Configure an active `github` `IntegrationConnection` for that Enterprise or its organization. Its `credential_reference` points to managed credentials and must not contain a token.

The credential resolver reads `services.github.credentials.<reference>`. The default reference reads `GITHUB_ACCESS_TOKEN`; named references may be supplied in the `GITHUB_CREDENTIALS` JSON environment map. Example:

    GITHUB_ACCESS_TOKEN=ghp_...
    GITHUB_CREDENTIALS='{"engineering":"ghp_..."}'
    GITHUB_TIMEOUT=15

Do not put access tokens in Enterprise fields, integration configuration/metadata, issue snapshots, or browser code. The `enterprise-portfolio:sync-github-issues` command queues sync jobs daily at 02:15 and may also be run manually. Queue workers must be running for jobs to execute asynchronously.

The synchronizer uses the GitHub REST API, paginates up to 100 pages, excludes pull requests, upserts by Enterprise/repository/stable GitHub issue ID, and commits snapshots only after all pages have been fetched successfully. Transient failures preserve last-known-good snapshots and update the Enterprise's `github_issues_sync_status`, `github_issues_synced_at`, and `github_issues_sync_error` fields.

## Google Tag Manager website events

Configure `website_domain` as a lowercase hostname only, such as `example.com`. GTM sends a JSON POST to `/api/events/website` from the website with its browser `Origin` header. HTTPS origins on the configured hostname are accepted. The endpoint is rate-limited and limits request bodies to 8 KiB.

Example:

    {
      "event_id": "b30d6bb6-1c65-4b57-9e2a-86f5e9c9dd17",
      "event_type": "page_view",
      "occurred_at": "2026-10-10T12:00:00Z",
      "payload": {
        "page_path": "/pricing",
        "page_title": "Pricing",
        "campaign_source": "newsletter"
      }
    }

Supported event types: `page_view`, `content_view`, `form_submit`, `cta_click`, `sign_up`, and `conversion`. Supported payload keys: `page_path`, `page_title`, `element_id`, `form_id`, `campaign_source`, `campaign_medium`, and `campaign_name`. Event IDs must be UUIDs. Timestamps must be within the previous 30 days and not in the future. Repeated IDs for the same organization/source are idempotent.

The endpoint resolves the Enterprise using the request Origin hostname and the persisted `website_domain`; a browser-supplied Enterprise ID is not accepted. Successful ingestion returns 202, unconfigured domains return 404, invalid/missing origins return 400, and invalid event data/timestamps return 422. CORS headers are only emitted for the matching configured HTTPS origin.

Origin matching is a routing/allowlist mechanism, not strong authentication. Website events are untrusted analytics input and must never authorize or mutate CR8OR business state. Do not send credentials or sensitive personal data in event payloads.

## Activity feed and data integrity

The Event feed is an immutable projection of meaningful Enterprise-scoped CR8OR lifecycle events and allowlisted website events. It does not replace authoritative business records, approval history, integration results, publication history, or `AgentExecutionEventRecord` history. Duplicate deliveries are ignored by source identity.

Counts and timelines are derived from persisted source records. Social follower metrics and third-party fixtures are not shown unless backed by a verified integration or authoritative metric source.
