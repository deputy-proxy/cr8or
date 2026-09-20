# CR8OR Governance

Governance defines who may act, what agents may do, when approval is required, and how important actions remain auditable.

## User Authority

Authenticated human users act within organization membership and assigned permissions. Human authority includes approving, rejecting, correcting and overriding actions where the applicable role permits it.

## Agent Authority

Agents operate only within explicit assignments, capabilities and permissions. Reasoning authority and execution authority are separate.

## Roles and Permissions

Roles group permissions for an organization. Permissions define capabilities an actor may perform within an applicable scope. The final role hierarchy and permission catalogue are deferred until Identity & Access is implemented.

## Approval Requirements

Sensitive or high-impact operations may require explicit human approval. Examples include financial mutations, publication, external spending, destructive changes and credential use. Delegating an operation to another agent must not bypass approval.

## Audit Requirements

Important operations should retain enough context to reconstruct the initiator, agent where applicable, capability, organization and authorization context, approval result, external execution and resulting state transition.

## Organization Isolation

Organizations are the primary business-data isolation boundary. Application services, policies, MCP and external integration workflows must preserve it.

## Historical Integrity

Important approvals, financial transactions, audit entries, publication results, agent decisions and external execution records must remain interpretable under the context applicable when created. Changing current permissions or instructions must not retroactively rewrite history.

Governance constrains domain operations. It does not become a parallel business process engine.
