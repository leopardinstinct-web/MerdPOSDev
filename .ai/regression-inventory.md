# MERDPOS Beta Regression Inventory

**Started:** 2026-08-27

This inventory tracks recovery by execution path. Source inspection does not equal live verification.

| Path | Current source owner | Source status | Runtime status / next check |
|---|---|---|---|
| Login/session bootstrap | `timesheet_portal/index.php`, `api/login.php`, auth/session includes | Canonical login page and API path present | DEPLOYED/VERIFIED unknown; test valid/invalid login and pending QR redirect on beta |
| Dashboard load | `dashboard.php`, `assets/beta.js`, `assets/management.js` | Permission-aware panel rendering; management loader active | Verify representative USER/management/DEV identities and no console aborts |
| Shared LOA/permission runtime | `includes/beta_api.php`, permission catalogue, `assets/app.js`, `assets/beta.js` | 2026-08-26 compatibility hotfix now protected by deploy-time validator | Verify a role that lacks Dashboard/Finance/Password capabilities can still use its allowed panels without JS crash |
| Dashboard widgets | `assets/dashboard-builder.js`, dashboard APIs | Source contract present | Verify add/remove/reorder desktop and mobile; loader execution order still being audited |
| Mobile navigation | `assets/navigation.js`, `assets/shell.css`, `assets/mobile-runtime.js` | Contextual submenu state and mobile audit hooks present | Verify parent-group open behavior, item selection, outside close, safe-area and keyboard behavior on phone |
| Shared Add/Search | `assets/minimal-controls.js`, `assets/design-system.css` | Latest Add MutationObserver loop fix inspected; source validator checks shared primitives | Verify Dashboard Add + one directory Add/Search pair on desktop and phone |
| Stores directory | `dashboard.php`, `assets/directory.js`, Stores API routes | Source path present | Verify list/search/add/edit/inactivate with role boundary and mobile dialog reachability |
| Workforce directory | `dashboard.php`, `assets/directory.js`, Workforce API routes | Source path present | Verify list/search/add/edit, store access, role/LOA controls and pay-rate visibility boundaries |
| Clients | `assets/navigation.js`, `assets/client.js`, client APIs | DEV-only dynamic mount path present | Verify DEV-only visibility, active client switch/return-panel behavior and non-DEV absence |
| Roles / Permission Policy | `assets/roles.js`, `api/role_authority.php` | Dynamic role/policy module present; retries mount against pre/post-navigation DOM | Verify tab mount is deterministic, policy save immediately changes UI/API scope, DEV-only remains locked |
| Timesheet report | `assets/app.js`, `assets/timesheet-app.js`, `api/weeks.php`, `api/timesheet.php` | Isolated runtime split; load order/cache wiring now validated | Verify current week, week change, report rendering, PDF print route and logout for own/all-timesheet roles |
| Disputes / attendance flags | `assets/beta.js`, `api/disputes.php` | Legacy shared bindings preserved through permission shims where needed | Verify own submit/cancel, SUPER review, handover confirmation and flag resolution permission boundaries |
| Finance | `assets/beta.js`, `api/financials.php` | Legacy finance runtime guarded against missing permission-gated DOM | Verify locked/unlocked store access, open day, queue, cash in/out, close day and offline queue recovery |
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

## Open source-level risk

`management.js` dynamically inserts feature/runtime scripts. Its comment requires Roles to mount before navigation, but the insertion helper does not currently make that execution order explicit. `roles.js` contains retry/fallback behavior for both pre-navigation and post-navigation DOMs, so this is not yet classified as a confirmed runtime defect. Resolve only after deterministic reproduction or a loader-order test.
