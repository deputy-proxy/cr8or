# CR8OR Implementation Decisions

This document records architectural decisions that constrain implementation without resolving details intentionally left open.

## ADR-001: Laravel Owns the Source of Truth
**Status:** Accepted

**Decision:** Laravel/CR8OR owns authoritative business state, domain rules, authorization and historical records.

**Rationale:** A persistent business operating system needs one authoritative state boundary.

**Consequence:** Other layers invoke controlled CR8OR capabilities rather than independently defining authoritative business state.

## ADR-002: MCP Is a Capability Interface
**Status:** Accepted

**Decision:** MCP exposes authorized resources and capabilities but does not contain a second implementation of business logic.

**Rationale:** AI-facing transport should not become a competing domain layer.

**Consequence:** MCP invokes application/domain services and preserves server-side authorization.

## ADR-003: n8n Is an Orchestration Boundary
**Status:** Accepted

**Decision:** n8n coordinates workflows crossing service boundaries and does not become the authoritative CR8OR state store.

## ADR-004: External Workers Are Execution Boundaries
**Status:** Accepted

**Decision:** Specialized services perform bounded execution such as media rendering, publishing or model generation.

## ADR-005: CI Is Authoritative Validation
**Status:** Accepted

**Decision:** GitHub Actions is the authoritative automated validation environment.

## ADR-006: Domain-Oriented Architecture
**Status:** Accepted

**Decision:** CR8OR is organized around explicit business domains and boundaries rather than generic CRUD structures.

## ADR-007: Explicit Authorization and Auditability
**Status:** Accepted

**Decision:** Sensitive operations require explicit server-side authorization, approval support where applicable and sufficient audit information to reconstruct important actions.

## ADR-008: Historical Truth Is Preserved
**Status:** Accepted

**Decision:** Important historical records are preserved rather than silently rewritten when current rules or configuration change.

## Deferred Decisions

The final Laravel domain directory structure, database schemas, role catalogue, MCP registry, provider-specific contracts, model configuration, delegation protocol, queue topology and detailed approval matrix remain unresolved until later implementation work.
