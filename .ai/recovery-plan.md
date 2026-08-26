# MERDPOS Beta Recovery Plan

## Goal

Stop the beta regression cycle by making every source change observable, testable, deployable and reversible while preserving the authoritative runtime contract.

## Phase 1 — Contain regressions

Status: **IN PROGRESS**

- Establish `.ai/` repository state so new sessions can recover the exact branch, baseline, decisions and next action from source control.
- Add beta-specific GitHub guardrails for PHP syntax, portal permission coverage, beta runtime-contract validation, JavaScript syntax and secret scanning.
- Do not make broad UI/runtime rewrites while the app is unstable.
- Do not delete current contract assets merely because an older handoff says to remove them.
- Keep each recovery fix small enough to attribute a regression to a specific change.

Exit criteria:

- Every push/PR to `namecheap-beta-live` receives deterministic source checks.
- The current branch source passes those checks.
- The deployed commit marker is known before any runtime repair is declared verified.

## Phase 2 — Build a regression inventory

Status: **NEXT**

Audit representative feature paths rather than only visual screenshots:

1. Login/session bootstrap.
2. Dashboard load and widget controls.
3. Mobile navigation/subnavigation.
4. Shared Add/Search controls.
5. Stores directory.
6. Workforce directory.
7. Clients directory.
8. Roles and Permission Policy.
9. Timesheet report and week navigation.
10. Dialogs/forms with mobile software keyboard.
11. Legacy migration DEV-only paths.

For each path record:

- expected behavior;
- current source entry point;
- JS module(s);
- API endpoint/action;
- permission key and LOA expectation;
- schema dependency;
- desktop result;
- mobile result;
- implementation state.

## Phase 3 — Deterministic browser smoke tests

Status: **PLANNED**

Add Playwright only after deciding which test paths can run safely without production credentials.

Preferred order:

- unauthenticated login-page structural smoke test;
- local/static component fixture tests for shared Add/Search/navigation behavior;
- authenticated tests only with explicit non-production fixture accounts and an isolated test database/session strategy.

Never commit real credentials or make CI depend on a production login secret by default.

## Phase 4 — Repair regressions

Status: **PLANNED**

For every fix:

1. Reproduce the failure.
2. Identify the smallest owning module.
3. Inspect the complete UI → JS → API → auth/client context → DB → response → render path where applicable.
4. Patch source without altering frozen payroll/timesheet reconciliation.
5. Run beta source guardrails.
6. Update relevant README/context when runtime behavior or architecture changes.
7. Commit to `namecheap-beta-live`.
8. Run the immediate Namecheap deploy.
9. Confirm `.beta_deployed_commit`.
10. Verify the real affected runtime path.

## Phase 5 — Prevent recurrence

Status: **PLANNED**

- Expand regression coverage from actual incidents.
- Keep shared UI ownership centralized.
- Fail deployments when canonical runtime wiring or permission coverage drifts.
- Maintain `.ai/memory.md` after each major recovery checkpoint.
- Prefer source-controlled state over chat-only memory.
