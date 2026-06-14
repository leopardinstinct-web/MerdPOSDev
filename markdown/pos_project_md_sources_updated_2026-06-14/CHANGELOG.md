# Changelog — POS LATEST

Use this file to record every meaningful change.

---

## 2026-06-14 — Google Drive Source Folder Verified

Verified connected Google Drive project folder:

```text
MerdPOSDev/
```

Found top-level folders:

```text
MerdPOSDev/
├── merdpos_staff/
└── backend/
```

Found Flutter project area:

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

Found important Flutter files:

```text
merdpos_staff/lib/main.dart
merdpos_staff/lib/db/local_db.dart
```

Found backend API files:

```text
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

Updated handover files to reflect the verified Drive structure and source file locations.

Next:

```text
Inspect actual code contents before making changes:
- merdpos_staff/lib/main.dart
- backend/api/get_timesheet.php
- backend/api/config.php
```

---

## 2026-06-14 — Handover Pack Created

Created standard continuity files for the Android POS project:

- `PROJECT_CONTEXT.md`
- `APP_REQUIREMENTS.md`
- `API_CONTRACT.md`
- `CHANGELOG.md`
- `BUGS_AND_FIXES.md`
- `FILE_MANIFEST.md`
- `NEW_CHAT_STARTER_PROMPT.md`
- `HANDOVER_UPDATE_TEMPLATE.md`

Purpose:

- Prevent new chats from losing context.
- Stop old code versions from being reused.
- Make future work continue from uploaded/latest files only.
- Standardize the rule that complete replacement files must be provided as downloads.

---

## Template for Future Entries

```text
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
```
