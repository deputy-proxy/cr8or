# Overview dashboard

The Filament **Overview** page is available at the existing `work-overview` route to preserve bookmarks. Its navigation label and page title are **Overview**.

## Scope and authorization

The page selects the first enterprise the authenticated user is authorized to access, ordered by enterprise name. The selector only accepts enterprises within the user's authorized organization scope. Every panel is filtered to that selected enterprise, and each resource-backed query reuses the corresponding Filament resource query scope. Summary counts are omitted when the current user cannot view the corresponding resource. Event activity is only queried when the Events resource is available to the user.

## Current panels

- Summary counts: Strategies, Goals, Objectives, Initiatives, and KPIs.
- Current work lists: active Projects, recently updated Initiatives, upcoming non-terminal Milestones, and actionable Tasks.
- Activity panels: recent persisted Enterprise Events, late Tasks, Tasks due this week, and Tasks due next week.
- Task due-date panels exclude `done`, `completed`, `cancelled`, and `canceled` statuses. Late means the due date falls before the current calendar date. Week boundaries are Monday 00:00 through the following Monday 00:00 in the application's configured timezone.

## Daily history chart

The dashboard now renders a separate daily lifecycle chart for Goals, Objectives, Tasks, Milestones, and GitHub Issues. Each chart plots open-at-start, open-at-end, opened, and closed counts over the most recent contiguous range of up to 14 complete days. The data service authorizes Enterprise access, scopes events and snapshots to the organization and enterprise, and verifies the daily reconciliation invariant before returning any points.

When no complete range exists, the relevant chart displays a collection status instead of fabricated values. Tasks and GitHub Issues use immutable lifecycle events when an item has been reopened. The `task.created` or first synchronized `issue.created` event establishes the initial state; subsequent `open` and `closed` events reconstruct repeated lifecycle intervals. Each observed reopen contributes another opening, and each subsequent close contributes another completion, while unique Task/Issue counts remain entity-based. The event-derived final state is checked against the current entity snapshot. Days before a reliable baseline, invalid transition chains, or unavailable event visibility remain explicitly incomplete. No historical transitions are inferred from the latest `closed_at` or `github_closed_at` value. Other internal entity charts continue to use their persisted creation and close timestamps. See [Lifecycle history and daily snapshots](lifecycle-history.md), [issue #624](https://github.com/deputy-proxy/cr8or/issues/624), [issue #665](https://github.com/deputy-proxy/cr8or/issues/665), and [issue #667](https://github.com/deputy-proxy/cr8or/issues/667) for the data contract and operational limitations.
