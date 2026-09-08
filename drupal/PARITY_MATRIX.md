# MERDPOS Beta → Drupal Beta Parity Matrix

Updated: 2026-09-08 20:19 +05:00

Scope: migrate current MERDPOS Beta behavior into Drupal Beta while preserving MERDPOS backend authority. **DevStudio/UI Studio is explicitly excluded.** Status is evidence-based; `EXACT` is used only when the Drupal path has been implemented and closure-verified, while `PARTIAL` records a known behavioral delta.

| Area / authoritative Beta capability | Drupal status | Evidence / remaining delta |
| --- | --- | --- |
| Login / identity | EXACT | Drupal `/login` delegates credential verification to authoritative Beta and stores only shadow session identity. |
| Home / role dashboard | EXACT | Signed dashboard data, role/LOA widget filtering, charts, Working Now and attendance QR are implemented and deployment-gated. Mobile containment is live-verified at 390/390 document width while table regions retain internal scrolling. |
| Attendance QR scan | EXACT | Signed `attendance_scan` path with Drupal CSRF; invalid-QR live closure verified without mutation. |
| Operations / HR read surface | EXACT | Signed role-aware operations, staffing, attendance, schedules and management slices are implemented. |
| Disputes | EXACT | Create/cancel/handover/review/flag resolution use signed `disputes` writes and authoritative permissions. |
| Reports / timesheets | EXACT | Weeks, frozen timesheet reconciliation, disputes, charts, filters and authorised CSV export are implemented. |
| Administration: clients/stores/workforce | EXACT | Governed signed writes, DEV client context, client search and onboarding are verified. |
| Administration: store logo | EXACT | Drupal uploads are converted server-side and submitted through the signed `store_logo` gateway; no browser service secret is exposed. |
| Administration: roles | EXACT | Signed `role_authority` workflow is implemented; role/permission thresholds remain backend-owned. |
| Finance read | EXACT | Signed `financials` statement, store/date filters, charts, accounts and ledger detail. |
| Finance writes: open day / Cash IN / Cash OUT / Z report | EXACT | PR #81 merged and Namecheap release `1d30d1aaf810` passed the signed/live deployment gate. MERDPOS remains authoritative for finance permissions, balances, attendance/store scope, idempotency, ledger and outbox behavior. |
| Finance offline queue / reconnect flush | EXACT | Canonical `merdpos_financial_queue_v1` browser queue parity is live: browser UUIDv4 IDs are created before first send and preserved across retries, queued entries affect effective balances, Open Day/Z-report same-day guards are enforced, and reconnect flushes in order through the Drupal-CSRF signed Finance JSON bridge. PR #90 deployed at `39f4007e56dd`; PR #91 refined authoritative rejection HTTP semantics and final release `d1edc1ad0661` passed the full deployment gate. DUMMY DEV Playwright queued four offline submissions with zero network writes, intercepted all four reconnect sends in original UUID order, verified signed invalid-store rejection `422 / Store not found. / retryable=false` with no mutation, and closed desktop/mobile Light/Dark at 1440/1440 and 390/390. |
| Account: Log out | EXACT | Drupal account menu provides logout and returns to MERDPOS login. |
| Dark-theme approved brand assets | EXACT | Canonical full lockup is contrast-preserved on light glass in dark account/login surfaces; canonical multicolor tagline replaces the plain shell subline in dark mode. Source-only Playwright closure passed desktop/mobile Dark on release `53b518c7be00`; assets are not recolored or duplicated into new binaries. |
| Account: Change password (`change_password`) | EXACT | Permission-scoped account-menu modal sends current/new/confirm fields through Drupal CSRF + signed `change_password`; backend password verification/storage/audit stays authoritative. Source-only Playwright closure passed desktop/mobile without submitting or changing a password. |
| Dashboard layout (`dashboard_layout`, non-DevStudio) | EXACT | Drupal renders the authoritative saved role layout and exposes role selection, Edit dashboard, add/remove, desktop drag/resize, mobile up/down ordering, quick templates, search and clear/reset through Drupal CSRF + the signed `dashboard_layout` route. `dev_studio` is rejected and never emitted. PR #85 merged and Namecheap release `f4bb37b3ca24` passed the signed release probe. Reversible DUMMY DEV Playwright closure completed signed no-op saves with 12 saved widgets unchanged on desktop/mobile Light/Dark; mobile measured 390/390. |
| Sheet health (`check_sheet`) | INTERNAL / NO UI GAP | Audit found no Beta button, menu item, page, or browser caller for `check_sheet`; it is a DEV diagnostic endpoint. Drupal intentionally does not invent a user-facing surface for it. |
| Timesheet Google refresh (`timesheet_google_refresh`) | EXACT | PR #88 merged and Namecheap release `8648325634aa` passed the complete Drupal deployment gate after backend Working-client prerequisite release `48ca08b`. The account shell preserves Beta **Working client** / **Sync** labels, canonical restart icon, DEV-only context, destructive confirmation, Drupal CSRF and signed `timesheet_google_refresh` POST. DUMMY DEV Playwright opened and cancelled the confirmation on desktop/mobile Light/Dark; the Drupal sync endpoint request count remained `0` in all four cases, mobile measured 390/390, and no attendance mutation was performed. |
| Legacy migration (`legacy_migration`) | PARTIAL / VALIDATED | Beta user-facing Legacy Sync workflow is implemented in Drupal through a CSRF-protected signed bridge for state, source configuration, Preview, Sync and Final Sync. MERDPOS remains authoritative for Google fetch, staging, snapshot lock, reconciliation, conflicts, SQL mutation and audit; Final Sync requires a fresh authoritative Client Code preflight. Implementation head `f08f0691050f` passes the full PHP 8.4/Twig/JS/UTF-8 validator suite. Live deployment and non-mutating browser closure remain before `EXACT`. |
| Client/default settings (`defaults`) | COVERED / VERIFY EXACTNESS | Default currency/timezone and store/client settings are consumed across current Drupal administration/reporting; audit remaining explicit Beta defaults actions before marking exact. |
| DEV platform diagnostics | EXACT READ / INTENTIONAL BOUNDARY | DEV command centre is read-only; DevStudio/UI Studio remains intentionally excluded. |

## Navigation / brand contract

Drupal must preserve the current MERDPOS names, route intent, approved mark/wordmark/lockup/tagline assets, semantic palette and SVG icon language. Navigation visibility must follow authoritative named permissions where Beta does; local Drupal roles must not hide a page that MERDPOS would permit. The current primary Drupal shell is Home, Admin, Financials and Reports, with DEV in the account menu for DEV actors; route-specific tabs expose Admin sections and Disputes/Reports relationships.

## Closure rule

A row moves to `EXACT` only after source validation, protected-branch promotion, Namecheap deployment, signed live probe, authenticated browser verification, desktop/mobile responsive closure where applicable, Light/Dark regression and continuity evidence. No operational test record is created merely to prove a write path when a non-mutating rejection probe can verify the boundary safely.
