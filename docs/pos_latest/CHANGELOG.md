# Changelog — POS LATEST

Use this file to record every meaningful change.

## 2026-08-05 — Milestone 1 trusted baseline and CI implementation

Changed:

- Sanitized `backend/api/config.sample.php` to safe placeholders; previous value
  is treated as historically exposed, rotated, and never reproduced.
- Added GitHub Actions repository hygiene, PHP 8.2 syntax parsing, Flutter
  `3.44.2` formatting/analysis/tests, and debug APK build.
- Added redacted Gitleaks change scanning plus optional manual full-history scan.
- Added CODEOWNERS for CI/security-sensitive paths.
- Added one exact legacy repository-hygiene exception for
  `timesheet_portal/includes/config.php`; the file remains excluded from
  inspection and modification and requires a future separate security review.
- Added a temporary, manually dispatched, read-only GitHub Actions workflow to
  generate the three missing Android wrapper files from a clean Flutter 3.44.2
  project for review.
- Added local-only model tests and preservation-only Timesheet parser regression
  fixtures. Tests do not call production services.
- Set debug APK artifact retention to seven days.

Not changed:

- No production signing, release workflow, deployment, application ID, Android
  project regeneration, API behavior, app feature screen, or Timesheet behavior.
- App/API version reporting is deferred to a separate feature milestone.

Validation status:

- Local structural, hygiene, whitespace, and secret-pattern checks are run on
  the VPS without installing build tooling.
- Flutter/PHP/Android workflow execution requires GitHub Actions after separate
  Level 3 approval to commit/push/open a PR.
- Android debug build may be blocked by the missing Gradle wrapper; no wrapper
  is generated on the VPS.

## 2026-08-05 — Documentation reconciliation for source commit 29de6f4

Changed:

- Reconciled authoritative guidance with current modular Flutter, Retail v1,
  Admin v1, API, SQLite, and SQL source.
- Replaced stale current-state marker claims with repository baseline `29de6f4`;
  production deployment remains unverified.
- Confirmed backend password hashing/migration is implemented.
- Documented missing `001_employee_auth_attempts.sql`, unresolved activation
  token policy, plain SharedPreferences token storage, and release limitations.
- Added product roadmap draft, implementation status, CI environment plan, and
  explicit legacy-document deprecation notice.

Files:

- `docs/pos_latest/` documentation only.

Tested:

- Documentation/source inventory, consistency searches, and Git diff checks.
- No application/backend code, production API/database, deployment, or build
  environment was changed.

Open decisions:

- See `PRODUCT_ROADMAP_DRAFT.md`, `SECURITY.md`, and
  `IMPLEMENTATION_STATUS.md`.

## 2026-08-01 — Stable deployment, security rotation, portal/app verification

Changed:
- Deployed live API checkpoint marker `4a54c41`.
- Added/verified `backend/api/index.php` behavior so `/api/` no longer exposes public directory listing.
- Verified `version_check.php` returns `deployed_commit: 4a54c41` and `api_status: ok`.
- Removed `backend/api/config.php` from Git tracking before this checkpoint.
- Added/kept non-secret `backend/api/config.sample.php` as the committed config shape.
- Rotated the database password in Namecheap/cPanel and updated the real local/server `config.php` without pasting credentials into chat.
- Verified live API still works after password rotation.
- Confirmed `timesheet_portal/` is reachable and working from Google Sheets.
- Confirmed Android app was rebuilt/tested and works against `https://app.merdpos.com/api`.
- Confirmed Android app and live timesheet website use separate data sources:
  Android app = PHP API + MySQL; timesheet portal = Google Sheets.

Files / areas involved:
- `backend/api/index.php`
- `backend/api/version_check.php`
- `backend/api/config.sample.php`
- `backend/api/.deployed_version` (ignored marker; not committed)
- `timesheet_portal/`
- `merdpos_staff/lib/main.dart` and modular Flutter app files

Tested:
- `https://app.merdpos.com/api/version_check.php` shows `4a54c41`.
- `https://app.merdpos.com/api/` returns app root message, not directory listing.
- `https://app.merdpos.com/timesheet_portal/` loads Timesheet Login.
- `get_stores.php` / `get_employees.php` return normal app-level responses, not DB connection failures.
- Android APK built/reinstalled and app flow working.

