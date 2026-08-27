# MERDPOS Beta AI State

**Updated:** 2026-08-27
**Authoritative repository:** `leopardinstinct-web/MerdPOSDev`
**Authoritative branch:** `namecheap-beta-live`
**Deployable tree:** `namecheap_beta_live/`

## Start here for future AI sessions

A future chat with GitHub access should read this file before planning MERDPOS beta work.

The beta webapp is in an **active product-design and restructuring stage**. Navigation, panels, workflows, inclusion/exclusion of features, copy and visual design are expected to keep changing. Do not treat the current UI structure as a permanent contract unless the user explicitly says a section is stable.

## Current implementation checkpoint

The permanent regression suite was merged into `namecheap-beta-live` at:

`df0d690dce1a312fbb523bd80c89715492b5b4b3`

That merge includes:

- authenticated live read-only audit tooling;
- authorization-matrix verification;
- DEV/Developer store-identity regression coverage;
- DUMMY-only destructive Financial and core transaction runners;
- manual DUMMY destructive GitHub workflow;
- CI path scoping so portal-only work does not unnecessarily run Flutter/Android/root-backend suites.

Do not claim this commit is deployed merely because it is on the branch. Continue using the deployment-state discipline below.

## Product-stage testing strategy — binding until the user changes it

The current goal is **product development, not exhaustive UI automation**.

### Keep and maintain now

1. **Runtime smoke coverage**
   - portal loads;
   - no browser runtime errors on critical entry paths;
   - no unexpected failed application HTTP responses.

2. **Security and authorization contracts**
   - `client role → LOA → named permission → UI/API/data scope`;
   - DEV-only permissions require actual DEV identity, not LOA 1000 alone;
   - tenant isolation must not be weakened;
   - destructive tests must be scoped to exact DUMMY identity/context and must abort otherwise.

3. **Stable business invariants**
   - frozen payroll/timesheet reconciliation logic;
   - Financial backend/business contracts that have already stabilised;
   - critical permission boundaries and server-side validation.

4. **Deployment/runtime guardrails**
   - canonical runtime assets;
   - loader order;
   - beta-state permission scoping;
   - Namecheap deploy recovery guards;
   - secret/build-artifact checks.

### Do not over-automate yet

While the user is still moving, redesigning, adding and removing webapp features:

- do not make navigation labels, panel order, DOM structure or cosmetic layout a permanent contract;
- do not build large Playwright suites for every button and CRUD path merely because the current screen exists;
- do not block product changes because an old UI-flow test is brittle;
- do not spend substantial effort automating unfinished flows such as Attendance QR or evolving Dispute UX unless the user explicitly prioritizes them;
- do not confuse existing scaffolding/endpoints with a finished product feature.

### Promotion rule

Use this progression for a changing feature:

`BUILD/CHANGE → QUICK SMOKE → PERMISSION/SECURITY CHECK → DEPLOY → VISUAL/RUNTIME VERIFY`

Only when the user treats a workflow as relatively stable should its important behavior be promoted into permanent regression coverage.

Preferred permanent tests assert **business outcomes and authorization contracts**, not incidental UI placement.

## DUMMY destructive testing policy

- All destructive/write automation must target DUMMY only.
- Verify the exact DUMMY client identifier at runtime; do not assume a database ID.
- Abort before mutation if the active tenant is not the expected DUMMY tenant.
- Never use a DEV client switch as proof that employee-owned actions are DUMMY-safe; endpoints that bind to the authenticated employee's owning client must be tested with a genuine DUMMY-native identity or skipped.
- Never mutate MERD production data for regression purposes.
- Test-created records should be uniquely named and cleaned up/deactivated/voided when practical.
- Credentials/storage-state/cookies must never be committed.

## Current verified baseline

### Live read-only Developer regression

Previously verified on the live beta with no browser runtime errors or failed app HTTP responses across the core read-only Developer surfaces, including mobile 390×844 checks.

