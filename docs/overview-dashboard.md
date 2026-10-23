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

The dashboard currently does **not** render historical daily counts. CR8OR does not yet have a complete, auditable lifecycle history for Goals, Objectives, Tasks, Milestones, and synchronized GitHub Issues that can reconstruct opening counts and opened/closed movements for arbitrary past days. Current model state, `created_at`, and a latest GitHub issue snapshot are not sufficient to reconstruct that history reliably.

The dashboard explicitly reports the history as unavailable rather than drawing misleading values. A future implementation must add durable, idempotent daily snapshots or complete lifecycle event capture before showing series for opening count, closing count, opened during day, and closed during day. GitHub issue history must be derived from retained synchronization snapshots/events, and its historical accuracy limitations must be exposed. Daily values must use consistent application-timezone boundaries and validate `open_at_start + opened_during_day - closed_during_day = open_at_end` where the lifecycle model makes that invariant applicable, with documented handling for reopenings, deletions, and corrections.