Open issues / next:
- Add visible app/API version screen inside Android app to make future APK/backend verification easier.
- Continue next POS module only after inspecting latest GitHub files.

2026-06-14 — Google Drive Source Folder Verified
Verified connected Google Drive project folder:

text
MerdPOSDev/
Found top-level folders:

text
MerdPOSDev/
├── merdpos_staff/
└── backend/
Found Flutter project area:

text
merdpos_staff/
├── lib/
├── android/
├── web/
├── test/
├── build/
├── .dart_tool/
├── .idea/
└── README.md
Found important Flutter files:

text
merdpos_staff/lib/main.dart
merdpos_staff/lib/db/local_db.dart
Found backend API files:

text
backend/api/get_timesheet.php
backend/api/config.php
backend/api/import_timesheet_logs.php
backend/api/sync_employee_logs.php
backend/api/init_employee_logs.php
backend/api/import_actual_employees.php
backend/api/get_employees.php
backend/api/get_stores.php
backend/api/activate_device.php
backend/api/sync_shifts.php
backend/api/init_db.php
backend/api/cors_test.php
backend/api/test_activate.php
Updated handover files to reflect the verified Drive structure and source file locations.

Next:

text
Inspect actual code contents before making changes:
- merdpos_staff/lib/main.dart
- backend/api/get_timesheet.php
- backend/api/config.php
2026-06-14 — Handover Pack Created
Created standard continuity files for the Android POS project:

PROJECT_CONTEXT.md

APP_REQUIREMENTS.md

API_CONTRACT.md

CHANGELOG.md

BUGS_AND_FIXES.md

FILE_MANIFEST.md

NEW_CHAT_STARTER_PROMPT.md

HANDOVER_UPDATE_TEMPLATE.md

Purpose:

Prevent new chats from losing context.

Stop old code versions from being reused.

Make future work continue from uploaded/latest files only.

Standardize the rule that complete replacement files must be provided as downloads.

Template for Future Entries
text
## YYYY-MM-DD — Short title

Changed:
- ...

Files modified:
- ...

Reason:
- ...

Tested:
- ...

Open issues:
- ...
2026-06-14 — POS-style dashboard and two-user session requirements added
Changed:

Added recovered UI/session requirements from previous chat into handover files.

Documented top blue store title bar, user badges, dashboard tiles, and two visible user rule.

Documented primary and secondary user dropdown menu rules.

Documented current GitHub/local workflow because GitHub is not appearing as a Project Source in the UI.

Documented verified timesheet API v7 response keys and payroll rules.

Created rebuilt main.dart replacement named main_rebuilt_ui_v2.dart.

Files modified/generated:

PROJECT_CONTEXT.md

APP_REQUIREMENTS.md

API_CONTRACT.md

BUGS_AND_FIXES.md

CHANGELOG.md

FILE_MANIFEST.md

NEW_CHAT_STARTER_PROMPT.md

main_rebuilt_ui_v2.dart

Reason:

Previous full app UI was missing from current source.

Current app was still opening directly to API Timesheet v2.

User recovered previous chat instructions for POS-style dashboard and secondary user behavior.

Tested:

Static file generation only in ChatGPT environment.

User still needs to copy replacement file and run Flutter locally.

Open issues:

Change Password tile/menu action currently needs backend API before it can be functional.

User must confirm company code/setup key if they should be stored in handover.

Add/create employee backend endpoint is still not confirmed.

2026-06-14 — Sidebar user session UI v5 committed
Changed:

Confirmed v5 UI tested locally and committed/pushed to GitHub.

Sidebar user-circle is now the main user/session menu trigger.

Dashboard tiles were removed from the main content area.

Sidebar now focuses on POS, Financials, Inventory, with Sync and Settings at the bottom.

Main Menu header was removed; store name remains visible in the app content.

One-user menu now shows: Time Sheet, Change Password, Log off, Add User.

Two-user menu now shows user-specific logoff options: <first user> Log off and <second user> Log off.

If primary user logs off while secondary user exists, secondary user is promoted to primary and app remains on dashboard.

If only one user is active and logs off, app returns to Staff Login.

Refresh keeps the primary user session; secondary user remains temporary.

Files modified:

merdpos_staff/lib/main.dart

