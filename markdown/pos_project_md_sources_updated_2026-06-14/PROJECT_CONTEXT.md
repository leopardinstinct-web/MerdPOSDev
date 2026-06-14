# POS LATEST — Android POS Project Context

## Purpose
This project is for building and maintaining an Android POS / MerdPOS-related app using ChatGPT across multiple chats without losing continuity.

The key rule is: **do not depend on chat memory alone**. Every new chat must read these project files first and continue from the latest uploaded/source files.

---

## Current Project Focus
The recent work has focused on:

1. Android/Flutter app work, especially `main.dart`.
2. MerdPOS/API timesheet integration.
3. Payroll/timesheet output matching actual payroll generated from CSV/PDF references.
4. Fixing API version confusion, especially around **API Timesheet v2**.
5. Preventing old versions of code from being reused by mistake.
6. Making sure any future code change is delivered as a **complete replacement file**, not snippets.
7. Using the connected Google Drive folder as the source-of-truth location for project files.

---

## Connected Google Drive Source

Google Drive folder **MerdPOSDev** is connected and accessible.

Verified top-level folders:

```text
MerdPOSDev/
├── merdpos_staff/
└── backend/
```

Verified Flutter project area:

```text
merdpos_staff/
├── lib/
├── android/
├── web/
├── test/
├── build/
├── .dart_tool/
├── .idea/
└── README.md
```

Important note:

```text
build/
.dart_tool/
.idea/
```

are generated or IDE folders and should not be treated as primary source files unless specifically debugging build/IDE issues.

---

## Confirmed Latest / Important Source Files

Use these Drive files before making code changes:

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

---

## Critical Working Rules

These are mandatory for every future chat in this project:

1. **Do not guess previous code.**
2. **Use the latest uploaded/source files only.**
3. **If a required file is missing, say exactly which file is missing.**
4. **When changing code, provide complete replacement files as downloads.**
5. **Do not provide partial snippets unless explicitly requested.**
6. **Preserve working logic unless the user explicitly asks to change it.**
7. **Do not silently replace API versions.**
8. **Check actual payroll/timesheet references before changing payroll logic.**
9. **When something is uncertain, mark it clearly instead of inventing.**
10. **At the end of coding work, update this handover pack.**
11. **Before modifying app/backend code, inspect the actual contents of `main.dart`, `get_timesheet.php`, and `config.php`.**

---

## Important User Preference
The user wants concise, direct debugging help and complete downloadable files.

For this project, the default response style should be:

- Direct.
- No unnecessary explanation.
- No guessing.
- Provide files when code is modified.
- Keep continuity through project files.
- Use complete replacement files rather than fragments.

---

## Known App/Backend Context

### Login
- Numeric employee login is expected.
- `USER_ID` and `PASSWORD` are numeric.
- Do not assume email login unless the user explicitly changes this.

### Timesheet / Payroll
- The app has been working toward showing API-based timesheet data.
- The important visible app version label mentioned by the user: **API Timesheet v2**.
- There was an issue where the app still showed an older version.
- There was an issue where API v2 appeared but timesheet data did not show.
- Payroll/timesheet totals must match actual payroll generated from CSV/PDF references.

### Backend API Mentioned / Verified
- `backend/api/get_timesheet.php`
- Earlier API/version messages included:
  - `payroll-rounded-v5-legacy-gas-match`
  - `payroll-rounded-v6-db-compat-legacy-gas-match`
- `backend/api/config.php` exists and should be checked before changing DB connection handling.
- API compatibility previously attempted to support common variables/connections such as `$pdo`, `$conn`, `$mysqli`, `$db`, `db.php`, `config.php`, and DB constants.

---

## Known Recent Problems

1. New chat did not carry enough context from the previous chat.
2. Old `main.dart` version appeared again.
3. User could not see **API Timesheet v2**.
4. After seeing API v2, no timesheet data appeared.
5. Backend API returned database connection errors.
6. Payroll totals did not match the actual payroll reference.
7. User was given a Dart file that did not correctly reflect the project state.

---

## Required Procedure for New Chat

Every new chat in this project should start by doing this:

1. Read `PROJECT_CONTEXT.md`.
2. Read `FILE_MANIFEST.md`.
3. Check the connected Google Drive folder and latest source files.
4. Confirm which files are available.
5. Continue only from the latest files.
6. If modifying app/backend, provide complete replacement files as downloads.
7. Update `CHANGELOG.md` and `BUGS_AND_FIXES.md` after changes.

---

## Do Not Overwrite Without Checking

These files must not be recreated from memory if the real source file is available:

- `merdpos_staff/lib/main.dart`
- `backend/api/get_timesheet.php`
- `backend/api/config.php`
- Any payroll/timesheet calculation file
- Any file connected to login/authentication

---

## Next Chat Starter
Use the prompt stored in `NEW_CHAT_STARTER_PROMPT.md` when opening a new chat.
