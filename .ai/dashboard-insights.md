# MERDPOS Beta — Dashboard Insight Widgets

**Introduced:** 2026-08-27  
**Feature commit:** `0227f39777e5ada35f35134a82470165bf514f90`  
**Lifecycle at introduction:** `CODED + WIRED`; do not call `DEPLOYED` or `VERIFIED` until the Namecheap marker and rendered dashboard are checked.

## Purpose

MERDPOS adapts five useful dashboard patterns from the supplied Datadog widget reference without adding a Datadog runtime dependency. The implementation stays inside the existing role-dashboard architecture and its permission contract.

1. **Change KPIs** — `sales_change` and `attendance_change` compare the latest business date with the previous date and show direction, relative change, and absolute change.
2. **Timeseries** — `sales_trend_7d` and `attendance_trend_7d` render seven-day SVG trends using existing MERDPOS design tokens and no external chart library.
3. **Top list** — `top_stores_sales` ranks active stores by the current business date's completed retail sales.
4. **Conditional-status table** — `sync_status_table` groups outbox exceptions into failed, processing, and pending states and uses semantic danger/warning/info surfaces.
5. **Reusable groups/templates** — the widget drawer exposes additive `Store operations`, `Finance`, and `Workforce` quick templates. A template adds only missing widgets that the selected role is actually allowed to use; it never expands permissions or silently removes existing widgets.

## Security and authorization contract

Every new widget has two explicit permission gates in `backend/api/includes/portal_permissions.php`:

`widget visibility permission + underlying data permission`

Finance insight widgets require `finance.management_summary`; workforce/attendance insight widgets require `workforce.view`; sync status requires `system.sync_status`. `dashboard_data.php` queries each analytics dataset only when both the selected role and its allowed-widget set authorize that dataset. DEV role preview therefore remains scoped to the previewed role rather than the actor's wider DEV access.

The permission validator now also fails when a permission-registered dashboard widget has no matching frontend widget definition. This is a stable wiring contract, not a cosmetic layout assertion.

## Data contracts

`dashboard_data.php` zero-fills seven business dates for the sales and attendance series. Existing current-day sales-by-store data is reused by the Top Stores widget. Sync status counts are grouped only for `pending`, `processing`, and `failed` outbox states.

The implementation deliberately does not invent business thresholds for cash variance or attendance quality. Attendance direction is visually neutral because a higher or lower clock-in count is contextual rather than inherently good or bad.

## Product-stage rule

These widgets are available through the existing role-aware picker and quick templates; existing seeded role dashboards are not forcibly rewritten. This preserves the current active-design workflow: add useful capabilities without freezing dashboard composition as a permanent UI contract.

After deployment, visually inspect the new widgets and quick-template drawer at representative desktop and mobile widths before promoting the feature beyond `WIRED`.