# FILE MANIFEST — POS LATEST / MerdPOS

Reconciled after documentation merge commit `ae873f8` on 2026-08-05.

## Documentation authority

`docs/pos_latest/` is authoritative project guidance. The similarly named
files under `markdown/pos_project_md_sources_updated_2026-06-14/` and its ZIP
archive are historical snapshots and must not guide new implementation.

Core guidance:

- `AGENT_AUTONOMY_CHARTER.md` — authority and approval levels.
- `PROJECT_CONTEXT.md` — current implemented architecture and state.
- `APP_REQUIREMENTS.md` — binding functional/non-functional requirements.
- `API_CONTRACT.md` — current endpoint inventory and known contracts.
- `SECURITY.md` — binding security gates.
- `DESIGN_TOKENS.md` and `merdpos-design-brief-blue-ice.md` — UI authority.
- `PRODUCT_ROADMAP_DRAFT.md` — proposed non-Timesheet product milestones.
- `M2_CATALOGUE_DECISIONS.md` — approved canonical catalogue, price, tax,
  stock, lifecycle, and synchronization policy.
- `IMPLEMENTATION_STATUS.md` — source-based roadmap status.
- `CI_ENVIRONMENT_PLAN.md` — non-production build/test design.
- `LEGACY_DOCUMENTATION_DEPRECATION.md` — authority notice for old packs.
- `CHANGELOG.md`, `BUGS_AND_FIXES.md` — history and active defects.

## Flutter application

Milestone 2B adds `services/device_token_store.dart` and
`test/secure_token_storage_test.dart`, updates setup/API/session persistence for
bearer transport and verified legacy migration, and disables Android backup for
device-bound token safety.

Entry and shared configuration:

- `merdpos_staff/lib/main.dart`
- `merdpos_staff/lib/theme.dart`
- `merdpos_staff/lib/utils/helpers.dart`

Models:

- `models/app_session.dart`
- `models/employee.dart`
- `models/retail_product.dart`
- `models/retail_sale.dart`
- `models/timesheet_row.dart` — preserve; outside new roadmap.

Services:

- `services/api.dart`
- `services/auth_service.dart`
- `services/employee_service.dart`
- `services/primary_login_store.dart`
- `services/retail_db.dart`
- `services/timesheet_parser.dart` — preserve.

Screens/dialogs/widgets:

- setup, login, home, POS, orders, inventory, financials;
- change-password and secondary-login dialogs;
- side rail and shared widgets;
- existing Timesheet screen/table — preserve.

Storage note: `services/retail_db.dart` is the active retail store.
`lib/db/local_db.dart` defines employee-log SQLite storage but is not included
as a `part` by current `main.dart`; treat it as disconnected until a scoped
decision is made.

Android/build files:

- `merdpos_staff/android/app/build.gradle.kts`
- `merdpos_staff/android/app/src/main/AndroidManifest.xml`
- `merdpos_staff/android/app/src/main/kotlin/.../MainActivity.kt`
- `merdpos_staff/pubspec.yaml`, `pubspec.lock`, `analysis_options.yaml`

There is no `merdpos_staff/test/`, Android `gradlew`, or committed release
signing configuration at the merged baseline. Milestone 1 adds:

- `merdpos_staff/test/baseline_models_test.dart`
- `merdpos_staff/test/timesheet_parser_regression_test.dart`
- `merdpos_staff/android/gradlew`
- `merdpos_staff/android/gradlew.bat`
- `merdpos_staff/android/gradle/wrapper/gradle-wrapper.jar`

The Timesheet test is preservation-only and does not change application logic.
The wrapper files were restored from the reviewed clean Flutter 3.44.2 CI
artifact and match the existing Gradle distribution property.

## GitHub automation

- `.github/workflows/ci.yml` — hygiene, PHP 8.2 lint, Flutter 3.44.2
  format/analyze/tests, and debug APK build with seven-day retention.
- `.github/workflows/secret-scan.yml` — redacted Gitleaks change scanning and
  optional manually requested full-history scan.
- `.github/CODEOWNERS` — review ownership for CI/security-sensitive paths.

No release-signing workflow, keystore, deployment workflow, or production
credential is part of Milestone 1.

## Milestone 2A.1 security foundation

- `backend/sql/012_employee_auth_attempts.sql` — durable device/action counters,
  rolling events, and employee-wide locks.
- `backend/sql/013_activation_grants.sql` — hashed ten-minute single-use grants.
- `backend/sql/014_device_token_security.sql` — checked additive token hash,
  expiry, rotation, and revocation metadata.
- `backend/sql/015_security_audit_events.sql` — redacted security events.
- `backend/api/includes/` — shared response, request, device authorization,
  lockout, logging, and deny-by-default maintenance helpers.
- `backend/tests/` — deterministic foundation tests and a synthetic schema.

No existing endpoint or Flutter file is changed by 2A.1.

## Milestone 2A.2 activation and authentication integration

- `backend/api/request_activation_grant.php` — POST-only setup validation,
  eligible-store discovery, and short-lived grant issuance.
- `backend/api/activate_device.php` — grant-required hash-only token issuance.
- `backend/api/login.php`, `change_password.php` — shared device authorization
  and fail-closed layered lockout integration.
- `backend/api/includes/employee_auth.php` — shared hashed/legacy employee
  secret verification.
- `backend/tests/endpoint_integration_test.php` — deterministic endpoint-policy
  regression coverage.

## Backend API

