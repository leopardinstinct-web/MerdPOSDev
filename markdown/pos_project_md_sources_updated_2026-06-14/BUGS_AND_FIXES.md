# Bugs and Fixes — POS LATEST

Use this file to track known issues, fixes attempted, and current status.

---

## Open / Recent Issues

### 1. New Chat Loses Project Continuity
**Status:** Process fix created.

**Problem:** New project chats may not reliably continue from previous chat details.

**Fix:** Use this handover pack. Every new chat must read `PROJECT_CONTEXT.md`, `FILE_MANIFEST.md`, and latest source files first.

**Current improvement:** Google Drive folder is now connected and source structure has been verified.

---

### 2. Old App Version Still Showing
**Status:** Needs verification against latest Drive `main.dart`.

**Problem:** User reported that the previous app version was still showing after changes.

**Current source location to inspect:**

```text
merdpos_staff/lib/main.dart
```

**Possible causes:**
- Wrong `main.dart` used.
- App not rebuilt/reinstalled.
- Old APK still installed.
- Flutter hot reload/hot restart not enough.
- Version label not updated in the actual active screen.

**Next debugging steps:**
1. Fetch/inspect latest Drive `merdpos_staff/lib/main.dart`.
2. Search code for old version label.
3. Search code for `API Timesheet v2`.
4. Clean/rebuild app.
5. Uninstall old APK from Android device/emulator.
6. Reinstall fresh build.

---

### 3. `API Timesheet v2` Not Visible
**Status:** Needs verification against latest Drive `main.dart`.

**Problem:** User could not see `API Timesheet v2` in the app.

**Next debugging steps:**
1. Inspect latest `merdpos_staff/lib/main.dart`.
2. Confirm which screen should show the label.
3. Search latest `main.dart` for `API Timesheet v2`.
4. Confirm rebuilt APK uses the latest file.

---

### 4. API v2 Visible but No Timesheet Data Showing
**Status:** Needs API response and parser verification.

**Problem:** App showed API v2 but no timesheet data.

**Confirmed relevant files:**

```text
merdpos_staff/lib/main.dart
backend/api/get_timesheet.php
backend/api/config.php
backend/api/get_employees.php
backend/api/get_stores.php
backend/api/sync_shifts.php
```

**Possible causes:**
- API response shape does not match app parser.
- API returned `success:false`.
- Database connection error.
- Empty filters.
- Wrong date/week range.
- Wrong client/store/employee ID.
- Shift import/sync not populated.

**Next debugging steps:**
1. Open API URL directly.
2. Save full JSON response.
3. Compare JSON keys with app parser.
4. Inspect `get_timesheet.php`.
5. Inspect `config.php`.
6. Inspect `main.dart` parser.
7. Fix parser or API response contract.

---

### 5. Backend Database Connection Not Found
**Status:** Config file found; contents still need inspection.

**Problem:** API returned database connection error.

**Confirmed relevant file:**

```text
backend/api/config.php
```

**Known error examples:**

```json
{
  "success": false,
  "api": "get_timesheet.php",
  "version": "payroll-rounded-v6-db-compat-legacy-gas-match",
  "error": "Database connection not found."
}
```

**Possible causes:**
- `config.php` not included correctly.
- Connection variable name different from expected.
- DB constants unavailable.
- API file placed in different folder path than expected.
- API expects `$pdo` but config creates `$conn`/`$mysqli` or vice versa.

**Next debugging steps:**
1. Inspect current `backend/api/config.php`.
2. Inspect current `backend/api/get_timesheet.php`.
3. Identify actual connection variable.
4. Modify API without breaking existing backend.

---

### 6. Payroll Totals Do Not Match Actual Payroll
**Status:** High priority; needs reference comparison.

**Problem:** User reported app/API payroll did not match actual payroll generated from CSV/PDF.

**Known reference context:**
- Week example: 18 May - 24 May PDF was provided previously.
- CSV payroll generation is the ground truth when supplied.

**Relevant files to inspect before changes:**

```text
backend/api/get_timesheet.php
backend/api/import_timesheet_logs.php
backend/api/sync_employee_logs.php
backend/api/sync_shifts.php
merdpos_staff/lib/main.dart
```

**Rule:** Do not change payroll logic by guessing. Compare raw shifts, rounding, counted hours, review exclusions, and final totals against the reference file.

---

## Current Verified Source Status

Google Drive source structure has been verified.

Confirmed important files:

```text
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
```

Next step before any code change:

```text
Inspect actual file contents, especially main.dart, get_timesheet.php, and config.php.
```
