# MERDPOS Namecheap Beta Backend

This folder is the backend component of the authoritative `namecheap-beta-live` runtime.

Read `../README.md` first. It defines the beta-only project scope, implementation-state language, deployment contract and README-maintenance rule.

## Responsibilities

- SQL migrations and migration runners;
- shared workforce/finance/device helpers;
- deployment/runtime validators;
- sanitized schema export tooling;
- device/POS API support that remains separate from browser-portal LOA authorization unless explicitly integrated.

## Migration rule

DB-dependent beta code must never be copied live before its required migration has successfully run and its required table/column/index/FK state has been verified.

Every migration requires:

1. versioned SQL;
2. explicit CLI runner;
3. deploy-script wiring in migration order;
4. idempotent verification;
5. failure before portal publish if schema requirements are not met;
6. DEV/schema diagnostics updated when appropriate;
7. relevant README/project context updated.

## Browser portal authorization

Browser portal permissions are defined by `docs/pos_latest/BETA_AUTHORIZATION_STANDARD.md` and enforced through named permissions/LOA. Device/POS APIs retain their own device-token/client/store/employee security contract unless a feature explicitly adds a named role/LOA requirement without weakening the device boundary.

`backend/cli/validate_portal_permission_policy.php` is a deploy-time fail-closed guard. It validates the central permission catalogue, dashboard permission mappings, protected portal API registration and refresh of active authorization state.

It also protects the permission-aware browser runtime split introduced after the 2026-08-26 LOA crash incident. `dashboard.php` intentionally omits controls a role cannot use, while the legacy shared `beta.js` still contains direct DOM lookups. The validator therefore requires:

- `assets/app.js` to provide compatibility shims for legacy permission-gated IDs that `beta.js` still directly references;
- `assets/app.js` to load the isolated `assets/timesheet-app.js` only when the Timesheet DOM contract exists;
- the Timesheet runtime double-load guard and its weeks/report API wiring;
- `dashboard.php` to load `app.js` before `beta.js`;
- portal cache revalidation to include `app.js` and `timesheet-app.js`.

If the legacy shared runtime is later refactored so these shims are no longer needed, update the validator and runtime together rather than weakening the guard first.

Beta CI **and** `scripts/deploy_namecheap_beta.sh` run the two incident-derived recovery guards before portal publication:

- `backend/cli/validate_portal_loader_order.php` requires dynamically inserted classic scripts to execute in insertion order and keeps Roles ahead of Navigation;
- `backend/cli/validate_beta_state_scope.php` requires the shared `beta_state.php` route to be authorized by its consuming features while keeping shifts, disputes, working-now data, stores and management data permission-scoped inside the payload.

The shared state endpoint must never make `dashboard.view` an accidental prerequisite for otherwise-authorized Finance, Disputes or Password flows, and broad route access must never become broad data access.

## Legacy migration subsystem

Migration 034 provides the client-scoped Google legacy migration staging/audit model.

Import direction is Google → staging → validation → SQL. The migration importer does not edit source Google Sheets. Imported historical finance must not be re-emitted through the existing outbound Google outbox.

Known Sheet schemas must be parsed deterministically; do not introduce generic header guessing for the known MERDPOS legacy workbooks.

## Release checks

The Namecheap deployment script is expected to fail closed on at least:

- PHP syntax errors;
- missing canonical MERDPOS brand registry/assets (`brand-assets.js`, complete lockup, M mark, wordmark or tagline);
- missing permission-policy/API coverage;
- permission-gated browser runtime wiring regressions;
- nondeterministic dependency-sensitive portal script loading;
- shared-state route/data-scope regressions;
- migration/schema verification failures;
- portal HTML/API response-boundary invariants;
- beta runtime-contract violations;
- missing canonical shared UI/mobile files or loader wiring.

`backend/cli/validate_beta_runtime_contract.php` verifies that documented beta contracts are wired into current source. Current shared UI/runtime checks cover:

- semantic design tokens and canonical component ownership;
- shared Search/Add primitives and placement;
- authoritative mobile navigation/subnavigation state;
- visual-viewport/software-keyboard behavior;
- dialog fallback and Dashboard mobile reorder parity;
- heading/accessibility/contrast/touch-target/overflow auditing through `assets/design-audit.js`;
- cache revalidation of canonical runtime assets;
- deterministic legacy Google Sheet reader contracts;
- absence of retired competing CSS layers.

The retired corrective CSS layers must not be restored to runtime ownership: `ui-standard.css`, `minimal-controls.css`, `mobile-hardening.css`, `apple-principles.css` and `omnichannel-identity.css`.

The deploy script also verifies required files/wiring in the **live Namecheap copy after rsync** before writing `.beta_deployed_commit`.

Runtime validators are release guards, not substitutes for real-device verification. A mobile UI change is VERIFIED only after the intended workflow is checked on a phone/tablet as relevant and no unresolved structural audit issue remains.

Do not describe source as `live` until `.beta_deployed_commit` confirms the intended commit and the relevant runtime path has been verified.

## README maintenance

Update this file by default when backend architecture, migrations, deployment invariants, security boundaries, mobile release gates or synchronization behavior changes. Documentation-only text is not implementation; runtime wiring must be verified separately.


## UI Studio global history

Migration 035 introduces `ui_studio_state` and `ui_studio_history` for client-scoped Developer preview history. The dedicated portal API is actual-DEV-only, CSRF-protected for writes, revision-checked for concurrent sessions, and never grants operational business permissions. History deletion soft-deletes the selected event and rebuilds preview patches by replaying the surviving journal. The deploy must run migration 035 and verify both tables before publishing the portal runtime.


- Migration 036 adds `stores.week_start_day` (1=Monday ... 7=Sunday) as store configuration. It controls Store Edit schedule ordering only and does not modify frozen timesheet/payroll pairing, rounding, payable-hours, cross-midnight, or wage-by-clock-in-date rules.
