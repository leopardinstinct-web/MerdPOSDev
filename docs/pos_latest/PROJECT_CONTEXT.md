# POS LATEST — Current Project Context

Last reconciled from local source: 2026-08-05

Repository: `leopardinstinct-web/MerdPOSDev`

Merged documentation baseline: `ae873f86f2390f5becba5679dfb0de887d489dd3` (`ae873f8`)

Current Level 2 branch: `milestone-1-trusted-baseline-ci`

## Authority

GitHub source is authoritative for implemented behavior. This directory is the
authoritative project guidance and must be kept synchronized with source.
Google Drive and `markdown/pos_project_md_sources_updated_2026-06-14/` are
historical snapshots, not current specifications.

No production deployment was inspected during this reconciliation. References
to the older deployed marker `4a54c41` are historical and must not be treated as
the current repository or production state without separate verification.

## Scope and boundaries

- May read: `docs/`, `markdown/`, `merdpos_staff/`, `backend/`.
- May edit only after approval: `merdpos_staff/`, `backend/`, and current docs.
- Never inspect or modify `timesheet_portal/`.
- Existing Timesheet and payroll behavior must be preserved. New Timesheet
  development is excluded from the current product roadmap.
- Never call production APIs/databases or deploy without explicit approval.

## Current application architecture

### Flutter Android application

The app uses a modular Dart `library`/`part` structure rooted at
`merdpos_staff/lib/main.dart`.

- `models/`: app session, employee, retail product/sale, preserved timesheet row.
- `services/`: HTTP client, authentication, employees, primary session,
  retail SQLite/sync, and preserved timesheet parsing.
- `screens/`: setup, login, home, POS, orders, inventory, financials, and
  preserved timesheet screen.
- `dialogs/`: password change and secondary-user login.
- `widgets/`: shared shell, side rail, common widgets, preserved timesheet table.
- `theme.dart`: Blue Ice theme tokens.

The production API base URL is currently compiled into `main.dart` as
`https://app.merdpos.com/api`. Environment-specific configuration requires a
future decision.

### Authentication and sessions

- Numeric `user_id` and PIN/password only.
- Flutter authenticates through `backend/api/login.php`.
- Backend supports `password_hash()`/`password_verify()` and transparently
  upgrades legacy plaintext secrets on successful login.
- `get_employees.php` does not return password/PIN fields.
- `change_password.php` hashes the new numeric secret in both
  `login_password` and `pin_code` for legacy compatibility.
- Login and password-change code uses an optional `employee_auth_attempts`
  table, but the documented migration file is missing from this commit.
  Lockout therefore cannot be assumed active.
- One primary employee persists by employee ID; one temporary secondary user
  is supported. Maximum visible users: two.
- Device UUID and activation token are stored in `SharedPreferences`, which
  conflicts with the secure-storage requirement.

### Setup and activation

The app loads stores using company code/setup key, then calls
`activate_device.php` with client/store/device data. The endpoint issues or
rotates a random bearer token. Token lifetime, expiry, revocation, and secure
re-activation policy are **requires decision**. The endpoint currently does not
prove possession of the setup key when issuing the token.

### Retail v1

Implemented Flutter modules:

- local product catalogue with barcode/name/category search;
- basket and quantity controls;
- cash/card sale recording (recording only; no payment-terminal integration);
- order history with pending/synced status;
- local stock deductions and manual adjustments;
- same-day local revenue, transaction, and margin summary;
- manual outbound synchronization of sales and stock movements.

Retail persistence uses `sqflite` database `merdpos_retail.db`. Products,
sales, sale lines, and stock movements are stored locally. A fresh database is
seeded with demonstration products. `sync_retail.php` accepts pending sales and
movements using a device token and writes them transactionally to MySQL.

Current retail synchronization is outbound-only. There is no authoritative
product/price/stock download, conflict-resolution protocol, or per-record
rejection workflow. The client marks all pending records synced after a broad
successful response.

### Admin v1

`backend/admin/` provides a Blue Ice browser administration console with:

- ADMIN employee login and PHP sessions;
- dashboard metrics;
- employees and stores;
- categories and products;
- store inventory, reorder levels, and store prices;
- suppliers;
- basic purchase-order creation/receiving;
- sales browser and store summaries;
- device browser;
- audit logs and settings/status page.

Admin uses prepared PDO statements, CSRF tokens, session regeneration,
30-minute inactivity timeout, output escaping, and audit records. The current
UI is basic and does not yet represent a complete role/permission or CRUD model.

### PHP API and MySQL

Current endpoint inventory is maintained in `API_CONTRACT.md`. Schema files
present at this commit:

- `backend/sql/010_retail_platform.sql`
- `backend/sql/011_admin_platform.sql`

`backend/sql/001_employee_auth_attempts.sql` is referenced historically but is
absent.

## Build and release state

The production VPS inspected during Phase 0 has Git and a PHP-CGI wrapper, but
does not have Flutter, Dart, Java, Gradle, Android SDK, ADB, a Gradle wrapper,
or resolved Flutter package metadata. Android builds must run on CI or a
dedicated development/build machine, not this production VPS.

Milestone 1 introduces GitHub Actions using Flutter `3.44.2` exactly for
formatting, analysis, local-fixture tests, and a debug APK build. PHP files are
parsed with PHP 8.2 CLI in CI. Gitleaks scans changes with redacted output.
Debug APK artifacts are retained for seven days. Production release signing,
deployment automation, and production credentials remain excluded.

Android release limitations in source:

- application ID remains `com.example.merdpos_staff`;
- release build uses debug signing;
- no landscape lock or dual-display implementation is present;
- Montserrat is referenced by the theme but is not packaged in `pubspec.yaml`;
- no Flutter tests are present.

See `CI_ENVIRONMENT_PLAN.md` and `PRODUCT_ROADMAP_DRAFT.md`.

## Immediate priorities

1. Validate Milestone 1 workflows in GitHub Actions and resolve any missing
   Android wrapper blocker without generating files on the production VPS.
2. Define and harden device activation/token lifecycle.
3. Restore guaranteed numeric-PIN lockout and add automated auth tests.
4. Implement reliable bidirectional retail master-data synchronization.
