# Single-issue implementation workflow

This is the shared engineering workflow for repositories that use the OpenAI/Railway development process.

- Canonical source: `deputy-proxy/cr8or/.chatgpt/osingle.md`.
- Copies in participating repositories must remain byte-identical to this file.
- Repository-specific facts and commands belong in `.chatgpt/repository.md`, never in this shared workflow.
- Before implementation, read both this file and `.chatgpt/repository.md`. If the repository file is missing, inspect the repository and create/update it before proceeding.
- The repository's current source code, dependency manifests, lockfiles, and CI workflows are authoritative over stale documentation.

## 1. Select and understand the issue

1. Get the oldest open issue in the current repository.
2. If there are no open issues, do nothing.
3. Read the complete issue body, implementation plan, comments, linked requirements, and relevant project documentation.
4. Treat the issue's approved implementation plan as the primary specification.
5. Resolve conflicts using the latest explicitly approved decisions in the issue discussion and repository documentation. If a material conflict remains unresolved, stop and report it rather than guessing.
6. Keep the work scoped to that issue. Do not silently absorb unrelated cleanup or other issues.

Do not move to another issue until the current issue has been completed according to section 10.

## 2. Inspect the repository before coding

Before writing code, inspect the current repository state, including at least:

- `.chatgpt/repository.md`;
- repository-specific development rules, including `.github/AI_DEVELOPMENT_RULES.md` when present;
- the applicable GitHub Actions workflows;
- `composer.json`, `composer.lock`, `package.json`, and the committed frontend lockfile;
- relevant PHP, frontend, test, database, and tool configuration;
- the existing implementation and tests around the affected functionality;
- the current Git branch, working tree, and any existing changes.

Treat the checked-out repository and its actual CI workflows as authoritative. Do not assume a command exists because it is conventional or because it appears in an old instruction file.

Do not overwrite, discard, or include pre-existing user changes. If the worktree contains unexplained changes, investigate and preserve them before proceeding.

## 3. Prepare and manage the persistent development Sandbox

Use the Railway Sandbox MCP `repository_prepare` flow for the current repository and the issue's dedicated development branch.

Always request:

- one persistent Sandbox per repository;
- a repository-specific checkout/worktree;
- the issue's dedicated branch;
- `idleTimeoutMinutes: 30`.

The 30-minute inactivity timeout is mandatory. The Sandbox must be automatically destroyed after 30 minutes without activity. Do not keep idle Sandboxes running indefinitely.

The intended lifecycle is:

- reuse the existing usable Sandbox for that repository;
- create a Sandbox only when none exists or the previous one has expired;
- never use one repository's Sandbox for another repository;
- keep the Sandbox while development or validation work is actively progressing;
- let the configured 30-minute inactivity timeout destroy it after work stops;
- on the next issue, call `repository_prepare` again and reuse or recreate the Sandbox as appropriate.

Do not manually destroy a Sandbox immediately after an issue is merged. The inactivity timeout controls its lifecycle.

Before an intentional pause that may exceed 30 minutes, preserve all work that must survive Sandbox expiry. Normally this means completing a safe local checkpoint and, when necessary, pushing a clearly labelled checkpoint commit to the issue branch. This is an exception to the no-intermediate-push rule in section 7 and must never be used to open a premature PR or bypass validation. Never rely on uncommitted or Sandbox-local-only changes surviving Sandbox expiry.

Preserve valid Composer/npm/Yarn dependencies and other reusable environment state while the Sandbox remains active. Do not reinstall dependencies merely because a new issue is being processed. Update only the affected dependency set when lockfiles or manifests change.

## 4. Implement the issue inside the Sandbox

- Perform all development in the prepared repository worktree and issue branch.
- Never modify the default branch directly.
- Implement the complete approved issue plan.
- Follow the repository's established architecture, security, accessibility, naming, and testing conventions.
- Prefer small, coherent changes that solve the requested problem.
- Add or update tests for changed behavior. Do not weaken, delete, or skip tests merely to make validation pass.
- Update documentation when behavior, configuration, setup, or operational procedures change.
- Keep secrets, credentials, and environment-specific values out of source control.
- Do not make unrelated dependency upgrades or broad refactors unless required by the issue.

## 5. Use focused validation during development

Use the smallest relevant validation that provides useful feedback while iterating.

Examples:

- run the affected test or test file when changing a specific behavior;
- run the relevant formatter/linter when changing formatting-sensitive code;
- run the relevant static-analysis scope when changing PHP types or analysis-sensitive code;
- run targeted frontend tests/build checks when changing frontend behavior;
- run focused migration or database regression tests when changing schema or queries;
- expand validation when shared infrastructure or cross-domain behavior is affected.

Use the loop:

`change → focused validation → fix → focused validation`

Do not run the complete repository validation after every small edit unless the change genuinely requires it.

