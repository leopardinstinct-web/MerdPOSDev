# QR Workforce and Financials Beta

Status: source on `beta/qr-attendance-disputes-financials`. Not migrated or deployed.

## Outcome

This beta replaces the POS WebViewer's always-online attendance and financial dependency with:

- an offline Android QR display using a device-held Ed25519 private key;
- the responsive `timesheet_portal/` for phone and desktop;
- a minimal SQL transaction core for concurrency, approval and idempotency;
- the existing Google Sheets as the operational/reporting mirror;
- a signed Apps Script bridge and retryable SQL outbox.

The existing Timesheet Portal navy, blue, green, white, pale-blue, border, shadow and typography system is extended rather than redesigned. Existing payroll rounding remains nearest 15 minutes.

## Authoritative data

| Concern | Authority | Google Sheet result |
|---|---|---|
| Active QR shift | SQL `attendance_shifts` | `Time Sheet` IN/OUT and `Employee Setup.LOG_STORE` |
| QR replay result | SQL `attendance_qr_uses` | No duplicate row |
| Dispute workflow | SQL `attendance_disputes` | `Shift Disputes` audit; approved change only |
| Simultaneous different-store scan | SQL `attendance_account_flags` | `Attendance Security Flags` audit |
| Financial day and balance | SQL `financial_day_accounts` / `financial_ledger_entries` | Existing General Ledger / zReport Ledger |
| Financial receipt | SQL `financial_submissions` | Event-ID-backed Sheet delivery |
| Sheet delivery | SQL `google_sheet_outbox` | `Beta Sync Ledger` receipt |

SQL is authoritative because a spreadsheet cannot safely enforce one-open-shift, replay protection, approval transactions, or idempotent financial submission under concurrency.

## Simple user workflow

### Attendance

1. Scan the current POS QR.
2. Log in if needed.
3. See one receipt: `IN` or `OUT`.

Reopening the same QR returns the same receipt. A QR from another store while a shift is open suspends attendance access for SUPER review. A missing OUT remains missing; the system does not invent a time.

### Disputes

The employee selects a shift and one plain-language issue: forgot OUT, wrong IN, wrong OUT, delete shift, add missing shift, or other. Only relevant time/store fields appear. Submit sends it to one SUPER queue. The employee can cancel while pending. SUPER sees only Approve or Reject.

No `Time Sheet` change occurs while pending. Approved additions append IN/OUT. Approved deletions record a durable tombstone before matched Sheet events are removed. Rejected/cancelled requests remain in SQL audit history.

### Financials

The Financials screen retains the existing three-section structure: Daily Cash Statement, Cash In / Out and Daily Cash Closing. A SUPER user opens the first cutover day with confirmed Register and Petty Cash balances. Later closings automatically create the next day's openings.

The server calculates `available = opening + confirmed IN - confirmed OUT` under a database row lock. Cash OUT is rejected if it would make Register or Petty Cash negative, including two simultaneous submissions. Closing is accepted once, rejects inconsistent totals, records the transfer to Petty Cash, and atomically opens the next business day. The browser shows the same available amounts but remains a convenience check; SQL is the final authority.

The phone retains submissions while offline. Network failures remain queued; an explicit server rejection is shown and is not retried forever. Non-SUPER users must be clocked in at that store. Sheet delivery retries independently in the background.

### POS user handover

After a second employee authenticates on a POS, the app asks only:

- `Add as additional user`; or
- `Previous user forgot`.

The second choice replaces the visible POS user. If the previous employee has an open QR attendance shift at that store, a `missing_out` dispute first appears only to that employee as `awaiting_employee`. They choose `Confirm & send` or `Not correct`. Only confirmation changes it to `pending` and exposes it to SUPER for final approval. It never silently writes an OUT to `Time Sheet`. The report is kept locally if the POS is offline and retries later.

## Passwords

- Portal login and password change use SQL `password_hash()` / `password_verify()`.
- A change requires current password, CSRF validation and 6–20 numeric digits.
- The session and CSRF token rotate afterward.
- New plaintext passwords are never written to Google Sheets.
- At cutover, remove legacy plaintext passwords from `Employee Setup` and retire the legacy Apps Script login. Never run the destructive `import_actual_employees.php` during cutover.

## Gated deployment order

1. Back up the database and both Google Sheets.
2. Apply `backend/sql/021_workforce_financial_beta.sql` in staging and run its verification query.
3. Confirm migrations 012 and 015 exist for login lockout and security audit.
4. Deploy `apps_script/workforce_financial_beta/` as a new web app, not over the legacy project.
5. Set a random 32-byte-or-longer `SYNC_SECRET` in Apps Script Properties.
6. Set `MERD_SHEETS_SYNC_URL` and `MERD_SHEETS_SYNC_SECRET` only in the backend cron environment.
7. Deploy backend and portal source; keep `backend/api/config.php` uncommitted.
8. Run `php backend/cli/sync_google_sheets.php 25` every minute.
9. Provision one authorised display device and discard the activation token from the form.
10. Seed/open the cutover financial day with reconciled Sheet balances, then test IN, duplicate scan, OUT, missing OUT, POS handover, correction, new shift, deletion, cancellation, rejection, suspension/reactivation, password change, zero-balance Cash OUT rejection, concurrent Cash OUT and Z report.
11. Reconcile SQL receipts against Sheet event IDs before wider rollout.
12. After cutover is verified, remove legacy Sheet passwords and anonymous legacy authentication.

## Rollback

- Stop the outbox cron and disable the new Apps Script deployment.
- Roll back portal/backend code.
- Retain the new SQL tables and Sheet audit tabs; deletion would destroy evidence.
- Do not reverse Sheet events automatically. Reconcile their event IDs against `google_sheet_outbox` first.
