# Repository-specific development configuration

Last verified: 2026-11-19. Exact resolved versions are recorded in [the cross-repository version matrix](https://github.com/deputy-proxy/cr8or/blob/main/.chatgpt/repository-versions.md). Re-check manifests, lockfiles, and workflows before each implementation.

## Runtime and resolved framework versions
- PHP constraint: `^8.4`; CI uses PHP 8.4.
- Laravel 13.32.0; Filament 5.8.4; Livewire 4.4.5.
- PHPUnit 13.3.4; Pest 5.2.1; Larastan 3.12.2; Pint 1.27.1.
- Node.js 22; npm lockfile resolves Vite 8.3.0, Tailwind CSS 4.3.3, Laravel Vite plugin 3.2.0.
- Database CI: MySQL 8.4.

## Current CI validation
Source: `.github/workflows/tests.yml`.
- Frontend build: Composer install without scripts, `npm ci`, `npm run build`.
- PHP job: Composer install, Laravel/Passport setup, MySQL planning migration test, descriptor seeders, migration rollback/re-application, then `composer ci:check`.
- `composer ci:check` delegates to Pint formatting validation, PHPStan, and the Laravel test suite.
- Frontend test job runs `npm run test:js`.
- CI caches Composer/npm dependencies and Vite build artifacts.

## Local validation
- PHP: `composer ci:check`.
- Frontend: `npm ci`, `npm run test:js`, `npm run build`.
- Reproduce MySQL planning migration/rollback checks when changing planning migrations or related database behavior.
- Use focused tests while iterating, then complete workflow-equivalent checks before PR.

## Known gaps and notes
- Root Composer and npm security audits are not yet CI gates; issue #671 tracks adding them.
- Follow `.github/AI_DEVELOPMENT_RULES.md` when present.
- Frontend build is required for Blade/Filament views using Vite assets.
