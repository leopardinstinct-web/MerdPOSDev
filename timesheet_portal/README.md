# MERDPOS Portal (Alpha) - PHP + JavaScript

MERDPOS Portal (Alpha) is the existing PHP/JavaScript Timesheet Portal, with a new authenticated Cigarette Orders area. Payroll and the existing CSV-reading timesheet integration remain unchanged.

## What it does

- Uses shared Google Sheet CSV export links. No Google API is used.
- Reads these tabs only:
  - `Time Sheet`
  - `PayRate`
  - `Start Time`
  - `Employee Setup`
- Employee Setup columns:
  - Column A = Employee name
  - Column B = Role
  - Column C = numeric User ID
  - Column D = numeric Password
- If Column B is `SUPER`, that user sees a consolidated report for all employees.
- Normal users see only their own employee-wise report.
- Default view is the current calendar week, Monday-Sunday.
- Previous/Next buttons and a week dropdown are included.
- Wages are visible.

## Upload to hosting

Upload all files and folders inside this folder to your PHP hosting public folder, for example `public_html/timesheet`.

Then open:

`https://yourdomain.com/timesheet/`

## Server requirements

- PHP 7.4 or newer recommended
- PHP sessions enabled
- cURL enabled, or `allow_url_fopen` enabled

## Google Sheet sharing requirement

Because this version does not use the Google API, the Google Sheet must be shared so the server can read CSV exports from the link.

Google Sheet ID is configured in:

`includes/config.php`

Current spreadsheet ID:

`1JyWMrqyRq3nh-uTpaVhd_XNyfeRFKrdQ09xMxRsGOQA`

## Security note

This no-API version is useful for quick deployment, but it is not the most secure long-term approach because the sheet must be readable through a shared link. For payroll and passwords, the stronger version should use a backend-only Google service account or a proper database.

## Read-only rule

This app does not write to Google Sheets. It only fetches CSV data and calculates reports in PHP memory.

## Main files

- `index.php` - numeric login page
- `dashboard.php` - timesheet dashboard
- `api/login.php` - validates login against Employee Setup
- `api/weeks.php` - returns available weeks
- `api/timesheet.php` - generates filtered report data
- `includes/timesheet_logic.php` - rounding, pairing, late flagging, summaries
- `assets/app.js` - frontend rendering and navigation
- `assets/styles.css` - responsive PDF-style design

## Login troubleshooting

If login fails, open:

`/api/check_sheet.php`

It should say `Sheet read OK` and show headers including `NAME, TYPE, USER_ID, PASSWORD`.

Do not open `/api/login.php` directly. It is only for the login form.

## Cigarette Orders — Alpha milestone

Open orders.php from the signed-in navigation. All authenticated users can select MX, ET, DT or RT; the choice persists in the PHP login session. This first milestone does not read Inventory DBA or write orders to Drive. See ORDERING_ALPHA.md for the approved ceiling-based (never sales-based) calculation, the unscanned-as-zero rule and later-stage requirements.
