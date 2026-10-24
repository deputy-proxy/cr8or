# Reliable lifecycle history

**Status:** Planned in [issue #624](https://github.com/deputy-proxy/cr8or/issues/624). The Overview dashboard must keep historical charts unavailable until the requested range has complete, reconciled history.

## Purpose

Provide auditable daily counts for Goals, Objectives, Tasks, Milestones, and GitHub Issues without inventing historical facts from current state. Reuse the existing immutable Enterprise `Event` model and `EnterpriseActivityObserver`. The only new snapshot logic is a start-of-day boundary and an end-of-day boundary.

## Data model and responsibilities

### Lifecycle events

Use the existing `events` table/model. Do not introduce a parallel event store.

Record normalized `open` and `closed` lifecycle transitions, with the tracked entity identified using the existing subject fields. Retain organization/enterprise scope, occurrence time, source, actor where known, and a minimal sanitized payload. Keep the Event model's immutability and payload sanitizer/size limit.

An event represents an actual semantic transition, not a model save. Reopening is a new `open` transition after a previous `closed` transition. Ordinary edits must not emit lifecycle transitions. Event writes must be transactionally consistent and idempotent under retries and synchronization replays.

### Daily boundary snapshots

Add one durable snapshot mechanism with exactly two boundary values:

- `start`: open-entity count at the beginning of a configured local calendar day.
- `end`: open-entity count at the end of that day.

Do not add hourly or arbitrary-interval snapshots, and do not snapshot each transition. Lifecycle events remain the transition history; snapshots anchor and reconcile daily counts.

Each snapshot must identify the organization/enterprise scope, entity type, local date, boundary, count, completeness/accuracy metadata, and audit timestamps. Enforce uniqueness over scope + entity type + date + boundary. Scheduled capture must be retry-safe and concurrency-safe. A delayed capture must not be presented as an exact boundary count unless that accuracy can be established.

## Counting semantics

For each entity type and local day, expose:

- **Open at start:** the start boundary snapshot.
- **Open at end:** the end boundary snapshot.
- **Opened during day:** number of observed `open` transition occurrences in the half-open interval `[start_of_day, next_start_of_day)`.
- **Closed during day:** number of observed `closed` transition occurrences in that same interval.

Transition counts are occurrences, not unique entity counts. This supports close-and-reopen sequences within a day. Boundary counts are distinct entities open at the boundary. Validate the reconciliation invariant:

`open_at_end = open_at_start + opened_during_day - closed_during_day`

If entity semantics (deletion, correction, merge, terminal states) make the invariant not applicable, document the explicit adjustment/exception rather than silently altering values. Do not count an unknown or unobserved transition.

Use one configured application timezone and consistent half-open day intervals. Keep event occurrence time separate from event receipt/processing time, especially for delayed GitHub synchronization.

## Entity-specific rules

Before implementation, inspect actual status fields, relationships, deletion semantics, and sync paths. Define a documented mapping from each entity's persisted states to open/closed. Do not assume that every model uses the same status vocabulary.

- **Goals and Objectives:** map the real domain lifecycle states after inspecting their models and update paths.
- **Tasks and Milestones:** map their actual status enums/values; distinguish completion/cancellation from ordinary updates.
- **GitHub Issues:** record transitions only when the synchronization process observes a genuine state change. Replayed payloads must be idempotent. Record synchronization gaps and establish the earliest reliable history date. Current GitHub state is not evidence of prior transitions.

The observer must validate ownership and use the existing Event invariants. Do not put raw GitHub payloads, secrets, or unrelated model attributes in event payloads.

## Implementation plan

1. Audit tracked models, status fields, tenancy relationships, deletion/reopen semantics, observer registration, and GitHub synchronization behavior.
2. Define stable lifecycle event types, subject identity, source, occurrence time, minimal payload, and idempotency strategy using the existing Event model.
3. Extend `EnterpriseActivityObserver`, model registration, and the GitHub synchronization path to emit only real open/closed transitions. Ensure rollback safety and deduplication.
4. Add the snapshot table/model and a scheduled operation that captures only start/end boundaries, with tenant isolation, uniqueness, concurrency protection, and explicit incomplete/late status.
5. Implement a testable read service that derives the four series, applies timezone boundaries, reconciles counts, and returns unavailable/incomplete states for gaps.
6. Add authorization-aware dashboard integration. Do not enable a chart for ranges that lack complete boundary snapshots or trustworthy event history.
7. Define `history_available_from` per entity/source. Do not fabricate a backfill from `created_at`, current state, or the latest GitHub snapshot.
8. Observe captured boundaries and event completeness for a defined validation period; enable charting only after reconciliation passes.
9. Document recovery procedures and surface late/missing snapshots, source gaps, duplicate attempts, and reconciliation failures without exposing sensitive payloads.

## Reliability and security requirements

- Tenant and enterprise scope must be validated on write and read.
- Event creation must follow transaction boundaries; rolled-back changes must not leave committed lifecycle events.
- Retries and duplicate sync payloads must not create duplicate transition events or snapshots.
- Snapshot uniqueness and locking must prevent concurrent duplicate captures.
- Missing history is an explicit data-quality state, never a zero count.
- No chart values should be shown for an incomplete range.
- Keep the current dashboard history-unavailable state until the new source proves completeness.

## Required tests

- State mapping for each entity, including reopen, terminal-state changes, deletion, and multiple transitions in one day.
- No lifecycle event on unrelated edits; no duplicates on repeated saves or sync retries.
- Transaction rollback, tenant isolation, authorization, and Event sanitization/immutability.
- Start/end boundary capture, uniqueness, concurrent execution, retries, delayed capture, timezone and daylight-saving boundaries.
- Reconciliation with close/reopen sequences and multiple transitions.
- GitHub replay, delayed observations, missing issues, and history gaps.
- Dashboard behavior for complete versus incomplete ranges.
- Full repository CI, PHPStan, frontend build, and JavaScript tests.

## Non-goals

- No new event store.
- No hourly or arbitrary interval snapshots.
- No per-transition snapshots.
- No fabricated historical backfill.
- No chart activation before completeness and reconciliation checks pass.
- No unrelated dashboard redesign.
