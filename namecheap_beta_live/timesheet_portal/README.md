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
- `assets/ui-studio.js` + `assets/ui-studio.css` — actual-DEV-only local visual preview/change-set tooling; see `UI_STUDIO.md`;
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
## Product brand asset library

The product identity is stored as exact supplied artwork under `assets/brand/`. Runtime code uses `assets/brand/brand-assets.js` as the canonical registry for the complete lockup, standalone M mark, MERDPOS wordmark and SMARTER • FASTER • TOGETHER tagline. Use the complete lockup file whenever the complete logo is required; individual elements exist for context-specific use and must not be recomposed into a substitute full logo.

### Omnichannel status pills (2026-08-30)
- Dashboard rolebar status uses one reusable pill grammar: LED, identity/context name, and latest meaningful action.
- Current User is self-only: actual authenticated employee, portal-session status, and only that employee's own open shop shift.
- DEV Current Preview Role is presentation context only; changing Current role records a local switch timestamp and never changes actual DEV identity.
- Client freshness, Current User, and Current Preview Role share the same status-pill primitive and remain visible on mobile without horizontal overflow.


### About release metadata
The About MERDPOS splash is deployment-aware. `scripts/deploy_namecheap_beta.sh` writes `.beta_release.json` beside the beta live tree after runtime validation. `dashboard.php` reads that server-side file to show the deployed MERDPOS Git reference/date, the latest DevStudio Git reference/date, and the three most recent release commit subjects. Do not replace these values with hand-maintained version strings.


### DevStudio Developer master + preview requests (2026-08-31)
- The visual-template chain is Developer → Admin → Super → User. Developer patches are the master and are inherited by every lower preview; lower roles can further specialize inherited elements but cannot introduce production elements absent from the Developer master.
- Actual DEV may still design in an Admin/Super/User preview. Add or Comment/Describe there is stored as a globally synchronized proposal request anchored to that preview and explicitly marked to begin implementation from Developer.
- Studio proposal placeholders are editing artifacts only. They do not grant permissions, create lower-role production DOM, or bypass the existing role/LOA/permission model.
- Studio JSON v4 carries canonical role targets plus proposal metadata; migration 035 global history remains the persistence mechanism.


### DevStudio27 context capture and inheritance reversal (2026-08-31)
- Developer remains the visual master. Undoing a Developer master patch removes the inherited effect from Admin/Super/User as well; an intentional lower-role override survives only when it is explicitly owned by that lower role.
- Studio comments/requests use a multiline composer and can attach up to six PNG/JPEG/WebP/GIF/SVG images. Context upload is actual-DEV-only and CSRF-protected; SVG is sanitized.
- Uploaded Studio context is stored under private backend runtime storage. A random-token, read-only `studio_context_asset.php` URL is copied into Studio JSON/Chat handoff so ChatGPT can fetch the image without MERDPOS authentication material.
- Studio JSON is version 5. Same browser-profile windows synchronize Studio accent/font/icon/radial appearance through localStorage events; this does not make device-local settings server-global.


### DevStudio implementation patches
Implementation requests authored in DevStudio are translated into canonical source. Completion requires regression tests, deployment, live verification, and then programmatic removal of only the matching active Studio patches. Studio history is retained as the audit trail.

### Shared analytics dashboard runtime (2026-08-31)
- Dashboard visualization uses `assets/analytics-runtime.js` + `assets/analytics-runtime.css`; do not add a second chart framework for ordinary MERDPOS dashboard/report widgets.
- Analytics follows a typed `dataset → view → renderer` contract. Data/API authorization stays separate from chart presentation.
- Shared renderers are responsive SVG bar, line and donut charts with keyboard/click selection and a bubbling `merdpos-chart-select` event for drill-down.
- Dashboard Builder coordinates Store and 7/14/30-day filters through `api/dashboard_data.php`; Store drill-down reloads all applicable widgets from the server rather than filtering privileged data only in the browser.
- Store filter options are scope-safe: management-capable dashboard dependencies may expose active client stores, while own-attendance-only access may expose only stores present in that employee's permitted attendance history.
- The analytics runtime consumes MERDPOS semantic/chart tokens and preserves desktop/tablet/mobile functional parity.
