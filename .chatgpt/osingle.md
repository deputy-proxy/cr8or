## 1. Select the issue

Get the **oldest open issue** in `deputy-proxy/cr8or`.

Read the **implementation plan in the issue body** and treat that plan as the primary implementation specification.

If there are no open issues, do nothing.

## 2. Inspect the repository before coding

Before writing any code, re-check the current repository state and configuration:

- `.github/AI_DEVELOPMENT_RULES.md`
- the applicable GitHub Actions workflow(s)
- `composer.json`
- `package.json`
- all relevant tool configuration
- the existing implementation around the affected functionality
- the existing tests around the affected functionality

Treat repository configuration, existing code, and CI workflows as authoritative.

Do not substitute generic Laravel/PHP conventions when the repository establishes a different convention.

## 3. Prepare the persistent development Sandbox

Use the Railway Sandbox MCP `repository_prepare` flow for:

`deputy-proxy/cr8or`

Prepare the Sandbox for the issue's dedicated development branch.

The Sandbox is **persistent and repository-specific**.

Do NOT create a new Sandbox merely because a new issue is being processed.

The expected model is:

- one persistent Sandbox for `deputy-proxy/cr8or`
- one development branch per issue
- reuse the existing Sandbox for subsequent issues
- create a new Sandbox only when no usable persistent Sandbox for this repository exists

`repository_prepare` must establish the correct repository state and issue branch before implementation begins.

Preserve the existing development environment and installed dependencies in the persistent Sandbox whenever possible.

Do not reinstall dependencies merely because a new issue is being processed if the existing Sandbox already has a valid dependency state.

If dependency files changed, update only the affected dependency set as required.

## 4. Implement the issue inside the Sandbox

Perform all development work inside the prepared persistent Sandbox and repository worktree.

Use the issue branch associated with the current issue.

Do not modify `main` directly.

Implement the complete implementation plan from the issue body.

Follow the repository's established architecture, naming conventions, patterns, and testing approach.

The implementation loop must remain local to the persistent Sandbox until the work is ready for the Pull Request.

Do not push intermediate implementation states merely to obtain CI feedback.

## 5. Use focused validation during development

During implementation, prefer the **smallest relevant validation** that gives useful feedback for the change.

Examples:

- When changing a specific service, capability, operation, or domain workflow, run its focused tests first.
- When changing a specific test suite, run that suite rather than the complete test suite.
- When changing PHP formatting, run the relevant lint/format validation.
- When changing PHP types or static-analysis-sensitive code, run the relevant PHPStan scope where practical.
- When changing frontend code, run the relevant frontend validation/build when practical.
- When changing shared infrastructure or behavior with broad impact, expand validation accordingly.

Do not run the complete repository validation after every small code change unless the change genuinely requires it.

Use focused validation repeatedly while iterating:

`change → focused validation → fix → focused validation`

The purpose is to catch local errors quickly without repeatedly paying the cost of the complete repository validation.

## 6. Full validation only before the Pull Request

Once implementation is complete and focused validation is clean, perform the **complete validation required by the repository's CI workflow**.

At minimum, reconcile the current workflow and run its equivalent checks locally.

For the current repository, this includes the configured PHP validation and frontend build requirements.

The full local validation is the **pre-PR gate**.

If full local validation fails:

1. identify the root cause;
2. fix the underlying problem;
3. rerun the relevant focused validation;
4. rerun the complete validation;
5. continue until the complete local validation is clean.

Do not create or update the Pull Request while the complete local validation is failing.

Do not use GitHub Actions as a substitute for local development feedback.

## 7. Avoid intermediate pushes

Do not push the issue branch during active implementation unless there is a specific repository or operational requirement that makes an intermediate push necessary.

The normal flow is:

1. prepare the issue branch;
2. implement the complete issue;
3. use focused validation during development;
4. run complete local validation once the implementation is ready;
5. push the completed issue branch;
6. create or update the Pull Request;
7. wait for GitHub Actions.

