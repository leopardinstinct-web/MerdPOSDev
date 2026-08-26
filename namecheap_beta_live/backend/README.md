# MERDPOS Namecheap Beta Backend

This folder is the backend component of the authoritative `namecheap-beta-live` runtime.

Read `../README.md` first. It defines the beta implementation-state language, deployment contract and README-maintenance rule.

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

## Legacy migration subsystem

Migration 034 provides the client-scoped Google legacy migration staging/audit model.

Import direction is Google → staging → validation → SQL. The migration importer does not edit source Google Sheets. Imported historical finance must not be re-emitted through the existing outbound Google outbox.

Known Sheet schemas must be parsed deterministically; do not introduce generic header guessing for the known MERDPOS legacy workbooks.

## Release checks

The Namecheap deployment script is expected to fail closed on at least:

- PHP syntax errors;
- missing permission-policy coverage;
- migration/schema verification failures;
- portal HTML/API response-boundary invariants;
- other explicitly registered release invariants.

Do not describe source as `live` until `.beta_deployed_commit` confirms the intended commit and the relevant runtime path has been verified.

## README maintenance

Update this file by default when backend architecture, migrations, deployment invariants, security boundaries or synchronization behavior changes. Documentation-only text is not implementation; runtime wiring must be verified separately.