Generated replacement file:

main_rebuilt_ui_v5.dart

Reason:

User requested a sidebar/avatar UI closer to the existing POS reference PDF and corrected two-user logoff behavior.

Tested:

User confirmed setup/login/dashboard works.

User confirmed primary + secondary visible user flow works.

User reported v4 primary logoff issue; v5 fixed expected behavior.

User confirmed v5 seems OK and completed Git commit/push.

Open issues:

Time Sheet may still not show DB data depending on week/store/employee filters.

Change Password still needs a confirmed backend endpoint.

POS, Financials, Inventory, Sync, and Settings are currently navigation/UI placeholders unless later connected to backend modules.

2026-06-14 — Timesheet UI v14, client-wide employees, and Change Password
Changed:

Updated Time Sheet to keep the accepted sidebar/avatar POS shell.

Added Who Is Working before POS in sidebar.

Changed Time Sheet into a clean responsive table inside the main content area.

Made table fit available width and stop cutting off Total hours.

Matched header alignment to content alignment.

Changed rows wording to shifts.

Staff Time Sheet now remains employee-only but does not filter by selected store.

Fixed employee list logic: device authorization remains store/device scoped, employee list becomes client-wide after authorization.

Added functional Change Password flow and backend endpoint.

Files modified/generated:

merdpos_staff/lib/main.dart

backend/api/get_employees.php

backend/api/change_password.php

main_rebuilt_ui_v14.dart

get_employees_clientwide_v2.php

change_password.php

pos_latest_ui_v14_responsive_table.zip

Reason:

Employees were imported into one store but shifts exist across stores.

Staff Time Sheet must show the employee's own shifts across all stores.

Time Sheet UI needed to remain inside the POS shell and be responsive.

Change Password needed real functionality.

Tested in chat/user flow:

User confirmed everything works except table fit before v14.

v14 generated specifically to fix responsive table cutoff.

Commit command:

cmd
cd /d C:\\Dev\\MerdPOSDev
git add merdpos_staff/lib/main.dart backend/api/get_employees.php backend/api/change_password.php
git commit -m "Update timesheet UI and employee password handling"
git push
git status
Open issues:

Backend deployment to Namecheap must include change_password.php and updated get_employees.php.

Android APK/device test still pending if only web was tested.

2026-06-16 — Handover pack overhaul (continuity + security + design)
Changed:

Set GitHub as the single source of truth across all files; Drive demoted to backup.

Collapsed stacked v2/v5/v14 instruction layers into one authoritative CURRENT
STATE block (overwrite-each-chat) in PROJECT_CONTEXT.md and the starter prompt.

Rewrote NEW_CHAT_STARTER_PROMPT.md with a Role + Rules block, a blocking
security gate, a design gate, and a pre-handover self-check.

Added SECURITY.md (config.php/secret handling, password hashing + migration,
PIN rate-limiting, prepared statements, error/transport/CORS rules).

Created API_CONTRACT.md (was referenced everywhere but never existed) to pin
timesheet request params and exact response keys.

