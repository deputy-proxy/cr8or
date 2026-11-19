# Agent Instructions

Before making changes, read `.chatgpt/osingle.md`, `.chatgpt/repository.md`, and `.github/AI_DEVELOPMENT_RULES.md`. Inspect the relevant code, tests, Composer scripts, frontend scripts, and GitHub Actions workflows. Read applicable skills under `.agents/skills/`.

Follow repository-specific architecture and conventions. Keep changes scoped, add regression tests for behavior changes, run configured validation, and inspect GitHub Actions before declaring completion. Never commit directly to `main`, bypass failing checks, or commit secrets.