# Android POS App Requirements

## Project Name
POS LATEST / MerdPOS Android App

## Main Goal
Build and maintain an Android app that connects to the POS/backend system and shows required store/staff/timesheet/payroll information correctly.

---

## Confirmed Source Context

Connected Google Drive source folder:

```text
MerdPOSDev/
```

Important app/backend files now confirmed:

```text
merdpos_staff/lib/main.dart
merdpos_staff/lib/db/local_db.dart
backend/api/get_timesheet.php
backend/api/config.php
```

Before changing requirements into code, inspect the actual contents of these files.

---

## Core Requirements

### 1. Authentication
- Employee login must support numeric credentials.
- `USER_ID` is numeric.
- `PASSWORD` is numeric.
- Do not assume email login.

### 2. Timesheet Display
The app should display timesheet data from the backend API.

Expected behavior:
- App calls the correct timesheet API.
- App shows the correct API version label when required.
- App displays employee timesheet records.
- App handles empty data clearly.
- App handles API errors clearly.

### 3. Payroll Accuracy
Payroll calculations and displayed totals must match the actual payroll reference generated from CSV/PDF.

Important:
- Do not change rounding/payroll logic without checking the reference output.
- If totals do not match, debug using actual API response and source payroll file.

### 4. API Version Control
The app must not silently fall back to an old API or old code path.

Known required/expected label:
- `API Timesheet v2`

### 5. Error Handling
The app should clearly show:
- API connection failure.
- Empty response.
- Invalid JSON.
- Missing timesheet data.
- Backend database connection error.
- Authentication failure.

### 6. File Delivery Rule
Every code change must be delivered as:
- Complete replacement file(s).
- Downloadable files.
- Short changelog.

Snippets are not enough unless the user explicitly asks for snippets.

---

## Non-Functional Requirements

### Must Be
- Stable.
- Easy to debug.
- Clear API status shown in app or logs.
- Compatible with the existing backend.
- Written without guessing previous code.

### Must Avoid
- Replacing latest working code with old code.
- Creating fake endpoints.
- Assuming database schema.
- Assuming login method.
- Changing payroll logic without evidence.
- Editing generated folders such as `build/` or `.dart_tool/` unless specifically needed.

---

## Pending Requirement Areas
These should be confirmed from the latest code/API files before implementation:

1. Exact base API URL.
2. Exact login endpoint.
3. Exact timesheet endpoint.
4. Exact request parameters for `get_timesheet.php`.
5. Exact database connection structure from `config.php`.
6. Exact payroll rounding rule currently required.
7. Required screens beyond login/timesheet.
8. Whether device activation is required through `activate_device.php`.
9. Whether employee/store lists come from `get_employees.php` and `get_stores.php`.