## 6. Run complete local validation before the PR

Once implementation and focused validation are complete:

1. Re-read the current CI workflow files.
2. Reconcile the commands in `.chatgpt/repository.md` with the actual workflows, manifests, lockfiles, and scripts.
3. Run the complete local validation required by the current CI workflow, including applicable formatting, static analysis, tests, migrations, dependency audits, frontend tests, and production builds.
4. Run relevant database-specific checks using the repository's documented database engines/versions.
5. Verify that the documented commands actually exist before invoking them.

Do not blindly copy commands from another repository. Do not treat non-existent Composer/npm scripts as valid commands. If a documented command is stale, correct `.chatgpt/repository.md` as part of the work when appropriate.

The full local validation is the pre-PR gate. If it fails:

1. identify the failed command and first meaningful error;
2. determine the root cause rather than treating downstream errors as the cause;
3. reproduce and fix the issue in the Sandbox;
4. rerun focused validation;
5. rerun the complete validation;
6. repeat until the required local validation is clean.

Do not create or update a PR while required local validation is failing. Do not use GitHub Actions as a substitute for local development feedback.

If a check cannot be run locally because it requires an unavailable service or protected CI secret, document the limitation and ensure the corresponding CI check remains enabled. Never claim an unrun check passed.

## 7. Avoid intermediate pushes

Do not push the issue branch during active implementation merely to obtain CI feedback.

The normal flow is:

1. prepare the issue branch;
2. implement the complete issue;
3. iterate using focused local validation;
4. run complete local validation;
5. push the validated branch;
6. create or update the PR;
7. wait for GitHub Actions.

Avoid push → CI → fix → push cycles when the same feedback can be obtained locally.

The only normal exception is a checkpoint required to preserve unfinished work before a planned pause that may exceed the 30-minute Sandbox inactivity timeout. A checkpoint push is not a PR-ready signal and must not bypass the full validation gate.

## 8. Create or update the Pull Request

Only after the complete local validation is clean:

- push the issue branch;
- create or update the corresponding PR;
- ensure the PR references the issue;
- describe the implementation, important design decisions, and validation actually performed;
- include only changes required for the issue;
- disclose any checks that could not be run locally.

Do not create commits solely to trigger CI when the underlying implementation has not changed.

## 9. Treat GitHub Actions as the final independent gate

After creating or updating the PR, inspect the actual GitHub Actions run and every required job.

GitHub Actions is the final independent validation gate. A clean local run does not replace CI.

If CI fails:

1. identify the failed job and step;
2. identify the exact command and first meaningful error;
3. distinguish the root cause from downstream failures;
4. identify the code/configuration responsible;
5. reproduce the failure in the persistent Sandbox;
6. fix the root cause;
7. run focused local validation;
8. rerun complete local validation;
9. push the correction;
10. inspect the new CI run.

Do not rerun deterministic failures blindly. For transient or infrastructure failures with unchanged code, use the appropriate rerun mechanism rather than creating an unnecessary commit.

CI optimization is encouraged only when it preserves required checks. Prefer dependency caching, parallel independent jobs, and cancellation of obsolete PR runs where appropriate. Never weaken required checks to make CI faster or green.

## 10. Complete the issue

An issue is complete only when:

- implementation is complete;
- required local validation is clean;
- the PR has passed all required GitHub Actions checks;
- the PR has been merged according to repository policy;
- the issue is closed, automatically or manually;
- the final PR and issue state has been verified.

Do not declare completion while CI is failing. Do not close an issue with incomplete implementation. Do not move to the next issue until the current one is fully completed.

## 11. Keep repository instructions accurate

When a task changes runtimes, dependencies, scripts, CI jobs, databases, or validation requirements:

- update `.chatgpt/repository.md` to reflect the verified current state;
- update this canonical workflow only when a rule should apply to every participating repository;
- keep the shared `osingle.md` copies byte-identical;
- never put repository-specific commands or versions into the shared workflow.

Periodically verify that every repository's `.chatgpt/osingle.md` matches the canonical copy and that its `.chatgpt/repository.md` matches the actual repository configuration.

## Non-negotiable constraints

- One repository → one repository-specific Sandbox → one issue branch at a time.
- Set the Sandbox inactivity timeout to exactly 30 minutes on every `repository_prepare` call.
- Reuse a usable Sandbox; recreate it after expiry.
- Never share a Sandbox between repositories.
- Never modify the default branch directly.
- Never discard unexplained working-tree changes.
- Prefer focused local validation during implementation.
- Run complete local validation before creating/updating a PR.
- Do not push intermediate states just to obtain CI feedback, except for a necessary pre-expiry checkpoint.
- Do not weaken or remove tests or required CI checks.
- Never claim checks passed when they were not run.
- Never merge or close an issue while required CI is failing.
- Do not move to another issue until the current issue is merged and verified complete.
