# Template catalogue

CR8OR owns the persisted template catalogue and its lifecycle metadata. The cr8or-media repository remains the source of renderer definitions and rendering behavior. The catalogue stores an immutable source locator, SHA-256 digest, schema version, and versioned input/media/rendering contracts rather than a second editable copy of renderer JSON. The exact JSON bytes are also stored as an immutable, content-addressed artifact in CR8OR's canonical Cloudflare R2 disk; the database stores only its path and hash, not the full editable definition.

## Definition registration contract

The current cr8or-media runtime reads built-in definitions from the repository-root data/templates directory. Register that directory, not packages/cr8or-core/data/templates: the image and video services resolve their live catalogue from the root data/templates path.

The adapter maps the current renderer schema without changing the renderer JSON:

- id becomes the stable CR8OR template key.
- name and project become catalogue display metadata.
- format image maps to output_format image; format reel maps to output_format video.
- version becomes schema_version. Only schema version 2 is supported until a deliberate adapter/schema update is implemented.
- sampleData keys become optional input fields with inferred primitive types. The renderer supplies sample defaults, so these fields are not falsely marked required.
- canvas, element types, background type, and media element support are recorded as versioned rendering/media contracts.
- renderer targets are derived as cr8or-image or cr8or-video.

The command also supports an explicit catalogue metadata envelope under catalog or metadata when a definition requires a richer contract. The renderer JSON remains authoritative for composition and rendering. Unsupported or missing identity, format, or schema version fails registration; CR8OR must not infer that an arbitrary JSON file is a renderable template.

## Registering definitions

Run this from a checkout of deputy-proxy/cr8or-media, pointing to the agreed directory that contains only template definitions:

    php artisan templates:register /path/to/cr8or-media/data/templates

The command derives the commit SHA from Git. If the definition directory is not in a Git checkout, registration fails rather than recording a mutable reference. The command accepts only the canonical deputy-proxy/cr8or-media origin.

Registration is idempotent for the same template key, definition content hash, and schema version, even if the source commit changes without changing the definition. Changed content creates a new draft version. The command rejects dirty catalogue files and verifies the checkout origin and canonical path before recording the commit. It does not publish discovered versions automatically.

Publish a registered draft only after retrieving the exact definition at its pinned commit and validating its content hash and versioned contracts:

    php artisan templates:publish <template-version-id> /path/to/cr8or-media

This is a privileged maintenance command. CR8OR does not currently have a platform-administrator identity distinct from organization membership, so no global publish action is exposed in Filament. The command verifies the checkout origin, reads the definition by its stored commit and repository-relative path, and refuses to publish if the definition is unavailable or its hash/contracts no longer match.

## Publication and execution

Publish through the privileged templates:publish CLI command, which verifies the exact pinned source bytes and matching R2 artifact before invoking the lifecycle service. There is deliberately no generic Filament create/edit/publish action for built-in platform templates: CR8OR currently has no platform-administrator identity distinct from organization membership, and granting every organization owner permission to change a global template would cross tenancy boundaries.

Render requests may supply template_key and optionally template_version in their parameters. CR8OR resolves the currently published version, checks the output format, and persists template_version_id on the render request. The exact foreign key remains attached to the historical request when a later version supersedes it. A renderer must retrieve the pinned R2 artifact and verify the content against definition_sha256 before rendering. Published-template resolution checks artifact availability, checksum, and contract compatibility and fails closed if the artifact is missing or corrupt. TemplateCatalogService::getDefinitionContents() exposes the verified bytes to trusted execution code.

The source repository is private, so registration and publication require a deployment process with access to the pinned commit. Runtime renderers need access to the verified R2 artifact, not a shared source checkout. Do not assume that CR8OR and renderer services share a filesystem.

The repository currently has no MCP capability/operation wired to the generation/render request methods in MediaLifecycleService. This change integrates version selection at those existing domain-service entry points and provides published catalogue discovery without inventing a duplicate MCP tool. Any future media capability should reuse TemplateCatalogService and the pinned version relation.