Fixed merdpos-design-brief-blue-ice.md: rebuilt the mangled palette table and
corrected the mislabeled green hex (#A3BE8C) to a true cool accent (#5FB6E6).

Added DESIGN_TOKENS.md with canonical tokens and a Flutter theme.dart mapping.

Updated FILE_MANIFEST, APP_REQUIREMENTS, COMMIT_INSTRUCTIONS, and the handover
template to reference the new docs and the security/design gates.

Files modified/added:

NEW_CHAT_STARTER_PROMPT.md, PROJECT_CONTEXT.md, FILE_MANIFEST.md,
APP_REQUIREMENTS.md, COMMIT_INSTRUCTIONS.md, HANDOVER_UPDATE_TEMPLATE.md,
merdpos-design-brief-blue-ice.md

SECURITY.md (new), API_CONTRACT.md (new), DESIGN_TOKENS.md (new)

Reason:

Goal: make the LLM behave as a secure dev + design-system-disciplined builder.

Old files lacked any security layer and never wired the design system into the
workflow; source-of-truth was contradictory (Drive vs GitHub).

Open issues:

config.php credentials are in git history -> git-ignore + rotate DB password.

Password storage (hashed vs plaintext) unconfirmed -> verify, migrate if needed.

API_CONTRACT.md has ___ placeholders -> fill from real deployed responses.

Stack versions (Flutter/PHP/DB/hosting) not yet recorded.

2026-06-16 — Screenshot‑driven workflow & architecture guardrails
Changed:

Added Screenshot Discovery Protocol to NEW_CHAT_STARTER_PROMPT.md –
forces the LLM to ask scoping questions before coding from a visual.

Created FEATURE_SCOPING_TEMPLATE.md – standardised handover for new features
requested via competitor screenshots.

Added main.dart size limit (1500 lines) to PROJECT_CONTEXT.md – triggers
modularisation before new features.

Updated HANDOVER_UPDATE_TEMPLATE.md with a Full‑Stack Delivery Check
(SQL migration, PHP endpoints, Flutter UI, API contract update).

Added note in APP_REQUIREMENTS.md and DESIGN_TOKENS.md to replace
standard green/red with cool teal/magenta.

Files added/modified:

NEW_CHAT_STARTER_PROMPT.md (updated)

PROJECT_CONTEXT.md (updated)

HANDOVER_UPDATE_TEMPLATE.md (updated)

APP_REQUIREMENTS.md (updated)

DESIGN_TOKENS.md (updated)

FEATURE_SCOPING_TEMPLATE.md (new)

FILE_MANIFEST.md (updated)

Reason:

User wants to work by sending screenshots and having the LLM build the
complete feature (backend + frontend + DB). The new protocol ensures the
LLM clarifies scope and data model before writing any code, avoiding
expensive rewrites.


2026-06-16 — Password hashing migration package prepared
Changed:
- Added backend login endpoint design: backend/api/login.php.
- Updated change_password.php to store password_hash() instead of plaintext.
- Updated get_employees.php contract to stop returning login_password and pin_code.
- Added migration 001_employee_auth_attempts.sql for login/change-password lockout.
- Prepared main.dart.patch to replace client-side password checking with backend login for primary and secondary users.

Reason:
- Password and PIN were confirmed plaintext.
- The app was verifying login locally using login_password from get_employees.php, which blocks safe hashing unless login moves to the backend.

Deployment note:
- Deploy patched main.dart and PHP endpoints together.
- config.php remains unchanged for now per user instruction, but should be removed/rotated later.

Test needed:
- Run SQL migration.
- Deploy login.php, get_employees.php, change_password.php.
- Apply main.dart.patch and build app.
- Login once with an existing plaintext PIN; confirm DB value becomes password_hash().
- Log out and log back in with same PIN.
- Change password; confirm new hash is stored and login works with the new PIN.


2026-06-16 — v16 modular hashed-login Blue Ice upgrade
Changed:
- Split the Flutter monolith into `screens/`, `widgets/`, `models/`, `services/`, `dialogs/`, and `utils/` using Dart part files.
- Added backend-authenticated login through `backend/api/login.php`.
- Removed app-side plaintext password comparison.
- Updated secondary-user login to verify through backend.
- Updated `get_employees.php` so password/PIN fields are not returned to the app.
- Updated `change_password.php` to store `password_hash()` values.
- Added `001_employee_auth_attempts.sql` for lockout/rate limiting.
- Applied Blue Ice theme tokens through `theme.dart`.

Files added/modified:
- `merdpos_staff/lib/main.dart`
- `merdpos_staff/lib/theme.dart`
- `merdpos_staff/lib/models/*`
- `merdpos_staff/lib/services/*`
- `merdpos_staff/lib/screens/*`
- `merdpos_staff/lib/widgets/*`
- `merdpos_staff/lib/dialogs/*`
- `merdpos_staff/lib/utils/helpers.dart`
- `backend/api/login.php`
- `backend/api/get_employees.php`
- `backend/api/change_password.php`
- `backend/sql/001_employee_auth_attempts.sql`

Reason:
- The markdown architecture rule required modularization because `main.dart` exceeded 1500 lines.
- Password hashing could not safely work while Flutter compared downloaded plaintext passwords.

Tested:
- Static packaging only in ChatGPT environment. User must run Flutter locally.

Open issues:
- `config.php` remains in repo by user instruction; remove and rotate credentials as soon as practical.
- Namecheap deployment must receive all backend files before the new Flutter build is used.
