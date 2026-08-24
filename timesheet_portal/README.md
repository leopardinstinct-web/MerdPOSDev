# Timesheet Portal - PHP + JS

The workforce/financial beta extends this same responsive design with QR attendance, live working staff, a simple employee-to-SUPER dispute queue, offline financial drafts and secure password change. Transactional writes use SQL and mirror to the existing Google Sheets through the durable outbox documented in `docs/pos_latest/BETA_WORKFORCE_FINANCIALS.md`.

This is a PHP + JavaScript employee portal. Historical weekly reports still read the current Sheet CSV shape during the beta transition; authentication and all new transactional actions use SQL.

## What it does

- Reads historical reporting data from these tabs:
  - `Time Sheet`
  - `PayRate`
  - `Start Time`
  - `Employee Setup`
- Login uses active SQL employee accounts and hashed passwords.
- SUPER users see consolidated reports, live staff and approval queues.
- Normal users see only their own employee-wise report.
- QR scanning automatically toggles IN/OUT and rejects replay.
- POS handover disputes require the previous employee's confirmation before they enter the SUPER queue.
- Pending disputes never change `Time Sheet`; SUPER approval applies the change.
- Financial drafts survive phone disconnection and wait for a server receipt. SQL prevents negative Register/Petty Cash balances and duplicate closing.
- Default view is the current calendar week, Monday-Sunday.
- Previous/Next buttons and a week dropdown are included.
- Wages are visible.

## Upload to hosting

Upload all files and folders inside this folder to your PHP hosting public folder, for example `public_html/timesheet`.

Then open:

`https://yourdomain.com/timesheet/`

## Server requirements

- PHP 8.1 or newer
- PDO MySQL and Sodium extensions
- PHP sessions enabled
- cURL enabled, or `allow_url_fopen` enabled

## Transitional Google Sheet read requirement

Historical reporting currently uses CSV exports. The cutover plan must remove legacy password values from `Employee Setup`; password authentication no longer uses Sheet data.

Google Sheet ID is configured in:

`includes/config.php`

Current spreadsheet ID:

`1JyWMrqyRq3nh-uTpaVhd_XNyfeRFKrdQ09xMxRsGOQA`

## Write path

Portal writes commit to SQL first. A cron worker sends signed, idempotent events to the dedicated Apps Script bridge, which mirrors approved attendance and financial data into the existing worksheets.

## Main files

- `index.php` - numeric login page
- `dashboard.php` - timesheet dashboard
- `api/login.php` - validates a hashed SQL employee credential with rate limiting
- `api/attendance_scan.php` - validates and consumes a signed POS QR
- `api/disputes.php` - employee proposals/cancellation and SUPER decisions
- `api/financials.php` - idempotent financial receipts
- `api/change_password.php` - current-password verified password change
- `api/weeks.php` - returns available weeks
- `api/timesheet.php` - generates filtered report data
- `includes/timesheet_logic.php` - rounding, pairing, late flagging, summaries
- `assets/app.js` - frontend rendering and navigation
- `assets/styles.css` - responsive PDF-style design

Do not open API files directly. They are session-protected portal endpoints.
