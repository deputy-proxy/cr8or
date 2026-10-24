# Lifecycle history and daily snapshots

Issue: [#624](https://github.com/deputy-proxy/cr8or/issues/624)

**Implementation status:** lifecycle events, boundary snapshots, and the read service are implemented. The Overview dashboard chart remains unavailable until a requested range has complete, reconciled history.

## Data contract

Lifecycle transitions reuse the immutable `events` table and `EnterpriseEventRecorder`; there is no parallel event store. `event_type` is `open` or `closed`; `subject_type` and `subject_id` identify the entity; `organization_id` and `enterprise_id` are derived from its persisted Enterprise relationship. The bounded, sanitized payload contains entity type/id, prior and resulting state, and a payload source field (`cr8or` or `github_sync`). `occurred_at` is the source transition time when reliable; `received_at` remains ingestion time.

The existing Event model only permits `cr8or` and `gtm` in its source column, so GitHub-originated lifecycle transitions retain the supported `cr8or` source and identify `github_sync` in the sanitized payload. Events are written on the same connection as the model mutation, so a surrounding transaction rollback also rolls back its event.

A lifecycle event is emitted only when an entity crosses the open/closed boundary. New internal Goals, Objectives, Tasks, and Milestones in an open state emit an initial `open` event. A new synchronized GitHub Issue does **not** emit a transition because its previous state is unknown. Subsequent observed `open`/`closed` changes are recorded; repeated syncs and ordinary edits do not produce another lifecycle transition. Reopening emits `open`.

The closed state mapping is case-insensitive: `closed`, `complete`, `completed`, `done`, `cancelled`, `canceled`, `archived`, `deleted`, and `rejected`. Other non-empty states are open. Objectives now have an explicit `status` field, defaulting to `active`, because the previous schema had no objective lifecycle state to observe. The Filament resource exposes Active, Completed, Cancelled, and Archived.

## Snapshot contract

`lifecycle_snapshots` is the only snapshot store. The only allowed boundaries are `start` and `end`. The daily command runs at local midnight and captures both the start of the new day and the end of the previous day. The boundary timestamp is midnight; `captured_at` records when the job actually observed the data. A capture more than 120 seconds after the boundary is incomplete. The unique key `(enterprise_id, snapshot_date, boundary, entity_type)` prevents duplicate snapshots. Organization ID is stored and checked alongside enterprise scope.

For the midnight boundary, a database transaction reads the observed open count and reverses recorded lifecycle events whose occurrence time is between the boundary and capture time. This reconstructs the boundary count rather than treating the later observation time as the boundary. Negative results are marked incomplete. Complete snapshots are not downgraded by later retries. Duplicate concurrent inserts resolve to the existing unique snapshot. There are no hourly, arbitrary-interval, or per-transition snapshots.

GitHub Issue snapshots are deliberately always marked incomplete in this implementation. The current repository sync is scheduled daily at 02:15, and a current-state sync cannot prove that no close/reopen transitions were missed between observations even if a boundary sync is fresh. Metadata records `sync_fresh_at_boundary`, `last_sync_observed_at`, `transition_coverage_verified: false`, and `completeness_reason: github_transition_coverage_unverified`. Do not enable issue history until a retained source (for example, verified webhook/event coverage) can prove transition completeness; changing the sync cadence alone is insufficient.

## Daily series and availability

`LifecycleHistoryService::dailySeries()` requires an authenticated User argument and authorizes `view` on the Enterprise before reading data. It returns opening count, closing count, `open` transition occurrences, and `closed` transition occurrences for one entity type and inclusive date range. Day windows use the configured application timezone and half-open intervals `[start_of_day, next_start_of_day)`.

Transition counts are occurrences, not unique entity counts, so close-and-reopen sequences can contribute multiple events during a day. Boundary counts represent entities open at that boundary. The service returns `history_available_from`, derived from the earliest complete start snapshot for that Enterprise/entity type, rather than using model `created_at`.

A day is available only when both snapshots exist and are complete and the reconciliation invariant holds:

`open_at_end = open_at_start + opened - closed`

If snapshots are missing/incomplete, or reconciliation fails, the service returns an unavailable reason with no points. It never derives history from current state or `created_at`. The Overview dashboard renders a separate chart per entity type. Each chart displays points only when the requested contiguous range is complete and reconciled; otherwise it displays a collection status.

## Entity and synchronization semantics

- **Goals:** `active` is open; `archived` and other listed terminal states are closed.
- **Objectives:** the new explicit `status` field is observed; the default is `active`.
- **Tasks and Milestones:** their persisted `status` fields are observed using the shared terminal-state mapping.
- **GitHub Issues:** transitions are recorded only when synchronization observes a genuine state change. A new issue's first observed state is a baseline observation, not a known transition. Delayed syncs use source timestamps when available. Gaps remain explicit; current state is not evidence of prior transitions.

## Implementation plan and validation

1. Audit model status fields, tenancy relationships, observer registration, and GitHub synchronization behavior.
2. Extend `EnterpriseActivityObserver` and its model registrations to emit semantic lifecycle transitions through `EnterpriseEventRecorder`.
3. Add Objective status because the previous schema could not represent Objective lifecycle transitions.
4. Add the unique, tenant-scoped snapshot table/model and the midnight capture command. Schedule it at `00:00` in the configured application timezone with overlap prevention and single-server locking.
5. Add the authorized read service, boundary reconciliation, `history_available_from`, and explicit unavailable reasons.
6. Keep the dashboard chart disabled until source coverage and reconciled history prove the range complete.
7. Do not fabricate historical backfill from current state or `created_at`. The first complete start snapshot is the earliest boundary anchor; for GitHub Issues, current sync cadence prevents reliable midnight snapshots.

## Reliability and security

- Snapshot and event reads filter by organization and Enterprise; entity counts are constrained to the selected Enterprise.
- Event payloads contain bounded identifiers/state/source fields and pass through `PlatformEventSanitizer`; raw GitHub payloads and secrets are never stored.
- Model changes made inside a database transaction roll back their lifecycle events with that transaction.
- Unique snapshot keys, complete-snapshot preservation, duplicate-insert recovery, `withoutOverlapping`, and `onOneServer` make scheduled retries and concurrent scheduled invocations safe.
- Missing history is an explicit data-quality state, never a zero count. Late captures are incomplete, and reconciliation failures prevent points from being returned.
- Recovery must inspect snapshot metadata and sync freshness. Do not manually mark gaps complete without reliable evidence.

## Tests

`tests/Feature/LifecycleHistoryTest.php` covers semantic open/closed transitions, Objective status, GitHub Issue sync behavior, start/end boundaries, late captures, idempotent retries, reconciliation, tenant isolation, transaction rollback, and Enterprise view authorization. The migration's schema change is covered by the strategy schema test; model policy and Filament-resource coverage contracts remain enforced.

The full repository validation required for this change is Pint, PHPStan, the complete PHP test suite, Vite production build, and JavaScript tests. The chart UI is enabled, but the data service continues to withhold incomplete or unreconciled points.

## Non-goals

No new event store, hourly/arbitrary interval snapshots, per-transition snapshots, fabricated historical backfill, automatic chart enablement, or unrelated dashboard redesign.