Milestone 2A.3 hardens `get_employees.php`, `get_working_now.php`,
`sync_employee_logs.php`, `sync_shifts.php`, and `sync_retail.php` through the
shared device-auth helper. Import/init/test/debug routes use the deny-first
maintenance guard. `backend/tests/endpoint_hardening_test.php` protects these
boundaries.

Application endpoints:

- `request_activation_grant.php`, `activate_device.php`, `get_stores.php`,
  `get_employees.php`
- `login.php`, `change_password.php`
- `sync_retail.php`
- `get_working_now.php`
- `index.php`, `version_check.php`

Existing employee-log/shift/Timesheet endpoints are inventoried in
`API_CONTRACT.md` but excluded from new Timesheet development.

Utility/schema endpoints requiring deployment review:

- `cors_test.php`, `test_activate.php`
- `init_db.php`, `init_employee_logs.php`
- `import_actual_employees.php`, `import_timesheet_logs.php`

Sensitive local-only files:

- `backend/api/config.php` — ignored; never print or commit.
- `backend/api/.deployed_version` — ignored deployment marker.
- `.env`, `*.local.php` — never commit.

`backend/api/config.sample.php` is tracked with safe empty placeholders. The
previous real-looking value is treated as historically exposed and already
rotated. Do not reproduce or test it. Git-history cleanup is deferred.

## Admin and schema

- `backend/admin/` — Admin v1 application, assets, includes, and page routes.
- `backend/sql/010_retail_platform.sql` — retail sales and movements.
- `backend/sql/011_admin_platform.sql` — categories, products, inventory,
  suppliers, purchase orders, and audit records.
- `backend/sql/016_catalogue_identity_lifecycle.sql` — preconditioned M2.1
  migration draft for normalized SKUs, barcode aliases, lifecycle metadata,
  and historical-reference protection; never application-executed.
- `backend/tests/fixtures/catalogue_identity_schema.sql` — synthetic tracked-
  schema fixture with explicit identity and historical-reference examples.
- `backend/tests/catalogue_identity_migration_test.php` and
  `backend/tests/run_catalogue_identity.php` — local-only MariaDB migration and
  rejection tests.
- `backend/sql/017_effective_pricing_tax.sql` — preconditioned shadow-schema
  draft for currency, product units, effective prices, tax codes/rates/
  assignments, overlap triggers, and nullable future sale snapshots.
- `backend/tests/fixtures/effective_pricing_tax_schema.sql` — synthetic clients,
  stores, legacy price/tax values, and completed-sale compatibility data.
- `backend/tests/effective_pricing_tax_migration_test.php` and
  `backend/tests/run_effective_pricing_tax.php` — isolated M2.2 migration,
  trigger, precision, tenant, and preservation tests.
- `backend/sql/018_stock_ledger_balance.sql` — preconditioned M2.3 shadow
  ledger, maintained balances, idempotency, transfers, negative exceptions,
  reconciliation boundary, and immutable movement triggers.
- `backend/tests/stock_ledger_balance_migration_test.php` and
  `backend/tests/run_stock_ledger_balance.php` — local-only synthetic M2.3
  migration, behavior, preservation, tenant, and precondition tests.
- `backend/api/sync_catalogue.php` and
  `backend/api/includes/catalogue_snapshot.php` — device-authorized M2.4 full
  catalogue endpoint and deterministic read/serialization service.
- `backend/tests/catalogue_snapshot_integration_test.php` and
  `backend/tests/run_catalogue_snapshot.php` — synthetic M2.4 authorization,
  tenant/store, resolution, lifecycle, serialization, stock, and schema tests.
- `M2_4_CATALOGUE_SNAPSHOT_SCHEMA.md` — exact request/response, resolution,
  ordering, serialization, revision, and compatibility contract.
- `backend/sql/019_incremental_catalogue_sync.sql` and incremental catalogue
  endpoint/tests — M2.6 opaque-cursor paging, retention, replay, and full-resync
  fallback foundation.
- `M2_5_FLUTTER_CATALOGUE_SYNC_DECISIONS.md`,
  `M2_6_INCREMENTAL_CATALOGUE_SYNC_DECISIONS.md`, and
  `M2_7_STOCK_SYNC_OPERATIONAL_HARDENING_DECISIONS.md` — binding implemented M2
  device catalogue and stock-convergence decisions.
- `backend/sql/020_durable_sale_model.sql` — source-only additive M3.1 UUID sale
  identity, exact snapshot, occurrence/acceptance time, and tender foundation.
- `backend/tests/durable_sale_model_migration_test.php` and
  `backend/tests/run_durable_sale_model.php` — isolated M3.1 preservation,
  identity, exact tender, constraint, and precondition tests.
- `M3_IMPLEMENTATION_DECISIONS.md` and `M3_1_DURABLE_SALE_CONTRACT.md` — approved
  M3 decomposition, withheld operating decisions, and durable sale/receipt
  data contract.
- `TAPTOUCH_UX_BENCHMARK.md` — product-owner-supplied TapTouch observations and
  Adopt/Adapt/Defer/Do-not-adopt dispositions; no direct MP4 inspection.
- `merdpos-design-brief-taptouch-inspired.md` — approved future MerdPOS visual
  intent and implementation boundaries. The Blue Ice brief is retained only as
  a marked superseded historical reference.
- `backend/sql/001_employee_auth_attempts.sql` — historically referenced but
  missing; Milestone 2A.1 replaces that abandoned reference with the reviewed
  additive `012_employee_auth_attempts.sql` draft.

## Exclusions

- Never inspect or modify `timesheet_portal/`.
- Do not commit generated `build/`, `.dart_tool/`, APKs, secrets, or deployment
  markers.
