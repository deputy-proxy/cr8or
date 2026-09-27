## 1. Select the issue

Get the **oldest open issue** in `deputy-proxy/cr8or`.

Read the **implementation plan in the first comment** of the issue and treat that plan as the primary implementation specification.

If there are no open issues, do nothing.

## 2. Inspect the repository before coding

Before writing any code, re-check the current repository state and configuration:

- `.github/AI_DEVELOPMENT_RULES.md`
- the applicable GitHub Actions workflow(s)
- `composer.json`
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

## 4. Implement the issue inside the Sandbox

Perform all development work inside the prepared persistent Sandbox and repository worktree.

Use the issue branch associated with the current issue.

Do not modify `main` directly.

Implement the complete implementation plan from the issue.

Follow the repository's established architecture, naming conventions, patterns, and testing approach.

## 5. Validate locally

Before creating or updating the PR, run the repository's appropriate validation commands inside the Sandbox.

At minimum, use the checks required by the repository's CI workflow.

Do not declare the issue complete merely because the code appears correct.

If local validation fails:

1. identify the root cause;
2. fix the underlying problem;
3. rerun the relevant checks;
4. continue until the local checks are clean.

## 6. Create/update the Pull Request

Push the issue branch and create or update the corresponding Pull Request.

The PR should contain only the changes required for the current issue.

Do not mix unrelated refactoring or changes from other issues into the PR.

## 7. Wait for GitHub Actions

After the PR is created or updated, inspect the **actual GitHub Actions results**.

If CI fails, do not simply fix the first visible error.

For every failed check:

1. identify the failed CI job/step;
2. identify the exact command that failed;
3. identify the first meaningful error;
4. determine whether it is the root error or a downstream consequence;
5. identify the changed code/configuration responsible;
6. fix the root cause;
7. run the relevant validation again inside the persistent Sandbox;
8. push the fix;
9. wait for CI again.

Repeat until CI is green.

## 8. Complete the issue

Once CI is green:

- merge the Pull Request according to the repository workflow;
- close the issue if it is not automatically closed by the merge;
- verify that the issue and PR are in the expected final state.

Do not move to another issue until the current issue has been successfully completed.

## 9. Preserve the persistent Sandbox

After completing the issue, leave the repository Sandbox intact.

Do not destroy the Sandbox merely because the issue is finished.

The next scheduled run must attempt to reuse the **same repository-specific Sandbox** and prepare it for the next issue.

The Sandbox may therefore accumulate the development environment, dependencies, and repository checkout across the entire development phase.

## Important constraints

- Never create a fresh Sandbox for every issue.
- Never use one repository's Sandbox for another repository.
- Never assume Sandbox state without verifying it through `repository_prepare`.
- Never skip repository-specific development rules.
- Never skip CI verification.
- Never treat a downstream CI error as the root cause without investigation.
- Never close an issue while its implementation is incomplete or CI is failing.
- Never modify `main` directly.
- Keep each issue isolated on its own branch.
- Preserve the persistent Sandbox after completing the issue.

The intended architecture is:

`one repository → one persistent Sandbox → many issue branches → many development loops`
