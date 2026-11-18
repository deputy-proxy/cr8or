# Repository-specific development configuration

Last verified: 2026-11-18. Re-check the live workflows and manifests before every implementation; this file is a snapshot, not a substitute for inspection.

## Runtime and framework
- PHP requirement: `^8.4`; CI uses PHP 8.4.
- Laravel: 13.x.
- Filament: 5.x.
- Livewire: 4.x.
- Node.js: 22.
- Frontend package manager: npm, using committed `package-lock.json`.
- Database CI: MySQL 8.4.

## Current CI validation
Source: `.github/workflows/tests.yml`.

- Frontend job: `composer install --no-interaction --prefer-dist --no-scripts`, `npm ci`, `npm run build`.
- PHP job: Composer install, Laravel environment setup, Passport key preparation, MySQL planning migration test, seed descriptor data, rollback/re-apply migration checks, then `composer ci:check`.
- `composer ci:check` delegates to the Composer test script, which runs Pint formatting validation, PHPStan, and the Laravel test suite.
- Frontend test job: `npm ci` and `npm run test:js`.
- MySQL migration checks use MySQL 8.4.
- CI caches Composer/npm dependencies and the Vite build; obsolete PR runs are cancelable.

## Local validation
- Inspect `composer.json` before using Composer aliases. Current primary quality gate: `composer ci:check`.
- Frontend: `npm ci`, `npm run test:js`, `npm run build`.
- Reproduce the workflow's MySQL planning migration/rollback checks when changing planning migrations or related database behavior.
- Use focused Pest/PHPUnit tests during implementation, then the complete workflow-equivalent checks before PR.

## Known CI coverage gaps
- The current workflow does not run a root Composer security audit or root `npm audit). An open issue tracks adding both.
- Do not claim these audits run until the workflow has been updated and verified.
- Do not assume a separate OpenWA PoC job exists in this repository; validate only what the current workflow actually defines.

## Repository-specific notes
- Follow `.github/AI_DEVELOPMENT_RULES.md` when present.
- The frontend build is required for Blade/Filament views that use Vite assets.
