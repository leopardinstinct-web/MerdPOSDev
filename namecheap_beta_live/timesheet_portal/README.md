# MERDPOS Web Beta Portal

This is the active PHP + JavaScript MERDPOS web beta deployed from branch `namecheap-beta-live`.

**Read `../README.md` first.** It is the authoritative beta runtime README and defines the beta-only project scope, implementation-state language, deployment contract, frozen payroll rules, Google migration safety and README-maintenance requirements.

## Current portal scope

The portal includes:

- numeric employee login and secure password change;
- signed POS QR attendance;
- role-specific dashboards;
- centralized client Role / LOA / named-permission authorization;
- Stores, Workforce, Roles, Clients, Defaults and DEV diagnostics;
- Timesheets and disputes;
- Finance;
- client-level Google legacy migration with Preview / Sync / Final cutover;
- shared desktop/tablet/mobile UI runtime.

## Authorization

Portal authorization follows `docs/pos_latest/BETA_AUTHORIZATION_STANDARD.md`.

Do not authorize new portal behavior with nominal `ADMIN`/`SUPER` checks alone. The authoritative pattern is:

```text
client role → LOA → named permission → UI/API/data scope
```

DEV-only permissions also require an actual DEV identity and cannot be delegated merely by raising another role to LOA 1000.

The backend is authoritative. Hiding a menu/button with JavaScript or CSS is not security.

The shared `api/beta_state.php` route is consumed by more than Dashboard. Its route authorization follows the consuming Dashboard/Disputes/Finance/Password feature, while the payload itself remains field/data scoped by the corresponding permissions. Broad route access must never become broad data access.

## UI standard

All user-facing beta features follow `docs/pos_latest/GUI_STANDARD.md`.

Current binding interaction rules include:

- Dashboard Add and Add Store / Employee / Client / Role use the same circular `+` runtime primitive;
- desktop Add/Search diameter is 46px; tablet/phone is 48px;
- Search and Add remain adjacent in one right-aligned cluster;
- mobile-ready means functional parity, not simply responsive dimensions;
- top bar remains a single stable row on mobile;
- bottom navigation and notices respect safe-area/fixed-nav offsets;
- contextual mobile subnavigation reserves workspace height only while that subnavigation is actually open; direct Home/Finance/DEV-style sections do not keep a phantom top gap;
- when the software keyboard opens, fixed bottom/context navigation yields so focused fields and dialog actions retain the visual viewport;
- forms collapse cleanly to one column;
- dialogs remain inside the visual viewport and their own content remains scrollable when the software keyboard is open;
- tables scroll within their own container instead of causing page-level horizontal scrolling;
- Dashboard widget drawer sits above all mobile chrome and has an explicit close route;
- editable Dashboard widgets keep add/remove/reorder capability on mobile; free drag/resize remains desktop-only;
- older browsers receive a minimal `<dialog>` fallback.

Canonical runtime layers are:

- `assets/design-tokens.css` — semantic color, spacing, type, radius, control and breakpoint tokens;
- `assets/design-system.css` — canonical shared visual grammar for controls, cards, buttons, tables, dialogs, Add/Search and accessibility states;
- `assets/shell.css` — application/navigation shell only;
- `assets/app-ui.css` — feature composition only, including directory, Clients, Roles, Permission Policy and Legacy Migration layouts;
- `assets/dashboard-builder.css` — Dashboard-specific composition only;
- `assets/account-menu.css` — account/client-context composition only;
- `assets/minimal-controls.js` — behavior-only normalization for circular Add, expandable Search and Search+Add clustering;
- `assets/mobile-runtime.js` — mobile viewport, keyboard-state detection, dialog compatibility, Dashboard mobile editing and structural runtime audit;
- `assets/navigation.js` — authoritative primary/contextual navigation state, including whether a mobile contextual subnav is open;
- `assets/design-audit.js` — runtime heading, accessibility, Search/Add geometry, touch-target, overflow and contrast checks;
- `assets/management.js` — runtime loading/wiring; dynamically inserted classic scripts are forced to execute in insertion order so dependency-sensitive modules such as Roles mount before Navigation; management cards/KPIs mirror their own underlying data permissions;
- `.htaccess` — cache revalidation for shared cross-portal UI contract assets.

Retired corrective styling layers must not be reloaded by the beta runtime: `ui-standard.css`, `minimal-controls.css`, `mobile-hardening.css`, `apple-principles.css` and `omnichannel-identity.css`.

Feature JavaScript may compose the design system but may not inject its own visual CSS. In particular, `client.js` and `roles.js` are behavior/data modules; their visual composition belongs to `app-ui.css`. The deploy/runtime validator enforces this boundary.

`window.MERDPOSMobileRuntime.audit()` provides a browser-side structural smoke test for mobile layouts. It checks page horizontal overflow, wrapped top bar, undersized touch targets, dialogs outside the viewport and malformed shared icon actions. `window.MERDPOSDesignAudit.run()` adds heading, accessible-name, Search/Add placement and contrast checks. Passing these audits is required for source/runtime confidence but does not replace real-device verification.

A rule written in Markdown but not loaded/called by the portal is **DOCUMENTED**, not implemented. Shared UI primitives must also be visually and functionally equivalent across representative screens.

## Recovery browser smoke coverage

`namecheap_beta_live/browser_tests/shared-runtime.spec.js` runs in real Google Chrome through a pinned Playwright test runner in the `Beta guardrails` workflow. It is intentionally credential-free and exercises shared browser behavior rather than production data.

