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
- `IMPLEMENTATION_STATUS.md` — source-based roadmap status.
- `CI_ENVIRONMENT_PLAN.md` — non-production build/test design.
- `LEGACY_DOCUMENTATION_DEPRECATION.md` — authority notice for old packs.
- `CHANGELOG.md`, `BUGS_AND_FIXES.md` — history and active defects.

## Flutter application

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

## Backend API

Application endpoints:

- `activate_device.php`, `get_stores.php`, `get_employees.php`
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
- `backend/sql/001_employee_auth_attempts.sql` — **missing**, despite historical
  documentation references.

## Exclusions

- Never inspect or modify `timesheet_portal/`.
- Do not commit generated `build/`, `.dart_tool/`, APKs, secrets, or deployment
  markers.
