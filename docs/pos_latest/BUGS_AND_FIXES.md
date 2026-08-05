# Bugs and Fixes — POS LATEST

Use this file to track known issues, fixes attempted, and current status.

## Current source reconciliation — 2026-08-05

Source baseline: `29de6f4`. Production was not inspected.

Active non-Timesheet issues:

- Tracked `config.sample.php` contained a real-looking historical value. Fixed
  in Milestone 1 by replacing values with placeholders. It is treated as
  exposed and already rotated; history cleanup is deferred.
- Login/password endpoints use the fail-closed lockout service in 2A.2;
  migration 012 still requires separate execution approval.
- Dedicated setup-grant activation and token lifecycle enforcement are
  integrated for the 2A.2 endpoint set; remaining endpoints await 2A.3.
- Flutter bearer token is stored in plain SharedPreferences.
- Retail synchronization is outbound-only and aggregate-acknowledged.
- Production demo product seeding is not separated from development mode.
- Milestone 1 CI and initial Flutter tests are verified; 2A.1 adds deterministic
  backend security tests, while endpoint/database integration coverage remains
  incomplete.
- Android uses example application ID and debug release signing.
- Flutter/Dart/Android toolchain is unavailable on the production VPS.
- Pre-existing Dart application source is not uniformly formatted. Milestone 1
  CI checks formatting only for changed tracked Dart files under
  `merdpos_staff/lib/` and `merdpos_staff/test/`; repository-wide formatting is
  separate technical debt and must not create unrelated application diffs in
  this milestone.
- The Flutter 3.44.2 analyzer reports 38 pre-existing information-level
  findings, including deprecations. Milestone 1 keeps them visible without
  making information findings fatal; they remain separate technical debt.
  Analyzer errors and warnings remain fatal, and future modified files must not
  introduce new analyzer errors or warnings.

Milestone 1 CI status:

- Flutter 3.44.2 format/analyze/test workflow: merged and verified.
- PHP 8.2 syntax workflow: merged and verified.
- Redacted Gitleaks workflow: merged and verified.
- Debug APK workflow: implemented. The three approved Android wrapper files
  were restored from the reviewed Flutter 3.44.2 GitHub Actions artifact. The
  artifact file set, JAR checksum, distribution URL, and executable script mode
  were verified before copying; no wrapper was generated on the production VPS.
- Repository hygiene has one exact legacy exception for
  `timesheet_portal/includes/config.php`. Its contents remain uninspected and
  unchanged. The exception requires a future separate security review and does
  not permit any other `config.php`.

Historical entries below describe earlier checkpoints. Where they conflict with
this block, current source and current authoritative documents win.

Milestone 2A.1 status:

- Added migration drafts and shared/tested security foundation components; no
  production migration was run and existing endpoint behavior is unchanged.
- Migration `014` checks the tracked legacy devices shape and aborts on visible
  incompatibility; production schema remains unknown and requires separate
  reconciliation and approval.
- Lockout persistence fails closed in the integrated 2A.2 endpoints.
- Legacy token transport exists exclusively in the shared device-auth helper.
- Dedicated POST `request_activation_grant.php` is implemented in 2A.2;
  `get_stores.php` remains unchanged and does not issue grants.

Open / Recent Issues
0. 2026-08-01 Stable Deployment / Security Checkpoint
Status: Fixed / verified.

Confirmed:

text
GitHub/live marker: 4a54c41
Live API: https://app.merdpos.com/api
Timesheet portal: https://app.merdpos.com/timesheet_portal/
DB password rotated
backend/api/config.php removed from Git tracking
/api/ directory listing fixed
Android app rebuilt/tested and working
Timesheet portal working from Google Sheets
Android app working from PHP API + MySQL

Important distinction:
- Android app does not use the Google Sheets portal.
- `timesheet_portal/` does not use the Android app MySQL API for its source data.

1. New Chat Loses Project Continuity
Status: Process fix created.

Problem: New project chats may not reliably continue from previous chat details.

Fix: Use this handover pack. Every new chat must read PROJECT_CONTEXT.md, FILE_MANIFEST.md, and latest source files first.

Current improvement: Google Drive folder is now connected and source structure has been verified.

2. Old App Version Still Showing
Status: Fixed / current APK verified working after rebuild.

Problem: User reported that the previous app version was still showing after changes.

Current source location to inspect for any future recurrence:

text
merdpos_staff/lib/main.dart
Possible causes:

Wrong main.dart used.

App not rebuilt/reinstalled.

Old APK still installed.

Flutter hot reload/hot restart not enough.

Version label not updated in the actual active screen.

If this happens again, debugging steps:

Fetch/inspect latest Drive merdpos_staff/lib/main.dart.

Search code for old version label.

Search code for API Timesheet v2.

Clean/rebuild app.

Uninstall old APK from Android device/emulator.

Reinstall fresh build.

3. API Timesheet v2 Not Visible
Status: Not currently active. App is working after rebuild.

