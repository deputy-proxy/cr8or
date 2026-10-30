# Strategy & Execution Overview activity

Issue: [#632](https://github.com/deputy-proxy/cr8or/issues/632)

The Strategy & Execution Overview is a live read model. It does not read lifecycle snapshots and does not depend on a scheduled capture command.

## Current state and historical activity

- Summary cards and work lists are queried from their current domain records on every render.
- The activity timeline reads immutable Event records through EventResource::getEloquentQuery(), then narrows the query to the selected Enterprise.
- The timeline supports **Today** and **Last 14 days**, groups events by date in the configured application timezone, and sorts newest first.
- The query is bounded to the 100 most recent events in the selected range. It does not load an unbounded history.
- Event descriptions, event types, actor names (or **System**), and local timestamps are displayed. The timeline does not build entity links from event payloads.
- Livewire polls every 30 seconds while the page is visible. A poll re-resolves the selected Enterprise through the user's authorized Enterprise query and re-renders current metrics and activity.
- A real empty state is shown when no events exist in the selected range. An authorization-unavailable state is shown separately.

## Event recording coverage

EnterpriseActivityObserver is the existing canonical model lifecycle recording path for these supported Strategy & Execution Planning records. It delegates persistence to EnterpriseEventRecorder; no parallel activity store or recorder is used.

| Entity | Ownership | Recorded activity |
| --- | --- | --- |
| Strategy | Objective → Enterprise | Create, name/description updates, delete |
| Plan | Strategy → Objective → Enterprise | Create, name/description updates, delete |
| Initiative | Plan → Strategy → Objective → Enterprise | Create, name/description updates, delete |
| Goal / Objective | Direct Enterprise | Create, tracked updates, observed open/closed transitions, delete |
| KPI | Direct Enterprise | Create, definition/target/current measurement updates, delete |
| Project | Direct Enterprise | Create, name/status updates, delete |
| Milestone | Direct Enterprise | Create, name/status/due-date updates, open/closed transitions, delete |
| Task | Direct Enterprise | Create, name/status/priority/due-date updates, open/closed transitions, delete |
| Decision | Direct Enterprise | Create, title/summary/rationale updates, and delete; historical decision fields remain immutable |
| EnterpriseDecision | Direct Enterprise | Create, meaningful content/actor/date updates, delete |
| Dependency | Direct Enterprise | Create, endpoint/type updates, delete |
| Assignment | Direct Enterprise | Create, assignee/target updates, delete |
| GitHub Issue | Direct Enterprise | Title updates and observed state changes only |

The observer emits only tracked-field updates, so no-op saves and irrelevant technical-field changes do not create activity. Creation is recorded as creation rather than a fabricated initial open transition; later persisted status changes use the existing open/closed event semantics. A newly synchronized GitHub Issue is not treated as a transition because its previous state is unknown. Synchronization records only transitions observed from persisted state; it does not invent missed intermediate changes.

The recorder enforces uniqueness for source event identities, preserves immutable Event records, and applies payload sanitization and size limits. Domain events and synchronized GitHub lifecycle transitions retain stable source identities; Eloquent model-mutation callbacks receive unique event identities so multiple legitimate updates in the same database timestamp interval are not collapsed. Model events are written on the same database connection and transaction as the underlying mutation, so a rolled-back transaction also rolls back its activity.

## Lifecycle timestamps for daily reporting

Goals, Objectives, Tasks, and Milestones persist a nullable `closed_at` timestamp maintained by `LifecycleTimestampObserver`, independently of activity-feed recording. A transition from an open status to a terminal status sets the timestamp; ordinary saves while closed preserve it; reopening clears it; a later close records the next close time. Creating a record directly in a terminal status records its creation-time close timestamp.

GitHub Issues use the provider-owned `github_created_at` and `github_closed_at` values populated by `GitHubIssueSyncService`. These are not replaced with local synchronization timestamps.

The migration does not infer closure times for records that were already closed before `closed_at` existed. Their `closed_at` remains null because `updated_at` cannot prove when the status changed. Historical chart queries must treat those records as lacking a reliable close date rather than fabricate one. The agreed single-timestamp model also cannot reconstruct prior close intervals after an item is reopened and its `closed_at` is cleared.

## Snapshot history retirement

A repository-wide consumer audit found that LifecycleHistoryService, LifecycleSnapshot, and the midnight capture command were only used by the former Overview charts and their dedicated tests/documentation. The Overview no longer has any runtime dependency on those components, and the scheduler registration and chart code have been removed.

The combined historical migration remains because it also introduces Objective status. Its lifecycle_snapshots table-creation block has been retired, so fresh installations no longer create that table. Existing installations that already applied the earlier migration retain their historical table and rows; the migration rollback also deliberately preserves them. No destructive data migration is introduced. The Overview never queries the legacy table and works when it is absent.

## Authorization and tenancy

The event query starts from the Event Filament resource's authorized base query, which scopes events to the user's authorized Organizations and verifies Enterprise/Organization consistency. It then filters by the currently selected Enterprise. The selected Enterprise is re-resolved from the authorized Enterprise query on each render and polling action. The feed does not expose subject links or untrusted entity identifiers.

## Validation

The Overview feature tests cover Enterprise selection and isolation, truthful empty state, persisted activity grouping and actor attribution, and polling refresh of both current metrics and activity. Activity observer tests cover entity ownership paths, supported action families, no-op behavior, GitHub synchronization semantics, and transaction rollback.
