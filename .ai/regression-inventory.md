# MERDPOS Beta Regression Inventory

**Started:** 2026-08-27

This inventory tracks recovery by execution path. Source inspection does not equal live verification.

| Path | Current source owner | Source status | Runtime status / next check |
|---|---|---|---|
| Login/session bootstrap | `timesheet_portal/index.php`, `api/login.php`, auth/session includes | Canonical login page and API path present | DEPLOYED/VERIFIED unknown; test valid/invalid login and pending QR redirect on beta |
| Dashboard load | `dashboard.php`, `assets/beta.js`, `assets/management.js` | Permission-aware panel rendering; management loader active; dynamic script execution is now deterministic | Verify representative USER/management/DEV identities and no console aborts |
| Shared LOA/permission runtime | `includes/beta_api.php`, `api/beta_state.php`, permission catalogue, `assets/app.js`, `assets/beta.js` | LOA DOM compatibility protected; shared state route now follows consuming feature permission and payload data is permission-scoped | Verify a role with Dashboard denied but Finance or Password allowed can still use the allowed feature without receiving unrelated state data |
| Dashboard widgets | `assets/dashboard-builder.js`, dashboard APIs | Source contract present; shared loader ordering fixed | Verify add/remove/reorder desktop and mobile |
| Mobile navigation | `assets/navigation.js`, `assets/shell.css`, `assets/mobile-runtime.js` | Contextual submenu state and mobile audit hooks present | Verify parent-group open behavior, item selection, outside close, safe-area and keyboard behavior on phone |
| Shared Add/Search | `assets/minimal-controls.js`, `assets/design-system.css` | Latest Add MutationObserver loop fix inspected; source validator checks shared primitives | Verify Dashboard Add + one directory Add/Search pair on desktop and phone |
| Stores directory | `dashboard.php`, `assets/directory.js`, Stores API routes | Source path present | Verify list/search/add/edit/inactivate with role boundary and mobile dialog reachability |
| Workforce directory | `dashboard.php`, `assets/directory.js`, Workforce API routes | Source path present | Verify list/search/add/edit, store access, role/LOA controls and pay-rate visibility boundaries |
| Clients | `assets/navigation.js`, `assets/client.js`, client APIs | DEV-only dynamic mount path present | Verify DEV-only visibility, active client switch/return-panel behavior and non-DEV absence |
| Roles / Permission Policy | `assets/roles.js`, `api/role_authority.php`, `assets/management.js` | Dynamic role/policy module present; Roles now executes before Navigation deterministically and CI guards the ordering | Verify policy save immediately changes UI/API scope and DEV-only remains locked |
| Timesheet report | `assets/app.js`, `assets/timesheet-app.js`, `api/weeks.php`, `api/timesheet.php` | Isolated runtime split; load order/cache wiring now validated | Verify current week, week change, report rendering, PDF print route and logout for own/all-timesheet roles |
| Disputes / attendance flags | `assets/beta.js`, `api/beta_state.php`, `api/disputes.php` | Shared state now omits disputes/flags unless corresponding view/resolve permission exists | Verify own submit/cancel, SUPER review, handover confirmation and flag resolution permission boundaries |
| Finance | `assets/beta.js`, `api/beta_state.php`, `api/financials.php` | Finance no longer depends on `dashboard.view` merely to obtain shared CSRF/store context; Finance-only store scope is constrained to active clock-in stores unless cross-store permission exists | Verify locked/unlocked store access, open day, queue, cash in/out, close day and offline queue recovery |
| Dialogs + software keyboard | shared dialogs, `assets/modal-lock.js`, `assets/mobile-runtime.js` | Mobile-safe locking/fallback is a release invariant | Verify active input/action remains reachable with iOS/Android software keyboard |
| Legacy Google migration | migration orchestrator/known reader + migration APIs | Deterministic known-sheet contract and fail-closed source checks present | DEV-only runtime verification; Preview must remain non-operational and Sync transactional |

## Incident-derived regression guards

### LOA permission runtime crash — 2026-08-26

Protected by `backend/cli/validate_portal_permission_policy.php`:

- legacy direct DOM references that still exist in `beta.js` must retain compatibility shims in `app.js`;
- Timesheet runtime remains split into `timesheet-app.js`;
- `dashboard.php` loads `app.js` before `beta.js`;
- `app.js` / `timesheet-app.js` remain cache-revalidated.

### Shared Add MutationObserver loop — 2026-08-26

Current `minimal-controls.js` only replaces the Add button markup when the canonical SVG is not already stable. The next browser-smoke layer should explicitly click/tap the shared Add primitive after MutationObserver activity and ensure exactly one action fires.

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

## Remaining source-level focus

The next source audit is the management Dashboard composition under deliberately unusual client permission thresholds. The goal is to distinguish harmless empty cards from controls/cards that imply access to data the backend correctly withholds. Any change must preserve server-side permission authority rather than hiding data only in the UI.