Problem: User could not see API Timesheet v2 in the app.

Next debugging steps:

Inspect latest merdpos_staff/lib/main.dart.

Confirm which screen should show the label.

Search latest main.dart for API Timesheet v2.

Confirm rebuilt APK uses the latest file.

4. API v2 Visible but No Timesheet Data Showing
Status: Not currently active. Keep this checklist for future timesheet debugging.

Problem: App showed API v2 but no timesheet data.

Confirmed relevant files:

text
merdpos_staff/lib/main.dart
backend/api/get_timesheet.php
backend/api/config.php
backend/api/get_employees.php
backend/api/get_stores.php
backend/api/sync_shifts.php
Possible causes:

API response shape does not match app parser.

API returned success:false.

Database connection error.

Empty filters.

Wrong date/week range.

Wrong client/store/employee ID.

Shift import/sync not populated.

Next debugging steps:

Open API URL directly.

Save full JSON response.

Compare JSON keys with app parser.

Inspect get_timesheet.php.

Inspect config.php.

Inspect main.dart parser.

Fix parser or API response contract.

5. Backend Database Connection Not Found
Status: Fixed for current live API after DB password rotation.

Problem: API returned database connection error.

Confirmed relevant file:

text
backend/api/config.php
Known error examples:

json
{
  "success": false,
  "api": "get_timesheet.php",
  "version": "payroll-rounded-v6-db-compat-legacy-gas-match",
  "error": "Database connection not found."
}
Possible causes:

config.php not included correctly.

Connection variable name different from expected.

DB constants unavailable.

API file placed in different folder path than expected.

API expects $pdo but config creates $conn/$mysqli or vice versa.

If this happens again:

Inspect current backend/api/config.php.

Inspect current backend/api/get_timesheet.php.

Identify actual connection variable.

Modify API without breaking existing backend.

6. Payroll Totals Do Not Match Actual Payroll
Status: Watch item only. Do not change payroll logic without CSV/PDF comparison.

Problem: User reported app/API payroll did not match actual payroll generated from CSV/PDF.

Known reference context:

Week example: 18 May - 24 May PDF was provided previously.

CSV payroll generation is the ground truth when supplied.

Relevant files to inspect before changes:

text
backend/api/get_timesheet.php
backend/api/import_timesheet_logs.php
backend/api/sync_employee_logs.php
backend/api/sync_shifts.php
merdpos_staff/lib/main.dart
Rule: Do not change payroll logic by guessing. Compare raw shifts, rounding, counted hours, review exclusions, and final totals against the reference file.

Current Verified Source Status
GitHub is source of truth. Google Drive is backup/snapshot only.

Confirmed important files:

text
merdpos_staff/lib/main.dart
merdpos_staff/lib/db/local_db.dart
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
Next step before any code change:

text
Inspect actual file contents, especially main.dart, get_timesheet.php, and config.php.
2026-06-14 — Missing Full UI/Main Dart Recovered by Rebuild
Issue
The current GitHub/active main.dart had become a simplified timesheet-only app showing API Timesheet v2 directly. It did not contain the previous full app shell with store title bar, user badge, secondary user, or dashboard tiles.

Cause
The original full main.dart with user-session UI was not available in the current source. The previous UI requirements were only in chat context and were not written into the handover .md files.

Fix in Progress
A reconstructed full main.dart has been created from verified backend APIs and the recovered UI/session requirements.

Current Expected Fix
Use replacement file:

text
main_rebuilt_ui_v2.dart
Copy it to:

text
C:\\Dev\\MerdPOSDev\\merdpos_staff\\lib\\main.dart
Test Needed
Run:

cmd
cd /d C:\\Dev\\MerdPOSDev\\merdpos_staff
flutter clean
flutter pub get
flutter run -d chrome
Expected first screen after setup is no longer a direct timesheet screen. Expected flow:

text
MerdPOS Setup -> Staff Login -> <Store Name> - Main Menu
Main Menu must show primary user badge and module tiles.

2026-06-14 — v5 User Logoff Promotion Fix
Issue
In v4, when two users were active and the first/primary user clicked log off, the app returned to the login screen instead of keeping the second user active.

Expected Behavior
When two users are active:

text
Faisal + Imran
If Faisal logs off:

text
Imran becomes the only active primary user.
The app stays on the dashboard.
The avatar returns to one circle.
The menu becomes: Time Sheet / Change Password / Log off / Add User.
Only when the last active user logs off should the app return to Staff Login.

Fix
Created main_rebuilt_ui_v5.dart and copied it to:

text
merdpos_staff/lib/main.dart
Status
User confirmed v5 seems OK and Git commit/push is complete.

Remaining Timesheet Issue
The Time Sheet screen may show no rows even when Namecheap DB has data because the app currently calls get_timesheet.php with:

text
client_id
store_id
employee_id
week_start
week_end
If uploaded data is for a different week, store, or employee, the employee-specific timesheet view will be empty. Next debugging step is to test the API in browser with all-store/all-employee parameters and then decide whether to add a manager/all-staff timesheet view.

