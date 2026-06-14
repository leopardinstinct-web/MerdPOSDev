# API Contract — POS LATEST / MerdPOS

This file records the known and expected backend API behavior. It must be updated whenever the API changes.

---

## Confirmed Backend Folder

The connected Google Drive source includes:

```text
backend/api/
```

---

## Confirmed API Files

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

---

## Timesheet API

Confirmed file:

```text
backend/api/get_timesheet.php
```

Known version labels/errors seen during development:

```text
payroll-rounded-v5-legacy-gas-match
payroll-rounded-v6-db-compat-legacy-gas-match
```

Expected app-facing label mentioned in the project:

```text
API Timesheet v2
```

Before changing `main.dart` or `get_timesheet.php`, inspect the current actual contents of:

```text
merdpos_staff/lib/main.dart
backend/api/get_timesheet.php
backend/api/config.php
```

---

## Database Connection Requirement

Confirmed config file exists:

```text
backend/api/config.php
```

The API must use the existing backend database connection. Previous compatibility attempts expected one of:

```php
$pdo
$conn
$mysqli
$db
```

Or common include files/constants such as:

```php
config.php
db.php
DB_HOST
DB_NAME
DB_USER
DB_PASS
```

If the API cannot find the database connection, it may return an error similar to:

```json
{
  "success": false,
  "api": "get_timesheet.php",
  "version": "payroll-rounded-v6-db-compat-legacy-gas-match",
  "error": "Database connection not found."
}
```

---

## Timesheet API Expected Response Shape

This must still be verified against the live/current API before final coding.

Likely response shape:

```json
{
  "success": true,
  "week_start": "YYYY-MM-DD",
  "week_end": "YYYY-MM-DD",
  "rules": {
    "max_counted_shift_hours": 16,
    "long_shift_action": "marked_needs_review_not_counted_in_total"
  },
  "scope": {
    "client_id": 1,
    "store_id": 1,
    "employee_id": null
  },
  "summary": {
    "employee_count": 0,
    "total_counted_minutes": 0,
    "total_counted_hours": 0,
    "total_review_minutes": 0,
    "total_payable_hours": 0,
    "total_wage": 0
  },
  "employees": []
}
```

---

## Fields That Must Be Verified

Before final coding, confirm from actual API response:

- `success`
- `api`
- `version`
- `week_start`
- `week_end`
- `rules`
- `scope`
- `summary`
- `employees`
- Employee ID field name
- Employee name field name
- Shift date field name
- Start/end time field names
- Break fields, if any
- Counted/payable hours fields
- Review/exception fields
- Wage/rate fields

---

## Related APIs To Inspect When Needed

### Employees
Likely file:

```text
backend/api/get_employees.php
```

Use when debugging:

- Employee list
- Employee ID mapping
- Numeric `USER_ID`
- Employee filter in timesheet

### Stores
Likely file:

```text
backend/api/get_stores.php
```

Use when debugging:

- Store filter
- Client/store IDs
- Wrong or empty store scope

### Device Activation
Likely files:

```text
backend/api/activate_device.php
backend/api/test_activate.php
```

Use when debugging:

- App activation
- Device setup
- Device token/registration

### Shift / Timesheet Sync and Import
Likely files:

```text
backend/api/sync_shifts.php
backend/api/import_timesheet_logs.php
backend/api/sync_employee_logs.php
backend/api/init_employee_logs.php
backend/api/import_actual_employees.php
```

Use when debugging:

- Missing shifts
- Imported timesheet logs
- Employee logs
- Sync mismatch
- Payroll totals mismatch

---

## Debugging Rule

When the app shows no timesheet data:

1. Test the API directly in browser/Postman.
2. Save the raw JSON response.
3. Check whether `success` is true.
4. Check whether `employees` or equivalent data array exists.
5. Check whether the app parser expects a different field name.
6. Check whether API returned database connection error.
7. Check whether store/client/employee filters are too restrictive.
8. Inspect `backend/api/config.php`.
9. Inspect `backend/api/get_timesheet.php`.
10. Inspect `merdpos_staff/lib/main.dart`.
11. Only then change app/API code.

---

## Do Not Assume

Do not assume:

- API URL.
- Database schema.
- Employee table names.
- Timesheet table names.
- Payroll rounding rules.
- Whether response uses `employees`, `data`, `rows`, or another key.
- That `config.php` exposes `$pdo`, `$conn`, `$mysqli`, or `$db` until inspected.
