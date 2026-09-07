# MERDPOS Beta Scope

**Status:** binding current-scope guide for Beta planning and "what next?" decisions.

## Current Beta product

MERDPOS Beta is the web operations/timesheet product around Drupal plus the authoritative PHP/MySQL Beta services. Current Beta work includes attendance, disputes, timesheets/reports, administration, workforce/stores/clients, approved finance workflows, migration/reconciliation, permissions/audit and release readiness.

The Drupal presentation branch is `beta/drupal-webapp`. The authoritative Beta backend/runtime branch remains `namecheap-beta-live`.

## Explicitly outside the current Beta queue

The older Flutter/full-POS product roadmap is a separate track. In particular, `docs/pos_latest/`, M3.x, barcode POS, basket/checkout, tender, receipts, POS sale synchronization and **M3.3 Checkout & Tender** are not current Beta milestones.

Do not use those documents to answer "what is next?" for the current Beta, and do not resume that roadmap unless the product owner explicitly reopens full-POS work.

## Current ordered Beta queue

1. **Attendance QR end-to-end — VERIFIED 2026-09-07:** DUMMY QR IN/OUT, Current Shift, Working Now and protections passed live.
2. **Attendance → Dispute end-to-end — VERIFIED 2026-09-07:** POS handover, employee confirmation and SUPER approval passed live.
3. **Timesheet reconciliation — VERIFIED 2026-09-07:** attendance reached Reports with frozen payroll rules unchanged, including Sydney/UTC week-boundary reconciliation.
4. **Administration acceptance — CURRENT:** Clients, Stores and Workforce CRUD, store profile/hours/logo, role/LOA boundaries and UI regressions.
5. **Finance Beta acceptance:** only approved Beta finance workflows and validations; no retail checkout/tender expansion.
6. **Migration / production readiness:** Google/legacy reconciliation, audit, permissions, DUMMY cleanup and deployment checks.
7. **Beta acceptance/freeze:** role-by-role USER/SUPER/ADMIN/DEV acceptance and feature-scope freeze.

When a current active work packet exists, its `next_action` controls execution inside this queue. Historical full-POS documents remain valid historical/product-track records but cannot supersede this Beta queue.
