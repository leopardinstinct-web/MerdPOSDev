# MERDPOS Beta Recovery Plan — HISTORICAL

> **Status:** Historical recovery document. Do not use this as the current work queue.
>
> Current authority is `AGENTS.md` → `.ai/README.md` → `.ai/invariants.md` → `.ai/memory.md` → `.ai/decisions.md` → `.ai/playbook.md`.
>
> The recovery phases below explain how the current safety net was reached. Several planned items were completed or intentionally superseded by the later product-stage testing strategy. In particular, the beta is now in active product redesign and should not receive exhaustive UI automation merely because this old plan listed a path.

## Original goal

Stop the beta regression cycle by making every source change observable, testable, deployable and reversible while preserving the authoritative runtime contract.

## Phase 1 — Contain regressions

Historical status: **COMPLETED**

- Established `.ai/` repository state.
- Added beta-specific GitHub guardrails for syntax, permission coverage, runtime-contract validation, browser smoke and secret scanning.
- Preserved canonical runtime assets and small attributed recovery fixes.
- Established deployment-state discipline.

## Phase 2 — Build a regression inventory

Historical status: **COMPLETED / SUPERSEDED IN PART**

Representative paths were audited and a permanent safety net was created. The current inventory is `.ai/regression-inventory.md`.

The earlier idea of treating every current UI path as a permanent regression target is superseded by the stage-aware testing decision in `.ai/decisions.md`.

## Phase 3 — Deterministic browser smoke tests

Historical status: **COMPLETED / EVOLVED**

Playwright/browser smoke coverage was introduced. Authenticated testing later became safe through external storage state and DUMMY-only destructive isolation.

The original blanket deferral of authenticated Playwright is superseded by the DUMMY-isolated regression decision.

## Phase 4 — Repair regressions

Historical status: **RECOVERY LOOP ESTABLISHED**

The core procedure remains useful and is now maintained in `.ai/playbook.md`:

1. reproduce the failure;
2. identify the smallest owning module;
3. trace UI → JS → API → auth/client context → DB → response → render where applicable;
4. patch without altering frozen payroll/timesheet reconciliation;
5. run relevant beta guardrails;
6. update source-controlled context when architecture/behavior changes;
7. merge/update the authoritative beta branch;
8. use the established Namecheap pull/mirror/deploy process;
9. confirm deployment evidence;
10. verify the real affected runtime path.

## Phase 5 — Prevent recurrence

Historical status: **ONGOING AS NORMAL DEVELOPMENT PRACTICE**

- protect stable incident-derived regressions;
- keep shared runtime ownership centralized;
- fail deployments when canonical runtime wiring or permission coverage drifts;
- maintain the GitHub knowledge layer after meaningful changes;
- prefer source-controlled state over chat-only memory.

## Current replacement strategy

See `.ai/memory.md` and `.ai/playbook.md`.

For unstable/evolving features:

`BUILD/CHANGE → QUICK SMOKE → PERMISSION/SECURITY CHECK → DEPLOY → VISUAL/RUNTIME VERIFY`

Promote important behavior into permanent regression only when reasonably stable or explicitly prioritised by the product owner.
