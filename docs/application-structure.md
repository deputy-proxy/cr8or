# CR8OR Application Structure

## Purpose

This document establishes the minimum Laravel application-layer conventions required by CR8OR's architecture.

The structure is intentionally small. Directories and classes are introduced when a concrete responsibility exists, not as empty placeholders for future domains.

## Application Boundaries

| Location | Responsibility | Must not become |
|---|---|---|
| `app/Actions/` | Explicit application operations that coordinate a business use case | Generic helpers or domain models |
| `app/Domain/` | Domain-specific business concepts and invariants when they require dedicated code | A dumping ground for every application class |
| `app/Filament/` | Filament resources, pages and UI-specific application concerns | The authoritative location for business rules |
| `app/Jobs/` | Asynchronous CR8OR-owned work | A second business-state store |
| `app/Models/` | Eloquent persistence models and relationships | The sole location for complex business workflows |
| `app/Policies/` | Server-side authorization decisions | Business execution |
| `app/Services/` | Reusable application/domain services with a concrete responsibility | Generic "manager" or utility classes |

Laravel's existing framework conventions remain authoritative for providers, concerns, console commands, notifications and other framework-owned components.

## Rules

1. Do not create an empty directory merely because the architecture diagram contains that boundary.
2. Do not create base classes, interfaces or repositories without a concrete consumer and documented responsibility.
3. Important business operations should be represented by explicit application/domain code rather than hidden inside controllers, Filament components, MCP handlers or workflow definitions.
4. Authorization remains server-side and is enforced through policies and other appropriate application boundaries.
5. Eloquent models may persist and expose domain state, but they must not become a substitute for explicit application workflows when an operation has meaningful invariants or side effects.
6. Jobs perform asynchronous work owned by CR8OR. Cross-service orchestration remains outside the application domain and is coordinated by the established orchestration boundary.
7. MCP and external integrations invoke application capabilities. They do not create duplicate implementations of CR8OR business rules.
8. New boundaries must be justified by the applicable specification and independently testable when executable behavior is introduced.

## Domain Placement

Domain code should be introduced under a bounded domain only when the corresponding product phase requires executable behavior.

The expected direction is:

`app/Domain/{Domain}/...`

The exact internal class hierarchy remains intentionally deferred until the first concrete domain is implemented. This prevents the foundation phase from inventing abstractions that later domains may not need.

## Current Foundation

The current starter application does not require new executable application-layer abstractions for this foundation issue.

Existing framework code remains in its current locations, including:

- `app/Models/User.php`;
- `app/Providers/`;
- `app/Concerns/`;
- existing routes and framework configuration;
- existing Pest feature and unit test structure.

This issue therefore establishes conventions through documentation rather than manufacturing placeholder classes.

## Testing

Executable application code must have focused automated coverage appropriate to its boundary.

The existing test conventions remain:

- feature workflows under `tests/Feature/`;
- isolated behavior under `tests/Unit/`;
- shared Pest configuration in `tests/Pest.php`.

Foundation documentation does not require artificial tests.

## Compatibility

The structure must remain compatible with the repository's current stack:

- Laravel 13.17+;
- Filament 5;
- PHP 8.4+;
- Livewire 4;
- Pest 5;
- Larastan/PHPStan level 7;
- Laravel Pint.