# MERDPOS Beta AI State

**Updated:** 2026-09-06
**Authoritative repository:** `leopardinstinct-web/MerdPOSDev`
**Authoritative branch:** `namecheap-beta-live`
**Deployable tree:** `namecheap_beta_live/`

## Current product state

MERDPOS Beta is still in active product design/restructuring. Current navigation labels, panel order, DOM shape and cosmetic placement are not permanent contracts unless explicitly stabilised.

The current shared portal runtime includes the canonical design system, bottom-dock shell, account utility sheet, dashboard builder, shared analytics runtime, mobile runtime and DevStudio. Retired corrective CSS layers remain retired.

Current database migrations include:
- 031 role/dashboard templates;
- 032 initial role dashboards;
- 033 portal permission levels;
- 034 Google legacy migration sync;
- migration 035: DevStudio global state/audit;
- migration 036: store week-start day.

## Current DevStudio checkpoint

DevStudio is an actual-DEV-only global unresolved implementation inbox plus preview/handoff tool.

- Browser sessions receive unresolved patches only; backend audit history is not exposed in DS.
- Every patch has a stable `patchId` and status.
- Copy for ChatGPT emits v6 unresolved-patch JSON and a required machine-readable receipt contract.
- Paste LLM Receipt records revisioned status transitions; `confirmed_applied` removes only the matching patch from the active inbox while retaining backend audit.
- Developer is the visual master. DEV patches inherit to DEV/ADMIN/SUPER/USER; Admin to ADMIN/SUPER/USER; Super to SUPER/USER; User to USER.
- Lower-role Add/Comment actions are proposals/requests that must be implemented from Developer master.
- Comments may include multiline text and tokenized image context.
- Studio29 adds Settings → MERDPOS Palette for view/edit/reorder/add/delete preview operations. Palette edits create one global unresolved `palette` patch; canonical design tokens do not change until normal implementation/deploy/verify/receipt completion.
- Full radial dismissal, including Ctrl+D/Minimize/outside dismissal, clears the selected element. Move destination mode is the explicit preserve-selection exception.
- Working client and Current role account contexts are minimizable with persisted local collapse state.

## Current analytics/dashboard checkpoint

MERDPOS uses its own feature-scoped analytics runtime rather than React/Tailwind/Google Charts runtime dependencies.

- Typed `dataset → view → renderer` contract.
- Responsive SVG bar/line/donut charts.
- Keyboard/click selection emits `merdpos-chart-select`.
- Dashboard Store drill-down and 7/14/30-day period filters reload through `api/dashboard_data.php`.
- Store filter choices and returned datasets remain permission/dependency scoped; own-attendance-only users cannot discover the full client Store directory.
- `My current shift` remains self-scoped and independent of dashboard Store filtering.

## Current implementation-patch workflow

For every DevStudio implementation patch use:

`active patch → canonical implementation → tests → deploy exact commit → live verification → LLM receipt / confirmed_applied → patch leaves unresolved inbox`

Backend audit/history is retained. Never confirm or remove unrelated/unverified patches.

## Current deployment discipline

Namecheap uses the established server-side pull/mirror process and `scripts/deploy_namecheap_beta.sh`.

A commit on GitHub is not deployment evidence. DEPLOYED requires the intended commit in the Namecheap deployed marker/process. VERIFIED additionally requires the affected real runtime behavior to be exercised and observed.

The latest known runtime feature generation before this continuity-maintenance task is Studio29 (`20260831studio29`) with analytics generation `20260831analytics2`. A fresh session must still resolve current branch HEAD and deployment evidence rather than assuming these strings remain latest forever.

## Pre-live Google Time Sheet refresh

Actual DEV has a Working client account-sheet utility for a full Google `Time Sheet` ? SQL attendance refresh. It is client-scoped, validates the complete source before one transactional `employee_logs` replacement, and intentionally does not change Google/SQL migration authority. This is a temporary pre-live workflow and should be reassessed at attendance cutover.

## Current regression/release posture

Beta Guardrails run PHP lint, runtime-contract validation, portal permission policy, loader-order validation, shared-state scope validation, deploy recovery guards, JavaScript syntax checks, Chromium browser regressions and secret scanning.

The current product remains under active redesign, so permanent tests should protect business/security/runtime outcomes and deliberately stabilised UI contracts rather than freeze incidental layout.

## Binding safety reminders

Frozen payroll/timesheet logic remains unchanged unless the product owner explicitly changes it:
- pair IN → next OUT;
- newer IN replaces unmatched prior IN;
- orphan OUT ignored;
- independently round IN/OUT to nearest 15 minutes;
- payable = rounded OUT − rounded IN;
- cross-midnight allowed;
- no 16-hour cap;
- wage rate by clock-in date.

