# POS LATEST — Current Project Context

Last reconciled from local source: 2026-08-06

Repository: `leopardinstinct-web/MerdPOSDev`

Merged source baseline: `4104092e6c2243d07c82c18947d6f819191cd1e4` (`4104092`)

Current planning state: Milestone 1 is source-complete through merged Milestone
2B. Production use of its security controls remains deployment-gated.

Beta branch state — 2026-08-24: `beta/qr-attendance-disputes-financials` now contains source for QR attendance, employee-confirmed POS handover disputes, SUPER-gated shift corrections, portal password changes, and SQL-validated/offline-queued financials mirrored to the existing Sheets. This user-approved beta explicitly extends `timesheet_portal/`; the earlier roadmap exclusion does not apply to this scoped branch. Nothing has been migrated or deployed.

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
- `theme.dart`: implemented legacy Blue Ice theme; future redesign uses the
  TapTouch-inspired original MerdPOS tokens after separate UI review.

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
- Login and password change use the 2A.1 fail-closed layered lockout service.
  Production use still requires separate approval and execution of migration
  012; no migration is executed by application code.
- One primary employee persists by employee ID; one temporary secondary user
  is supported. Maximum visible users: two.
- Device tokens use Android-backed secure storage. Existing SharedPreferences
  tokens migrate silently after a verified write/read round trip and remain for
  the approved two-release compatibility window; non-secret metadata stays in
  SharedPreferences.

### Setup and activation

Flutter setup uses the dedicated POST setup-validation grant and grant-required
activation contracts. New device tokens are stored only as hashes server-side,
expire after 180 days, allow a seven-day previous-token overlap, revoke
immediately, and bind client/store/UUID. Milestone 2A.3 applies the same shared
authorization to the remaining non-Timesheet device endpoints.

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

`backend/admin/` provides a legacy Blue Ice browser administration console with:

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
- Milestone 1 tests cover local model behavior and Timesheet parser regression,
  but broader feature and integration coverage remains absent.

See `CI_ENVIRONMENT_PLAN.md` and `PRODUCT_ROADMAP_DRAFT.md`.

## Milestone 1 security implementation and deployment gate

Milestones 2A.1, 2A.2, 2A.3, and 2B are merged. Source now includes additive
migration drafts, shared PHP security components, activation/authentication and
non-Timesheet endpoint integration, deterministic tests, bearer transport, and
verified Flutter secure-token migration.

Migrations `012_employee_auth_attempts.sql`, `013_activation_grants.sql`,
`014_device_token_security.sql`, and `015_security_audit_events.sql` remain
unexecuted. Production activation, lockout, token lifecycle, and security-audit
behavior remain unavailable until the production schema is reconciled, the
migrations and deployment are separately approved, and the reviewed code and
schema changes are deployed.

The only tracked `devices` definition uses integer client/store IDs,
`device_uuid VARCHAR(150)`, plaintext `activation_token VARCHAR(150)`, and no
token lifecycle columns. Production schema is unknown. Migration `014` checks
visible preconditions, adds no speculative indexes or `updated_at`, and requires
separate approval before database execution.

The legacy SharedPreferences token remains for the approved two-compatible-
release window and is due for removal in the third compatible release.

## Current product milestone

M2.1–M2.7 are complete and merged. M3 — Barcode POS and durable sales — is
approved for sequential implementation beginning with M3.1 durable sale model.
There is no required M2.8. Production reconciliation, migrations, and
deployment remain separate approval boundaries.

M2.1 — Catalogue identity and lifecycle foundation is implemented in source as
an additive, preconditioned migration draft plus isolated synthetic MariaDB
tests. It preserves existing product/category IDs and historical references,
adds exact-text zero-to-many barcode aliases, and enforces client-scoped
case-insensitive SKU identity while preserving display case. It changes no API
endpoint, Flutter, SQLite, Timesheet, or payroll behavior. Production schema
reconciliation, migration execution, and deployment remain separate approval
boundaries. Later M2 contracts must continue to follow
`M2_CATALOGUE_DECISIONS.md`.

M2.2 — Effective pricing and tax foundation adds a preconditioned shadow schema
for client AUD settings, product units, effective-dated regular/promotional
prices, stable tax codes/rates/assignments, overlap rejection, and nullable
future sale-line audit snapshots. Existing `sell_price`, `store_price`,
`tax_rate`, cost fields, completed sales, endpoints, admin, Flutter, SQLite,
checkout, stock, and synchronization remain unchanged and authoritative until
a separately approved integration and production cutover.

M2.3 — Stock ledger and server balance foundation adds a distinct immutable
shadow ledger, transactionally maintained store-product balances, stable
idempotency/source identities, compensating reversals, linked transfer legs,
negative-stock exceptions, and reviewed reconciliation-candidate structures.
It does not reinterpret the legacy movement table or copy
`retail_store_inventory.quantity`. No runtime API, admin, Flutter, SQLite,
checkout, receiving, inventory, or sync behavior changes in this milestone.

M2.4 — Initial full catalogue API adds a device-authorized read-only endpoint
and synthetic integration coverage over the M2.1–M2.3 foundations. It returns
deterministically ordered tenant/store catalogue content, explicit sellability
reasons, resolved effective price/tax, authoritative stock, a content revision,
and an opaque future seed. It does not modify Flutter, SQLite, last-good state,
checkout, outbound sync, or incremental cursor behavior.

## Product-owner UX benchmark and visual direction

TapTouch is the preferred functional and visual benchmark for future admin,
catalogue, pricing, inventory, and POS work. OpenClaw did not receive or inspect
the original MP4; `TAPTOUCH_UX_BENCHMARK.md` records only product-owner-supplied
observations. The original TapTouch-inspired MerdPOS system supersedes Blue Ice
for future reviewable UI work. Existing screens remain unchanged, and Flutter
redesign requires a separate scope.
