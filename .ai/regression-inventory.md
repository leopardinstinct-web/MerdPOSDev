# MERDPOS Beta Regression Inventory

**Updated:** 2026-08-27

This inventory describes the current safety net. It must be read with `.ai/README.md`, `.ai/memory.md` and `.ai/playbook.md`.

The beta portal is in active redesign. This file is **not** a mandate to automate every current screen. Permanent regression should protect stable business/security/runtime contracts; evolving UI workflows should use targeted smoke and runtime verification until they stabilise.

Source/CI inspection does not equal Namecheap deployment verification.

## Current permanent safety net

| Area | Current protection | Status / intent |
|---|---|---|
| Portal runtime contract | PHP/JS source validators, canonical asset checks, loader/deploy guardrails | Permanent |
| Authorization model | Permission-policy validator, authorization matrix tooling, server-side route/data checks | Permanent |
| DEV-only boundary | Actual DEV identity required; LOA 1000 alone is insufficient | Permanent |
| Shared beta-state scope | Validator protects consumer-based route access and permission-scoped payload sections | Permanent |
| Dynamic loader order | Roles-before-Navigation and `async=false` loader behavior guarded | Permanent |
| Permission-hidden legacy runtime | Chrome smoke protects against hidden-DOM JS crashes | Permanent |
| Timesheet runtime injection | Chrome smoke guards duplicate `timesheet-app.js` loading and request multiplication | Permanent |
| Mobile UX contract | 390x844 Chrome smoke protects four-destination primary nav, utility bottom sheet, contextual page header/subtabs, labelled table cards and horizontal-overflow safety | Permanent usability/runtime contract; do not freeze exact cosmetic coordinates |
| Shared Add/Search runtime | Chrome smoke protects duplicate mutation/click behavior | Keep while component remains canonical |
| DEV Stores identity | Browser regression accepts backend `Developer` label for DEV store enrichment | Permanent incident guard |
| Authenticated read-only live audit | Reusable external-storage-state runner | Available; run when meaningful, not after every cosmetic change |
| DUMMY Financial | Open Day → Cash In → Cash Out → Z Report with final-state assertions | Live DUMMY workflow previously VERIFIED |
| DUMMY core transactions | Workforce, Stores, Roles/permissions, report surfaces, mobile basics | Live DUMMY run previously `DUMMY_CORE_OK total=30 passed=30`; use as an opt-in safety tool, not a mandatory loop for every redesign |
| DUMMY destructive workflow | Manual/opt-in GitHub workflow with external auth material | Permanent safety mechanism |
| CI product-area scoping | Portal-only changes skip unrelated mobile/root-backend heavy jobs | Permanent workflow principle |

## Intentionally not promoted to exhaustive permanent regression

### Disputes lifecycle

The existing API supports dispute create/decision operations, but full DUMMY-native employee-owned lifecycle automation is not a current product-stage requirement.

An experimental branch `dummy-native-disputes` exists. It is unmerged and should be treated as exploration, not pending mandatory work. Reassess current product UX/security requirements before using or merging it.

### Attendance QR/device workflow

Server-side attendance QR verification/scanning scaffolding exists, including cooldown handling, but the complete POS device/QR product flow was not established as a stable end-to-end feature during the audit.

Do not treat endpoint presence as feature completeness. Attendance automation remains deferred until the product flow is sufficiently complete/stable or explicitly prioritised.

### Navigation/panel/layout details

Do not permanently assert exact current:

- navigation labels;
- panel order;
- DOM hierarchy;
- cosmetic layout;
- exact wording;
- every CRUD click sequence.

These are expected to change while the product is being redesigned.

## Verified live baselines already obtained

### Read-only Developer baseline

A previous authenticated live audit completed 24/24 across core read-only Developer portal surfaces, report switching and mobile 390×844 checks with no browser runtime errors or failed application HTTP responses.

### DUMMY Financial baseline

Previously live-verified on DUMMY:

- Open Day;
- Cash In;
- Cash Out;
- Z Report / close;
- final statement reload/closed balances.

### DUMMY core baseline

Previously live-verified:

`DUMMY_CORE_OK total=30 passed=30`

Coverage included:

- Workforce create/edit/pay-rate/credential-reset/deactivate;
- Store create/edit/deactivate;
- Role create/edit/delete;
- permission LOA change + restoration;
- report/panel read surfaces;
- desktop/mobile runtime checks;
- DUMMY context preservation.

These baselines prove those workflows at that checkpoint. They do not freeze the UI or guarantee later deployment state.

## Incident-derived permanent guards

### LOA permission runtime crash

Protected by portal permission-policy validation and browser smoke. Permission-hidden UI must not crash legacy JS; API/backend permission enforcement remains authoritative.

### Shared Add MutationObserver loop

Protected by browser smoke: canonical Add control must not duplicate or trigger multiple actions under unrelated DOM mutations.

### Dynamic Roles → Navigation loader race

Protected by deterministic classic-script insertion/order validation.

### Shared state accidentally required Dashboard

Protected by `validate_beta_state_scope.php`; shared state follows consuming feature permissions while sensitive sections remain independently scoped.

### Management cards implied unavailable data

Management surfaces should not imply access to data the identity cannot actually read.

### Duplicate Timesheet runtime injection

Protected by duplicate-loader guard and browser request-count assertions.

### DEV Stores role-label mismatch

Protected by `dev-stores-runtime.spec.js`; frontend normalization accepts backend Developer/DEV identity representation without weakening DEV-only authorization.

## When to add a new permanent regression

Add one when at least one is true:

1. a stable business/security invariant needs protection;
2. a real production/beta incident has been understood and needs a narrow guard;
3. the product owner considers the workflow sufficiently stable;
4. the cost of recurrence is materially higher than test-maintenance cost.

Do not add a permanent test merely because a screen currently exists.
