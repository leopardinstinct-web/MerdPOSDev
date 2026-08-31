# MERDPOS Beta AI State

**Updated:** 2026-09-01
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

1. Keep the Beta product moving.
2. Preserve authorization, tenant isolation, frozen payroll logic and deployment safety.
3. Keep GitHub continuity state truthful after substantive work.
4. Close/archive completed work packets promptly.
5. Promote reusable lessons into decisions/playbook/regression guards instead of leaving them only in chat.

## Repository governance

`namecheap-beta-live` is protected against force pushes and branch deletion while preserving the normal direct bounded-push workflow. Beta Guardrails and Namecheap deploy guards remain the release safety net; a protected branch alone is not verification.
