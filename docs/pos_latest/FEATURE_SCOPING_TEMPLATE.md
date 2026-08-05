# FEATURE_SCOPING_TEMPLATE.md — Screenshot Translation

Use this template when a new feature is requested via screenshot.

1. Visual Analysis
Layout: (e.g., Card grid, Data Table, Detail View)

Key UI Elements: (e.g., Floating Action Button, Dropdown filters, Date picker)

User Actions: (e.g., Swipe to delete, Click row to edit)

2. Data & Backend Impact
Required Data: (e.g., Product ID, Transaction time, Employee Role)

New Database Tables/Columns needed?: (Yes/No - if Yes, list schemas)

Affects Existing Endpoints?: (e.g., get_timesheet.php needs a new shift_type filter)

3. Scoping Questions (To ask the User)
Q1: Should this feature be accessible to all employees, or only admins?

Q2: Does this data need to be synced offline, or is it strictly live API data?

Q3: Should this be a sidebar item or a tile on the dashboard?

Once user confirms this scope, proceed to write the Full Replacement Files.
