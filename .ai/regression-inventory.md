# MERDPOS Beta Regression Inventory

**Started:** 2026-08-27

This inventory tracks recovery by execution path. Source/CI inspection does not equal live Namecheap verification.

| Path | Current source owner | Source/CI status | Runtime status / next check |
|---|---|---|---|
| Login/session bootstrap | `timesheet_portal/index.php`, `api/login.php`, auth/session includes | Canonical login page and API path present | DEPLOYED/VERIFIED unknown; test valid/invalid login and pending QR redirect on beta |
| Dashboard load | `dashboard.php`, `assets/beta.js`, `assets/management.js` | Permission-aware panel rendering; dynamic script execution deterministic; management cards mirror data permissions | Verify representative USER/management/DEV identities and no console aborts |
| Shared LOA/permission runtime | `includes/beta_api.php`, `api/beta_state.php`, permission catalogue, `assets/app.js`, `assets/beta.js` | Shared-state route follows consuming feature; payload data permission-scoped; Chrome proves permission-minimal legacy runtime does not page-crash | Verify authenticated role with Dashboard denied but Finance or Password allowed against deployed beta |
| Dashboard widgets | `assets/dashboard-builder.js`, dashboard APIs | Source contract present; shared loader ordering fixed | Verify add/remove/reorder desktop and mobile |
| Mobile navigation | `assets/navigation.js`, `assets/shell.css`, `assets/mobile-runtime.js`, `browser_tests/navigation-runtime.spec.js` | Chrome at 390×844 proves contextual parent opens without navigation, contextual child changes panel, and direct Finance clears `merd-mobile-subnav-open` | Still verify outside-close, safe-area and keyboard behavior on a real phone/tablet |
| Shared Add/Search | `assets/minimal-controls.js`, `assets/design-system.css`, `browser_tests/shared-runtime.spec.js` | Chrome proves Add SVG node stability after repeated MutationObserver activity, Search+Add clustering, and exactly one click action | Still verify Dashboard Add + directory Add/Search geometry/touch behavior on desktop and phone |
| Stores directory | `dashboard.php`, `assets/directory.js`, Stores API routes | Source path present | Verify list/search/add/edit/inactivate with role boundary and mobile dialog reachability |
| Workforce directory | `dashboard.php`, `assets/directory.js`, Workforce API routes | Source path present | Verify list/search/add/edit, store access, role/LOA controls and pay-rate visibility boundaries |
| Clients | `assets/navigation.js`, `assets/client.js`, client APIs | DEV-only dynamic mount path present | Verify DEV-only visibility, active client switch/return-panel behavior and non-DEV absence |
| Roles / Permission Policy | `assets/roles.js`, `api/role_authority.php`, `assets/management.js` | Roles execute before Navigation deterministically and CI guards ordering | Verify policy save immediately changes UI/API scope and DEV-only remains locked |
| Timesheet report | `assets/app.js`, `assets/timesheet-app.js`, `api/weeks.php`, `api/timesheet.php`, Chrome smoke | `app.js` prevents duplicate Timesheet runtime injection; Chrome proves one script load, one initial weeks/report load and one report request per week selection | Verify full authenticated report data, PDF print route and logout for own/all-timesheet roles |
| Disputes / attendance flags | `assets/beta.js`, `api/beta_state.php`, `api/disputes.php` | Shared state omits disputes/flags unless corresponding view/resolve permission exists | Verify own submit/cancel, review, handover confirmation and flag resolution permission boundaries |
| Finance | `assets/beta.js`, `api/beta_state.php`, `api/financials.php`, workforce finance helpers | Finance no longer depends on Dashboard for shared CSRF/store context; Finance-only state store scope is active clock-in stores; backend independently enforces active clock-in unless `finance.cross_store` | Verify locked/unlocked store access, open day, queue, cash in/out, close day and offline queue recovery |
| Dialogs + software keyboard | shared dialogs, `assets/modal-lock.js`, `assets/mobile-runtime.js` | Mobile-safe locking/fallback is a release invariant | Verify active input/action remains reachable with iOS/Android software keyboard |
| Legacy Google migration | migration orchestrator/known reader + migration APIs | Deterministic known-sheet contract and fail-closed source checks present | DEV-only runtime verification; Preview must remain non-operational and Sync transactional |