Authorization remains:

`client role → LOA → named permission → UI/API/data scope`

UI hiding is not security. Actual DEV identity is required for DEV-only tooling. Destructive regression writes are DUMMY-only and must abort before mutation unless exact DUMMY context/identity is proven.

## Fresh-session continuity rule

A fresh session must follow `AGENTS.md` → `.ai/README.md` → `.ai/invariants.md` → `.ai/task-gates.md` → `.ai/work/ACTIVE.yaml`, then load only task-relevant current source/history and targeted durable knowledge.

Current code outranks documentation. Current binding invariants outrank memory. Historical decisions remain provenance unless explicitly current/superseding.

Do not reconstruct current implementation state from old Studio version notes or chat history. Use current source, current `ACTIVE.yaml`, current tests/validators, recent commits and deployment evidence.

## Current priority

The active Beta queue is defined in `.ai/BETA_SCOPE.md`. Attendance QR, Attendance → Dispute, and Timesheet reconciliation were **VERIFIED live on 2026-09-07** with a 26/26 DUMMY E2E run and final `ATTENDANCE_E2E_AUDIT none`. Administration acceptance and the Admin Roles/shell follow-up are **VERIFIED live on 2026-09-08**. The immediate acceptance milestone is now **Finance Beta acceptance**. Ordered Beta work is:

1. Attendance QR end-to-end — VERIFIED.
2. Attendance → Dispute end-to-end — VERIFIED.
3. Timesheet reconciliation with frozen payroll rules unchanged — VERIFIED.
4. Administration acceptance for Clients, Stores, Workforce and Roles — VERIFIED.
5. Approved Finance Beta workflows/validations only — CURRENT.
6. Legacy/Google migration reconciliation and production readiness.
7. Role-by-role Beta acceptance and scope freeze.

The old Flutter/full-POS roadmap, `docs/pos_latest/`, M3.x and **M3.3 Checkout & Tender** are explicitly outside the current Beta queue unless the product owner reopens that track. Do not suggest them as the next Beta step.

## Repository governance

`namecheap-beta-live` is protected against force pushes and branch deletion while preserving the normal direct bounded-push workflow. Beta Guardrails and Namecheap deploy guards remain the release safety net; a protected branch alone is not verification.

## 2026-09-30 checkpoints

- Reports contrast fix + UI makeover (`/merdpos/reports`, branch `beta/drupal-webapp`, commits `b9cc47d`..`7655fbe`): small accent text/icons consume `--color-brand-text-accent` (cyan 65% toward navy on light; 30% cyan toward white under `[data-theme="dark"]`); primary actions use the navy-to-violet `--gradient-brand-action` token (white text, >=7.5:1); gradient KPI chips, cyan-tint count bubbles, token-derived table inks, page-header gradient baseline, header sun/moon theme toggle (`data-merdpos-theme-toggle` + `localStorage('merdpos-theme')`, system/light/dark). Token canonical source is `namecheap_beta_live/timesheet_portal/assets/design-tokens.css`; the module copy is sync-generated (run `sync_merdpos_resources.php` after source edits).
- Evidence (2026-09-30): deployed to the Namecheap Drupal runtime via the server-side `drupal/tools/namecheap_deploy.sh` (NOT the GitHub Actions "Deploy Namecheap Beta" workflow, which belongs to the `namecheap-beta-live` pipeline and has never been the Drupal deploy path); live release marker `drupal/web/.merdpos_drupal_release.json` = commit `66bf6d3` (runtime code; `7655fbe` is docs-only); deploy validators all green; live computed-style WCAG contrast audit on the real page returned EMPTY failure lists in BOTH light and dark themes; toggle switch + persistence exercised live; 375px mobile clean. GitHub CI runs #72-77 (global UI contract) also SUCCESS. Lifecycle for the deployed baseline: DEPLOYED + VERIFIED at `66bf6d3`. The fix-forward branch `feature/drupal-reports-aa-makeover-v1` (account-menu toggle removal, dark-print snap, `#fff`/gradient-token cleanups, packet) is separate: CODED/WIRED, local checks pass, awaiting PR-triggered CI — not merged, not deployed.
- Open product-owner decisions: (a) the Drupal logo-swatch master palette (`#0A91FB`/`#01102B`/`#591DE9`, DEV-editor-backed, packet MERD-20260913-drupal-dev-master-palette-v1 VERIFIED) vs the namecheap five-color master palette (`#12BDF3`/`#031B4B`/`#8B2EFF`) — align vs record exception; this gates any merge toward `namecheap-beta-live` because deploy sync would overwrite module token values with the namecheap canonical file. (b) Owner ruling pending on the PR path for the makeover.
