# File Manifest — POS LATEST

This file lists important files and how they should be handled.

---

## Handover / Continuity Files

| File | Purpose | Update When |
|---|---|---|
| `PROJECT_CONTEXT.md` | Main project memory and rules | End of every major chat |
| `APP_REQUIREMENTS.md` | Functional requirements | Requirements change |
| `API_CONTRACT.md` | Backend endpoint/response details | API changes |
| `CHANGELOG.md` | Version/change history | Every code change |
| `BUGS_AND_FIXES.md` | Known bugs and fixes | Any bug/fix work |
| `FILE_MANIFEST.md` | File inventory | Files are added/removed |
| `NEW_CHAT_STARTER_PROMPT.md` | Prompt for new chat | Process changes |
| `HANDOVER_UPDATE_TEMPLATE.md` | End-of-chat update template | Process changes |

---

## Connected Google Drive Project Folder

Source folder:

```text
MerdPOSDev/
```

Verified top-level structure:

```text
MerdPOSDev/
├── merdpos_staff/
└── backend/
```

---

## Confirmed Flutter / Android Source Files

| File | Type | Rule |
|---|---|---|
| `merdpos_staff/lib/main.dart` | Main Flutter app source | Use latest Drive version only |
| `merdpos_staff/lib/db/local_db.dart` | Local DB / app storage source | Inspect before changing local storage/offline logic |
| `merdpos_staff/README.md` | Default Flutter README | Not project-specific; can be replaced later |
| `merdpos_staff/android/` | Android platform files | Use only when Android build/native config needs fixing |
| `merdpos_staff/web/` | Web build target files | Usually not relevant to Android app debugging |
| `merdpos_staff/test/` | Tests | Use if writing/running tests |

Generated / low-priority folders:

```text
merdpos_staff/build/
merdpos_staff/.dart_tool/
merdpos_staff/.idea/
```

Do not treat these as primary source files unless debugging build cache, IDE config, or generated output.

---

## Confirmed Backend API Files

| File | Type | Rule |
|---|---|---|
| `backend/api/get_timesheet.php` | Timesheet API | Use latest Drive version only |
| `backend/api/config.php` | Backend DB/API config | Required before fixing DB connection issues |
| `backend/api/import_timesheet_logs.php` | Timesheet import API/script | Inspect before changing import flow |
| `backend/api/sync_employee_logs.php` | Employee logs sync | Inspect before changing sync flow |
| `backend/api/init_employee_logs.php` | Employee logs setup | Do not run/change without checking schema impact |
| `backend/api/import_actual_employees.php` | Employee import | Inspect before employee/auth changes |
| `backend/api/get_employees.php` | Employee list API | Relevant to employee dropdown/login/timesheet |
| `backend/api/get_stores.php` | Store list API | Relevant to store/client filters |
| `backend/api/activate_device.php` | Device activation API | Relevant to app/device setup |
| `backend/api/sync_shifts.php` | Shift sync API | Relevant to timesheet/payroll data |
| `backend/api/init_db.php` | DB setup/init | Do not run/change without explicit confirmation |
| `backend/api/cors_test.php` | CORS test utility | Debug only |
| `backend/api/test_activate.php` | Activation test utility | Debug only |

---

## Source Files Expected / Still Useful

These may still be needed depending on the task:

| File | Type | Rule |
|---|---|---|
| Payroll CSV/PDF references | Test data | Required to validate payroll totals |
| Latest APK/build files | Build output | Only if user requests APK/build debugging |
| `pubspec.yaml` | Flutter dependencies | Needed for package/dependency/build changes |
| Full Flutter project ZIP | Full source backup | Useful for local rebuilds or full inspection |

---

## Files That Must Not Be Guessed

Never recreate these from memory if the real file is not uploaded/accessible:

- `merdpos_staff/lib/main.dart`
- `merdpos_staff/lib/db/local_db.dart`
- `backend/api/get_timesheet.php`
- `backend/api/config.php`
- Payroll calculation/import/sync files
- Login/authentication/device activation files

If missing or inaccessible, say:

```text
I need the latest <filename> before I can safely modify this. I will not guess it from memory.
```

---

## Standard Output Rule

When code is modified, output:

1. Complete replacement file(s).
2. A `.zip` containing all changed files when more than one file is changed.
3. Short changelog.
4. Any test steps needed.
