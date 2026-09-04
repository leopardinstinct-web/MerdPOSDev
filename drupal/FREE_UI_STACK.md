# MERDPOS Drupal Free UI Stack

All packages in this stack are free/open-source Drupal projects installed through Composer. MERDPOS retains its Git-owned operational theme and canonical Beta icon language; contributed modules extend capabilities rather than replacing MERDPOS design or business rules.

## Selected runtime stack

| Project | Locked line | MERDPOS use |
| --- | --- | --- |
| Dashboard | 2.2.x | Reusable, permission-aware dashboard infrastructure for future configurable management widgets. |
| Charts | 5.2.x | Drupal-native chart/block/Views integrations for richer analytics without inventing financial or payroll calculations. |
| UI Patterns | 2.0.x | Reusable SDC-backed components and design-system patterns. |
| UI Icons | 2.0.x | Supplemental Drupal Icon API ecosystem; canonical MERDPOS SVGs remain primary. |
| Gin | 5.0.x | Modern Drupal administration theme only; it does not replace the MERDPOS app shell. |
| Gin Toolbar | 3.0.x | Improved Drupal administration navigation. |
| Better Exposed Filters | 7.1.x | Better filter UX for future Views-based reporting surfaces. |

The deployment enables the six contributed modules needed at runtime and sets Gin as the Drupal administration theme. Release verification fails if the expected free UI stack is missing.

## Evaluated but deferred

- UI Styles and Layout Builder Styles: useful if MERDPOS later exposes controlled Layout Builder/editor workflows; unnecessary for the current operational shell.
- Views Bulk Operations: valuable only after explicit write-parity authorization; deliberately excluded from the read-first runtime.
- ECA: powerful event/condition/action automation, but would broaden behavior beyond this UI/authentication milestone.
- Paragraphs: useful for structured editorial content, not needed for the operational application.
- SDC Devel: useful developer aid, not a production dependency.
- Gin Login: not used because MERDPOS requires its approved branded login graphics and authoritative MERDPOS identity flow.
- Views Infinite Scroll: deferred for operational tables in favor of predictable pagination/filtering.
- DataTables: rejected for this runtime because its stable Drupal project line does not currently target Drupal 11.
