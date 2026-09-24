# CR8OR Governance

Governance defines who may act, what agents may do, when approval is required, and how important actions remain auditable.

## User Authority

Authenticated human users act within organization membership and assigned permissions. Human authority includes approving, rejecting, correcting and overriding actions where the applicable role permits it.

## Agent Authority

Agents operate only within explicit assignments, capabilities and permissions. Reasoning authority and execution authority are separate.

## Roles and Permissions

Membership is the authoritative user-to-organization authority record. Phase 1 defines the membership roles `owner`, `admin`, and `member`. Owners may manage memberships; admins may manage member-level memberships but cannot manage privileged memberships; members cannot manage memberships. Membership access is organization-scoped and enforced server-side by a native Laravel policy. The broader permission catalogue and role hierarchy remain deferred.

## Approval Requirements

Sensitive or high-impact operations may require explicit human approval. Examples include financial mutations, publication, external spending, destructive changes and credential use. Delegating an operation to another agent must not bypass approval.

For governed Agent-to-Agent delegation, an approval may be bound to the exact delegation before decision. Server-side authorization preserves organization, Enterprise, assignment, actor, capability and target-context matching. Once consumed, an approval is tied to the execution that used it and cannot authorize a different execution or delegation.

## Audit Requirements

Important operations should retain enough context to reconstruct the initiator, agent where applicable, capability, organization and authorization context, approval result, external execution and resulting state transition.

## Organization Isolation

Organizations are the primary business-data isolation boundary. Application services, policies, MCP and external integration workflows must preserve it.

## Historical Integrity

Important approvals, financial transactions, audit entries, publication results, decision records, agent decisions and external execution records must remain interpretable under the context applicable when created. Changing current permissions or instructions must not retroactively rewrite history.

Decision records are separate from AgentDecision and EnterpriseDecision and preserve actor identity, decision-time context and timestamp as historical facts.

Governance constrains domain operations. It does not become a parallel business process engine.