Avoid push → CI → fix → push cycles when the same feedback can be obtained locally.

The first normal push for an issue should represent a locally validated implementation suitable for PR review.

## 8. Create/update the Pull Request

Push the issue branch only after the complete local validation is clean and create or update the corresponding Pull Request.

The PR should contain only the changes required for the current issue.

Do not mix unrelated refactoring or changes from other issues into the PR.

Do not create additional commits solely to trigger or inspect CI if the underlying implementation has not changed.

## 9. GitHub Actions remains the final independent gate

After the PR is created or updated, inspect the **actual GitHub Actions results**.

GitHub Actions remains authoritative for final validation. Local validation does not replace CI.

The repository's CI should be optimized to avoid unnecessary repeated work:

- cache Composer dependencies using the repository's Composer lockfile as the cache key/input;
- cache npm dependencies using the repository's npm lockfile as the cache key/input;
- run independent PHP and frontend validation in parallel jobs where the repository workflow permits;
- avoid performing frontend setup/build as part of the PHP validation job when it can run independently;
- cancel obsolete in-progress PR CI runs when a newer commit supersedes them.

These optimizations must preserve the same validation requirements and must not weaken required checks.

If the current GitHub Actions workflow does not yet provide these optimizations, identify that discrepancy and treat the workflow optimization as a repository-level change rather than silently bypassing CI.

If CI fails:

1. identify the failed CI job/step;
2. identify the exact command that failed;
3. identify the first meaningful error;
4. determine whether it is the root error or a downstream consequence;
5. identify the changed code/configuration responsible;
6. reproduce the failure in the persistent Sandbox;
7. fix the root cause;
8. run the relevant focused validation;
9. run the complete local validation;
10. push the correction;
11. wait for CI again.

Do not rerun CI blindly when the failure is deterministic.

If a failure is transient or infrastructure-related and the implementation is unchanged, use the appropriate GitHub Actions rerun mechanism rather than creating an unnecessary code commit.

Repeat until CI is green.

## 10. Complete the issue

Once CI is green:

- merge the Pull Request according to the repository workflow;
- close the issue if it is not automatically closed by the merge;
- verify that the issue and PR are in the expected final state.

Do not move to another issue until the current issue has been successfully completed.

## 11. Preserve and reuse the persistent Sandbox

After completing the issue, leave the repository Sandbox intact.

Do not destroy the Sandbox merely because the issue is finished.

The next scheduled run must attempt to reuse the **same repository-specific Sandbox** and prepare it for the next issue.

Preserve:

- the repository checkout;
- installed Composer dependencies;
- installed npm dependencies;
- other reusable development tooling;
- any other valid environment state.

Do not assume the Sandbox state is valid without verifying it through `repository_prepare`.

The intended architecture is:

`one repository → one persistent Sandbox → many issue branches → many focused development loops → full validation → PR → CI`

## Important constraints

- Never create a fresh Sandbox for every issue.
- Never use one repository's Sandbox for another repository.
- Never destroy the persistent Sandbox after completing an issue.
- Never assume Sandbox state without verifying it through `repository_prepare`.
- Never skip repository-specific development rules.
- Never skip final CI verification.
- Never treat GitHub CI as the primary development feedback loop.
- Prefer focused local validation during implementation.
- Run complete local validation before creating/updating the PR.
- Do not push intermediate implementation states merely to obtain CI feedback.
- Keep each issue isolated on its own branch.
- Do not weaken or remove tests to make validation pass.
- Do not weaken required GitHub checks.
- Do not declare an issue complete while CI is failing.
- Do not close an issue while its implementation is incomplete or CI is failing.
- Do not move to another issue until the current issue is merged and completed.

The intended optimized architecture is:

`one repository → one persistent Sandbox → one issue branch → focused local validation → full local validation → one PR push → parallel/cached GitHub CI → merge → reuse Sandbox`