Current incident-derived Chrome checks verify:

- the canonical Add SVG remains the same DOM node after repeated MutationObserver activity;
- exactly one Add action fires for one click after those mutations;
- Search and Add are normalized into the shared adjacent action cluster;
- `app.js` compatibility shims allow the legacy shared `beta.js` runtime to execute against a permission-minimal DOM without a page-level JavaScript crash.

These smoke tests are regression gates, not a substitute for authenticated USER/management/DEV tests or real mobile-device verification.

## Timesheet/payroll behavior — frozen

Do not change without explicit product-owner instruction:

- pair IN → next OUT;
- newer IN replaces an unmatched previous IN;
- orphan OUT ignored;
- round IN and OUT independently to nearest 15 minutes;
- payable = rounded OUT − rounded IN;
- cross-midnight allowed;
- no 16-hour cap;
- wage rate resolved by clock-in date.

## Google legacy migration

The client migration subsystem is DEV-only.

```text
Google Sheets → read-only fetch → staging → validation/reconciliation → MERDPOS SQL
```

Safety rules:

- Preview does not write operational attendance/finance data;
- importer does not edit source Google Sheets;
- Sync requires the exact source snapshot last Previewed;
- Sync is blocked if Preview has rejected rows or conflicts;
- operational application is transactional/all-or-nothing;
- imported historical finance must not echo through the SQL→Google outbox;
- final cutover makes MERDPOS SQL authoritative and prevents Google from overwriting SQL later.

Known workbooks must use deterministic mappings rather than generic header guessing. Current approved structures:

```text
Time Sheet:
USER_NAME / STORE_NAME / LOG_TYPE / DATE / TIME

Employee Setup:
NAME / TYPE / USER_ID / PASSWORD / STATUS / LOG_STORE / PAY_RATE
(the real header may occur below row 1)

PayRate:
NAME / PAY_RATE

Start Time:
Store Name / Shift Start Time

General Ledger:
DATE / STORE_NAME / ACCOUNT / TYPE / HEAD / AMOUNT / Key

zReport Ledger:
DATE / STORE_NAME / REGISTER_DENOMINATIONS / REGISTER_TOTAL / PETTYCASH_ADDIN
```

Plaintext legacy passwords/PINs/secrets must never be persisted to staging, logs or audit output.

## Data authority during transition

Historical Google data is being migrated into SQL, while new portal transactions are SQL-first. The existing outbound SQL→Google mirror is a separate compatibility/reporting path and must never create a circular Google→SQL→Google migration loop.

## Main runtime areas

- `index.php` — login;
- `dashboard.php` — authenticated portal shell/panels;
- `scan.php` — attendance confirmation;
- `api/` — authenticated portal APIs;
- `includes/beta_api.php` — live authorization refresh and fail-closed route policy;
- `includes/timesheet_logic.php` — frozen timesheet reconciliation;
- `includes/legacy_migration*.php` — legacy source fetch/staging/reconciliation;
- `assets/management.js` — shared runtime module loader/management behavior with deterministic dynamic-script execution order and permission-aware management composition;
- `assets/directory.js` — Store/Workforce admin behavior;
- `assets/roles.js` — Roles/Permission Policy behavior only;
- `assets/client.js` — Client and Legacy Migration behavior only;
- `assets/design-tokens.css` — canonical semantic tokens;
- `assets/design-system.css` — canonical shared component layer;
- `assets/app-ui.css` — feature composition, including Clients/Roles/Migration;
- `assets/shell.css` — desktop/mobile shell and navigation composition;
- `assets/navigation.js` — primary/contextual navigation state and mobile subnav visibility state;
- `assets/minimal-controls.js` — shared Add/Search behavior;
- `assets/mobile-runtime.js` — mobile runtime enhancement and self-audit;
- `assets/design-audit.js` — contextual design/accessibility runtime audit;
- `../browser_tests/shared-runtime.spec.js` — credential-free Chrome regression smoke tests for shared runtime incidents.

## Deployment

Do not manually upload portal files. Deployment authority is the Namecheap-side repository mirror using `scripts/deploy_namecheap_beta.sh`. A short-lived GitHub→Namecheap SSH workflow was intentionally retired in favor of Namecheap pulling `namecheap-beta-live`; do not restore that workflow without an explicit deployment-architecture decision.

The normal immediate command after beta code changes is:

```bash
cd ~/git/MerdPOSDev-beta-mirror

GIT_SSH_COMMAND="ssh -i $HOME/.ssh/merdpos_github -o IdentitiesOnly=yes -o BatchMode=yes" \
git pull --ff-only origin namecheap-beta-live

/bin/bash scripts/deploy_namecheap_beta.sh

echo "=== DEPLOYED ==="
cat ~/merdpos.com/app/beta/.beta_deployed_commit
```

Do not say a change is `live`, `fixed` or `working` based only on a GitHub commit. Confirm the deployed marker and the real runtime path.

## Implementation status discipline

```text
REQUESTED → DOCUMENTED → CODED → WIRED → DEPLOYED → VERIFIED
```

`Implemented in beta source` means at least **CODED + WIRED**.  
`Live/fixed` means **DEPLOYED + VERIFIED**.

Mobile verification requires both structural runtime checks and real-device testing of the affected workflows.

## README maintenance

Update this README by default whenever portal behavior, authorization, UI conventions, data-flow, synchronization, mobile behavior or migration behavior changes. README maintenance is part of beta Definition of Done, but documentation itself must never be mistaken for runtime implementation.