### DUMMY Financial

A live DUMMY run verified:

- Open Day;
- Cash In;
- Cash Out;
- Z Report / close;
- final statement reload and closing-state assertions.

### DUMMY core transaction runner

A live DUMMY run completed:

`DUMMY_CORE_OK total=30 passed=30`

Coverage includes Workforce CRUD, Store CRUD, Role/permission mutations with restoration, report/panel surfaces, mobile basics, runtime errors, failed app HTTP responses and final DUMMY context preservation.

This does **not** mean every product workflow is permanently stable or should now receive exhaustive UI automation.

## Work intentionally not promoted yet

A follow-up branch named `dummy-native-disputes` was created to explore DUMMY-native login and full Disputes lifecycle testing. That work is **experimental/unmerged** and should not be merged or deployed merely because it exists.

Reason: the webapp remains in active redesign, and exhaustive Dispute/Attendance workflow automation is not currently the highest-value use of effort.

If future work returns to this branch, review its security model and current product requirements first rather than assuming it should be completed.

Attendance QR/device automation remains intentionally deferred until the feature itself is sufficiently complete/stable.

## CI scope policy

Portal-only changes should rely primarily on:

- Repository hygiene;
- Beta source contract;
- Beta Chromium smoke;
- Beta secret scan / repository secret scan.

Flutter/Android jobs should run only when `merdpos_staff/` changes (or on an explicit full/manual CI run).

Root `backend/` PHP/catalogue jobs should run only when their relevant backend area changes (or on an explicit full/manual CI run).

Do not reintroduce unrelated heavy CI jobs for portal-only changes without a concrete dependency reason.

## Existing invariants retained

### Frozen payroll logic — do not modify

- pair `IN → next OUT`;
- newer IN replaces an unmatched prior IN;
- orphan OUT ignored;
- independently round IN and OUT to nearest 15 minutes;
- payable time = rounded OUT − rounded IN;
- cross-midnight allowed;
- no 16-hour cap;
- wage rate selected by clock-in date.

### Authorization

Binding model:

`client role → LOA → named permission → UI/API/data scope`

Named permissions are independently configurable. Do not invent parent-child permission dependencies.

DEV-only capability requires actual DEV identity.

### Runtime assets

Canonical runtime assets include:

- `design-tokens.css`
- `design-system.css`
- `shell.css`
- `app-ui.css`
- `dashboard-builder.css`
- `account-menu.css`
- `minimal-controls.js`
- `mobile-runtime.js`
- `design-audit.js`
- `management.js`

Retired corrective CSS must not be restored:

- `ui-standard.css`
- `minimal-controls.css`
- `mobile-hardening.css`
- `apple-principles.css`
- `omnichannel-identity.css`

`design-audit.js` remains required.

## Deployment architecture

Do not restore GitHub→Namecheap SSH deployment from the development PC.

Namecheap pulls/mirrors the authoritative beta branch through the established server-side process using `scripts/deploy_namecheap_beta.sh`.

Public beta URL:

`https://app.merdpos.com/beta/timesheet_portal/`

Historical server target:

`~/merdpos.com/app/beta`

Server mirror:

`~/git/MerdPOSDev-beta-mirror`

## Implementation-state discipline

Always use:

`REQUESTED → DOCUMENTED → CODED → WIRED → DEPLOYED → VERIFIED`

Never broadly call a source change live/fixed/working until the intended commit is confirmed by the Namecheap deployment marker/process and the affected runtime path is actually checked.

## Default priority for new work

Unless the user gives a different priority:

1. build/redesign/fix the actual beta product;
2. protect stable security/business contracts;
3. run quick targeted smoke/runtime verification;
4. deploy through the established Namecheap pull process;
5. promote a workflow into permanent regression only after it becomes sufficiently stable.

The regression suite is a safety net for development, not the product-development goal itself.
