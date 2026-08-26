# FEATURE_SCOPING_TEMPLATE.md — Screenshot Translation

Use this template when a new feature is requested via screenshot or described for the MERDPOS beta.

> Binding rule: every protected portal feature must comply with `BETA_AUTHORIZATION_STANDARD.md`. A feature is not scoped until its permission, LOA, tenant scope and server enforcement are defined.

## 1. Visual Analysis

Layout: (e.g., Card grid, Data Table, Detail View)

Key UI Elements: (e.g., Floating Action Button, Dropdown filters, Date picker)

User Actions: (e.g., Swipe to delete, Click row to edit)

## 2. Data & Backend Impact

Required Data: (e.g., Product ID, Transaction time, Employee Role)

New Database Tables/Columns needed?: (Yes/No — if Yes, list schemas)

Affects Existing Endpoints?: (e.g., get_timesheet.php needs a new shift_type filter)

Client/store/employee tenant predicates required?:

Sensitive fields returned?: (e.g., pay rate, financial values, credentials — state how they are filtered server-side)

## 3. Authorization / LOA — MANDATORY FOR BETA

Named permission(s): (e.g., `inventory.view`, `inventory.adjust`)

Default minimum LOA for each permission:

DEV-only/non-delegable?: (Yes/No)

Read scope: (own / assigned stores / working store / all client / cross-client DEV only)

Write scope:

Sidebar/panel visibility permission:

Button/action permission:

Dashboard widget visibility + underlying data permission, if applicable:

API route/action registration in `beta_enforce_route_permission()`:

Negative-path checks: (insufficient LOA, direct API request, cross-client/store attempt)

Audit requirement for writes:

## 4. Scoping Questions

Q1: What business capability does this feature grant, and what should its default minimum LOA be?

Q2: Does this data need to be synced offline, or is it strictly live API data?

Q3: Should this be a sidebar item, dashboard widget, action inside an existing screen, or more than one?

Q4: Does any part need to remain permanently DEV-only regardless of numeric LOA?

## 5. Definition of Done

Before the feature can be called complete:

- central permission catalogue updated;
- backend route/action/field/data scope enforced;
- UI mirrors the same permission;
- tenant isolation verified;
- CSRF/input validation/audit applied where relevant;
- dashboard permission binding added where relevant;
- PHP lint passes;
- `validate_portal_permission_policy.php` passes;
- direct insufficient-LOA API request is denied.

Once scope is confirmed, proceed to the implementation. Do not add a protected beta feature using a hard-coded ADMIN/SUPER/DEV role check.