2026-06-14 — v14 Timesheet UI + Employee Store Mapping + Change Password
Fixed: Employees hidden by store-level employee import
Issue: The app login/employee list was store-scoped, but import_actual_employees.php inserted all employees into store_id = 1. This caused devices for other stores to hide employees or mismatch login/timesheet behavior.

Fix: get_employees.php should authorize the device by selected store/device token, then return all active employees for the client.

Files:

text
backend/api/get_employees.php
Fixed: Staff Time Sheet filtering by selected store
Issue: Timesheet logs are cross-store, but the app was filtering staff Time Sheet by the selected store. This hid valid shifts worked at other stores.

Fix: Staff Time Sheet now queries by employee/week and does not send store_id.

Files:

text
merdpos_staff/lib/main.dart
Fixed: Time Sheet UI broke away from POS shell
Issue: Time Sheet opened as a separate page with its own blue AppBar and static sidebar behavior.

Fix: Time Sheet now keeps the same dark sidebar/avatar shell and changes only the main workspace content.

Fixed: Table overflow/cut-off
Issue: The table did not use available width and cut off the Total hours column.

Fix: v14 responsive table calculates widths from available screen space.

Fixed: Change Password not functional
Issue: Change Password existed in the menu but did not have backend functionality.

Fix: Added change_password.php and connected app dialog.

Files:

text
backend/api/change_password.php
merdpos_staff/lib/main.dart
Open / Test Needed
Confirm backend change_password.php is deployed to Namecheap.

Confirm get_employees.php deployed to Namecheap.

Confirm v14 committed after local testing.

Confirm Android build after web testing.

2026-06-16 — Security items raised during handover review
S1. DB credentials committed to git (config.php)
Status: Fixed as of 2026-08-01 stable checkpoint.
Problem: backend/api/config.php with real DB credentials had been committed
(private repo). Private lowered exposure but credentials remained in history for
all collaborators / any leaked token.
Fix completed: config.php removed from Git tracking, config.sample.php added,
DB password rotated in Namecheap/cPanel, live API verified after rotation.
Remaining optional hardening: purge old secret from Git history if needed.

S2. Password storage unconfirmed
Status: Open — verify.
Problem: Unknown whether login_password / pin_code are hashed.
Fix: Confirm. If plaintext/weak hash, migrate to password_hash() and
re-hash transparently on next successful login. See SECURITY.md §2.

S3. No brute-force protection on numeric PINs
Status: Open.
Problem: A 4-digit PIN is only 10,000 combinations; hashing alone doesn't
protect it without throttling.
Fix: Add per-employee/per-device attempt counter + lockout on login and
change-password endpoints. See SECURITY.md §3.

S4. Prepared statements not verified across endpoints
Status: Open — audit.
Fix: Audit every PHP DB query; convert any string-built SQL to parameterized
queries. See SECURITY.md §4.

Process fix: design system was never enforced
Status: Addressed.
Problem: The Blue Ice brief existed but no file required the UI to follow it,
and the palette had a green hex mislabeled as blue.
Fix: Added DESIGN_TOKENS.md + theme.dart mapping, corrected the brief, and
added a design gate to the starter prompt.


2026-06-16 — Password hashing transition
Issue
Passwords and PINs were confirmed to be stored as plaintext, and main.dart was verifying login client-side by downloading login_password through get_employees.php.

Fix package prepared
- backend/api/login.php added for backend-side numeric USER_ID + password/PIN verification.
- Successful legacy plaintext login upgrades both login_password and pin_code to password_hash().
- backend/api/change_password.php updated to verify the current password backend-side and store password_hash().
- backend/api/get_employees.php v3 prepared to stop returning login_password and pin_code.
- main.dart.patch prepared to make primary and secondary login call login.php.
- 001_employee_auth_attempts.sql prepared for brute-force lockout.

Important deployment rule
Deploy login.php + get_employees.php + change_password.php + patched main.dart together. If only PHP files are deployed and the old app remains active, login will break after a password is hashed because the old app expects plaintext login_password.

Config status
Fixed as of 2026-08-01: config.php is local/server-only, removed from Git tracking, and DB password has been rotated. Never commit config.php or .deployed_version.


2026-06-16 — v16 Modularization + Password Hashing
Status: Ready for local test / deploy.

Fixed:
- `main.dart` monolith split into modules to satisfy the architecture guardrail.
- Flutter no longer checks plaintext passwords locally.
- Backend login verifies PIN/password and upgrades plaintext rows to `password_hash()` on successful login.
- `get_employees.php` no longer returns `login_password` or `pin_code`.
- Change Password stores hashed values in both fields for compatibility.

Still open:
- Confirm SQL migration `backend/sql/001_employee_auth_attempts.sql` has been run before relying on lockout behavior.
- Continue endpoint audit for prepared statements and generic errors as future maintenance.
