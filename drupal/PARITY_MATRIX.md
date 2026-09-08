# MERDPOS Beta → Drupal Beta Parity Matrix

Updated: 2026-09-08 15:05 +05:00

Scope: migrate current MERDPOS Beta behavior into Drupal Beta while preserving MERDPOS backend authority. **DevStudio/UI Studio is explicitly excluded.** Status is evidence-based; `EXACT` is used only when the Drupal path has been implemented and closure-verified, while `PARTIAL` records a known behavioral delta.

| Area / authoritative Beta capability | Drupal status | Evidence / remaining delta |
| --- | --- | --- |
| Login / identity | EXACT | Drupal `/login` delegates credential verification to authoritative Beta and stores only shadow session identity. |
| Home / role dashboard | EXACT | Signed dashboard data, role/LOA widget filtering, charts, Working Now and attendance QR are implemented and deployment-gated. |
| Attendance QR scan | EXACT | Signed `attendance_scan` path with Drupal CSRF; invalid-QR live closure verified without mutation. |
| Operations / HR read surface | EXACT | Signed role-aware operations, staffing, attendance, schedules and management slices are implemented. |
| Disputes | EXACT | Create/cancel/handover/review/flag resolution use signed `disputes` writes and authoritative permissions. |
| Reports / timesheets | EXACT | Weeks, frozen timesheet reconciliation, disputes, charts, filters and authorised CSV export are implemented. |
| Administration: clients/stores/workforce | EXACT | Governed signed writes, DEV client context, client search and onboarding are verified. |
| Administration: store logo | EXACT | Drupal uploads are converted server-side and submitted through the signed `store_logo` gateway; no browser service secret is exposed. |
| Administration: roles | EXACT | Signed `role_authority` workflow is implemented; role/permission thresholds remain backend-owned. |
| Finance read | EXACT | Signed `financials` statement, store/date filters, charts, accounts and ledger detail. |
| Finance writes: open day / Cash IN / Cash OUT / Z report | VALIDATED | Implemented on `milestone/drupal-finance-write-parity-v1`; uses signed `financials` POST, named permissions and Drupal CSRF. Pending merge/deploy/live closure. |
| Finance offline queue / reconnect flush | PARTIAL | Beta queues financial submissions in browser localStorage while offline; Drupal write forms are synchronous. Must be added before claiming exact offline Finance parity. |
| Account: Log out | EXACT | Drupal account menu provides logout and returns to MERDPOS login. |
| Account: Change password (`change_password`) | MISSING | Beta exposes own-password change (`password.change_own`, current password, 6–20 digit new password). Drupal account menu currently has no equivalent. Next parity block. |
| Dashboard layout (`dashboard_layout`, non-DevStudio) | MISSING / NEEDS AUDIT | Gateway supports GET/POST and blocks `dev_studio`; Drupal does not yet expose the normal role dashboard-layout workflow. Must distinguish normal layout personalization from excluded Studio behavior. |
| Sheet health (`check_sheet`) | MISSING / NEEDS AUDIT | Gateway supports GET; no Drupal action/surface currently references it. Audit Beta visibility/permission and reproduce only user-facing behavior. |
| Timesheet Google refresh (`timesheet_google_refresh`) | MISSING / NEEDS AUDIT | Gateway supports POST; no Drupal equivalent currently references it. Audit Beta UI/permission before implementation. |
| Legacy migration (`legacy_migration`) | MISSING / NEEDS AUDIT | Gateway supports GET/POST; no Drupal surface currently references it. DEV-only operational migration is not DevStudio and requires parity review. |
| Client/default settings (`defaults`) | COVERED / VERIFY EXACTNESS | Default currency/timezone and store/client settings are consumed across current Drupal administration/reporting; audit remaining explicit Beta defaults actions before marking exact. |
| DEV platform diagnostics | EXACT READ / INTENTIONAL BOUNDARY | DEV command centre is read-only; DevStudio/UI Studio remains intentionally excluded. |

## Navigation / brand contract

Drupal must preserve the current MERDPOS names, route intent, approved mark/wordmark assets, semantic palette and SVG icon language. Navigation visibility must follow authoritative named permissions where Beta does; local Drupal roles must not hide a page that MERDPOS would permit. The current primary Drupal shell is Home, Admin, Financials and Reports, with DEV in the account menu for DEV actors; route-specific tabs expose Admin sections and Disputes/Reports relationships.

## Closure rule

A row moves to `EXACT` only after source validation, protected-branch promotion, Namecheap deployment, signed live probe, authenticated browser verification, desktop/mobile responsive closure where applicable, Light/Dark regression and continuity evidence. No operational test record is created merely to prove a write path when a non-mutating rejection probe can verify the boundary safely.
