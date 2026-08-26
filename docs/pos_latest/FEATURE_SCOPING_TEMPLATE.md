# FEATURE_SCOPING_TEMPLATE.md — Screenshot Translation

Use this template when a new feature is requested via screenshot or described for the MERDPOS beta.

> Binding rules: every protected portal feature must comply with `BETA_AUTHORIZATION_STANDARD.md`, every user-facing touchpoint must comply with `OMNICHANNEL_IDENTITY_STANDARD.md`, and every beta UI must comply with `GUI_STANDARD.md`. A feature is not scoped until its permission, LOA, tenant scope, server enforcement, reliability, usability and visual consistency are defined.

## 1. Visual Analysis

Layout: (e.g., Card grid, Data Table, Detail View)

Key UI Elements: (e.g., Floating Action Button, Dropdown filters, Date picker)

User Actions: (e.g., Swipe to delete, Click row to edit)

### Omnichannel identity review — MANDATORY

Reliability: Is client/store/user context explicit and consistent? Are loading, stale, success and error states clear?

Usability: Is the primary task obvious? Can redundant controls/submenus/context switches be removed?

Trendiness: Does the interface look current and coherent with MERDPOS without adding operational noise?

Cross-touchpoint identity reused: (MERDPOS mark / employee / role+LOA / active client / store ID+code+logo / currency+timezone / freshness)

### GUI standard review — MANDATORY

Spacing scale used: `4 / 8 / 12 / 16 / 20 / 24 / 32`

Form controls: canonical label gap, 42px desktop inputs/selects, shared focus state

Buttons: one primary action per region; standard/compact/destructive hierarchy

Minimal toolbar controls: Dashboard/List Add uses one canonical 46px desktop circular `+`; Search uses the same-diameter circular magnifier; when both exist they are adjacent in a right-aligned `Search + Add` cluster

Shared-component visual equivalence checked against existing reference screen?: Compare width/height, true circle/radius, icon size/stroke, background/border/shadow, relative placement and mobile behavior — matching class/icon names alone is not sufficient

Cards/sections: 14px card radius, 20px desktop padding, consistent sibling gap

Tables: canonical header/body typography, 10x12px cell padding, numeric columns right aligned, horizontal scrolling rather than compression

Responsive behavior: two-column forms collapse intentionally; no decorative empty columns; mobile Add/Search remain 48px touch-safe and adjacent

Feature-local UI exceptions required?: (No by default — document exact reason if Yes)

## 2. Data & Backend Impact

Required Data: (e.g., Product ID, Transaction time, Employee Role)

New Database Tables/Columns needed?: (Yes/No — if Yes, list schemas)

Affects Existing Endpoints?: (e.g., get_timesheet.php needs a new shift_type filter)

Client/store/employee tenant predicates required?:

Sensitive fields returned?: (e.g., pay rate, financial values, credentials — state how they are filtered server-side)

Canonical identity fields reused rather than duplicated?:

Data freshness/staleness behaviour required?:

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

Q5: Which identity dimension matters most for this touchpoint — Reliability, Usability or Trendiness — and why?

## 5. Definition of Done

Before the feature can be called complete:

- central permission catalogue updated;
- backend route/action/field/data scope enforced;
- UI mirrors the same permission;
- tenant isolation verified;
- canonical client/store/user identity reused consistently;
- loading, success, empty, stale and error states handled where applicable;
- primary task is obvious and redundant controls are removed;
- UI follows `GUI_STANDARD.md` including shared spacing, typography, controls, buttons, cards, dialogs, tables and responsive behavior;
- global Add/Search primitives use the canonical shared runtime component rather than feature-local geometry;
- where Search and Add coexist they are adjacent in one right-aligned action cluster;
- shared UI components have been compared for actual visual equivalence across representative screens;
- no helper/body text below the standard readable size;
- no feature-local spacing/control/table system is introduced without a documented exception;
- CSRF/input validation/audit applied where relevant;
- dashboard permission binding added where relevant;
- PHP lint passes;
- `validate_portal_permission_policy.php` passes;
- `validate_beta_runtime_contract.php` passes;
- direct insufficient-LOA API request is denied.

Once scope is confirmed, proceed to the implementation. Do not add a protected beta feature using a hard-coded ADMIN/SUPER/DEV role check, do not add a user-facing feature that bypasses the omnichannel identity standard, and do not ship a feature-local UI grammar that conflicts with `GUI_STANDARD.md`.