## Incident-derived regression guards

### LOA permission runtime crash — 2026-08-26

Protected by `backend/cli/validate_portal_permission_policy.php` and Chrome smoke coverage:

- legacy direct DOM references that still exist in `beta.js` must retain compatibility shims in `app.js`;
- Timesheet runtime remains split into `timesheet-app.js`;
- `dashboard.php` loads `app.js` before `beta.js`;
- `app.js` / `timesheet-app.js` remain cache-revalidated;
- a permission-minimal DOM can execute `app.js → beta.js` without a browser `pageerror`.

### Shared Add MutationObserver loop — 2026-08-26

Protected in real Chrome by `browser_tests/shared-runtime.spec.js`:

- the canonical Add SVG remains the same DOM node after repeated unrelated DOM mutations;
- only one canonical Add SVG remains;
- Search + Add retain the shared action cluster;
- one click fires exactly one Add action.

### Dynamic Roles → Navigation loader race — 2026-08-27

Resolved in source by forcing dynamically inserted classic scripts in `assets/management.js` to `async=false`, preserving insertion/execution order. `backend/cli/validate_portal_loader_order.php` guards both the ordered-script behavior and Roles-before-Navigation dependency in beta CI.

### Shared state accidentally required Dashboard — 2026-08-27

Resolved in source across `includes/beta_api.php` and `api/beta_state.php`:

- `beta_state.php` route access follows the consuming Dashboard, Disputes, Finance or Password feature rather than only `dashboard.view`;
- recent shifts are loaded only for Timesheet/own-dispute consumers;
- dispute rows are returned only with dispute-view/review permission;
- working-now data is limited to Workforce, own-Timesheet or Finance consumers and remains self-only without Workforce access;
- Finance-only users do not receive a full store enumeration unless their permission set grants broader scope;
- management summaries remain separately permission-gated.

`backend/cli/validate_beta_state_scope.php` protects these boundaries in beta CI.

### Management cards implied unavailable data — 2026-08-27

Resolved in `assets/management.js`. Workforce, Finance, Dispute, Sync and Recent Attendance dashboard surfaces now mirror the permissions of the data they display instead of showing misleading zero/empty cards for a management identity that lacks that specific capability. The shared-state validator also protects this frontend/backend alignment.

### Duplicate Timesheet runtime injection — 2026-08-27

`timesheet-app.js` owns one report runtime. `app.js` now refuses to inject another Timesheet script when the first script is already pending or initialized. The Chrome recovery suite evaluates `app.js` twice and requires exactly one Timesheet script request, one initial weeks request, one initial report request and exactly one additional report request for a user week selection.

### Mobile navigation smoke fixture — 2026-08-27

The first navigation browser run failed before runtime validation because `page.setContent()` created an opaque `about:blank` document and Chromium denied `sessionStorage`. The fixture now runs on a normal HTTPS origin. With that corrected, Chrome verifies contextual parent open behavior, contextual child navigation and direct-section clearing of mobile subnav state without page-level JavaScript errors.

## Current CI checkpoint

Beta guardrails run `33009891345` on commit `5daca6c51afde3bca8130338085ff6cf5297f88c` completed green with all three jobs:

- Beta source contract;
- Beta Chromium smoke, including Add/permission-minimal/Timesheet/mobile-navigation regression scenarios;
- Beta secret scan.

This is not a Namecheap deployment claim.

## Remaining recovery focus

1. Deploy the current branch through the established Namecheap pull/mirror path and confirm `.beta_deployed_commit`.
2. Run authenticated role verification for USER, management and DEV, especially custom thresholds where Dashboard is denied but Finance/Password/Disputes remain allowed.
3. Verify Dashboard widget editing and Store/Workforce/Role/Client feature workflows on deployed beta.
4. Perform real phone/tablet checks for navigation, dialogs/keyboard, Dashboard edit controls and shared Add/Search touch behavior.
