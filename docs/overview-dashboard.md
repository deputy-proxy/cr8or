# Overview dashboard

The Filament **Overview** page is available at the existing `work-overview` route to preserve bookmarks. Its navigation label and page title are **Overview**.

## Scope and authorization

The page selects the first enterprise the authenticated user is authorized to access, ordered by enterprise name. The selector only accepts enterprises within the user's authorized organization scope. Every panel is filtered to that selected enterprise, and each resource-backed query reuses the corresponding Filament resource query scope. Summary counts are omitted when the current user cannot view the corresponding resource. Event activity is only queried when the Events resource is available to the user.

## Current panels

- Summary counts: Strategies, Goals, Objectives, Initiatives, and KPIs.
- Current work lists: active Projects, recently updated Initiatives, upcoming non-terminal Milestones, and actionable Tasks.
- Activity panels: recent persisted Enterprise Events, late Tasks, Tasks due this week, and Tasks due next week.
- Task due-date panels exclude `done`, `completed`, `cancelled`, and `canceled` statuses. Late means the due date falls before the current calendar date. Week boundaries are Monday 00:00 through the following Monday 00:00 in the application's configured timezone.

## Daily history limitation

The dashboard does **not** render historical daily counts yet. CR8OR does not have a complete, auditable lifecycle history for Goals, Objectives, Tasks, Milestones, and synchronized GitHub Issues that can reconstruct opening counts and opened/closed movements for arbitrary past days. Current model state, `created_at`, and a latest GitHub issue snapshot are not sufficient to reconstruct that history reliably.

The dashboard continues to report history as unavailable rather than drawing misleading values. Lifecycle events and the start/end-of-day snapshot service have been implemented, but charting remains disabled until the requested range has complete boundary snapshots and passes reconciliation. The snapshot logic must remain limited to start-of-day and end-of-day boundaries, with no hourly, arbitrary-interval, or per-transition snapshots. See [Lifecycle history and daily snapshots](lifecycle-history.md) and [issue #624](https://github.com/deputy-proxy/cr8or/issues/624) for the data contract and operational limitations.
