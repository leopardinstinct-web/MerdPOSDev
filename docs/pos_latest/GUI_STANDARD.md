# MERDPOS Beta GUI Standard

**Status:** Binding for the current `namecheap-beta-live` portal and every future beta UI change.

This standard exists to prevent feature modules from inventing their own visual grammar. Feature-specific styling is allowed only where it expresses domain meaning. Geometry, spacing, typography, controls, tables, dialogs, status treatment and responsive behavior are shared system concerns.

## 1. Design priority

Every screen is reviewed in this order:

1. **Reliability** — context, state, values and consequences must be trustworthy and visually unambiguous.
2. **Usability** — the primary task must be obvious, efficient and accessible.
3. **Restrained trendiness** — modern presentation is welcome only when it does not reduce operational clarity.

The MERDPOS dark navigation rail and light working surfaces remain the product identity. Do not clone another product's branding or ornamental style.

## 2. Spacing

Use only the shared spacing scale for layout:

```text
4, 8, 12, 16, 20, 24, 32 px
```

Rules:

- 6px is the standard label-to-control gap.
- 16px is the standard gap between related form fields.
- 20px is the normal card/section internal padding on desktop.
- 16px card padding is allowed on narrow mobile layouts.
- 16px is the default gap between sibling cards/sections.
- Do not use blank grid cells or empty elements to create visual spacing.
- Do not add arbitrary `margin-top`/`margin-bottom` values when a parent `gap` can express the relationship.

## 3. Typography

Canonical portal scale:

```text
Page heading       28px / 720
Section heading    20px / 680
Subsection title   16px / 680
Body               14px / 400–500
Form label         13px / 620
Helper/caption     12px / 400
Table header       11.5px / 680
Table cell         13px / 400–600
```

Rules:

- Operational/helper text must not be smaller than 12px on desktop.
- Do not use all-uppercase body copy. Uppercase is reserved for short system labels/kickers.
- Use tabular numerals for counts, money, dates and migration metrics where alignment matters.
- Headings describe the task or information, not internal implementation jargon.

## 4. Controls

Canonical desktop controls:

```text
Input/select        42px minimum height
Standard button     40px minimum height
Compact button      36px minimum height
Operational touch   48px minimum target where touch/scanner use is expected
Control radius      10px
```

Rules:

- Every input has a persistent visible label.
- A label owns its input; helper text follows the input within the same field group.
- Textareas use the same border, radius, focus treatment and typography as inputs.
- Disabled controls must look disabled and remain non-interactive.
- Keyboard focus must be visible.

## 5. Minimal toolbar controls — BINDING

Administrative list/toolbars use minimal controls to reduce clutter.

### Add actions

All top-level create actions use one circular `+` button only:

- Add Store
- Add Employee
- Add Client
- Add Role
- equivalent future top-level `Add ...` actions

The visible control is the `+` icon only. The full action name MUST remain available through `aria-label` and `title`/tooltip, for example `aria-label="Add employee"`.

Desktop target: 40×40px. Mobile/tablet target: 48×48px.

Do not render `+ Add employee`, `+ Add store`, etc. as permanent text buttons in list headers.

### Search

Search controls begin as a circular magnifier icon only. Clicking/tapping the icon expands the search input. Rules:

- empty + unfocused search collapses back to the circle;
- a non-empty search remains expanded so the active filter is visible;
- `Escape` clears/collapses the search;
- mobile expansion uses the available toolbar width;
- search must remain keyboard accessible and keep an accessible label.

Feature modules MUST use the shared minimal-control behavior rather than creating permanently expanded search bars unless the page's primary purpose is search itself.

Runtime implementation:

- `assets/minimal-controls.css`
- `assets/minimal-controls.js`

## 6. Buttons and actions

- Use **one primary action per action region**.
- Secondary actions use the standard secondary style.
- Destructive/irreversible actions must be visually and textually explicit.
- Do not make a configuration/save action visually larger than the task requires.
- Transactional buttons use clear verbs such as `Save sources`, `Preview changes`, `Sync legacy data`.
- The circular `+` and magnifier are explicit exceptions to text-button labeling because they are standardized global controls; accessible labels are mandatory.
- Other icon-only actions require a universally recognizable icon plus an accessible label/tooltip.

## 7. Cards and sections

```text
Card radius         14px
Dialog radius       20px
Desktop padding     20px
Mobile padding      16px
```

- Use borders/shadows sparingly. One visual boundary is normally enough.
- A section starts with heading → optional explanatory copy → content/actions.
- Explanatory copy should sit close to its heading, not halfway to the next field.
- Nested cards should be used only when the hierarchy is real; avoid card-inside-card decoration.

## 8. Tables

Canonical administrative table:

- header height about 38px;
- header text 11.5px/680;
- body text 13px;
- row/cell vertical padding about 10px;
- horizontal cell padding 12px;
- numeric columns right aligned;
- status values use text + badge/color;
- tables horizontally scroll rather than compressing content into unreadable text;
- sticky headers may be used for long data sets;
- empty state occupies the table body and explains what is missing.

Do not create a feature-local table density or tiny 8–10px data text.

## 9. Dialogs

Every dialog requires:

- explicit title;
- short consequence/context line where needed;
- a visible close/cancel route;
- consistent body padding;
- actions grouped at the end;
- background scroll lock;
- destructive consequences stated before confirmation.

Long workflows should use sections inside one task-oriented dialog rather than uncontrolled whitespace.

## 10. Status, validation and feedback

- Never rely on color alone.
- Success, warning, conflict, rejected and failed states must include text.
- Validation belongs next to the affected control/section where possible.
- Long-running actions show a disabled/loading state and cannot be double-submitted.
- For migrations/imports, totals without reasons are insufficient. Rejected/conflict counts must be drillable or summarized by reason.

## 11. Responsive/mobile behavior — BINDING

Every beta feature is mobile-ready at implementation time, not as a later cleanup task.

- Two-column forms collapse to one column below the shared mobile breakpoint.
- No decorative empty columns survive responsive collapse.
- All interactive targets are at least 48px on tablet/phone.
- Inputs use at least 16px text on phone to prevent browser zoom and preserve readability.
- Dialogs fit inside the dynamic viewport and keep primary/cancel actions reachable.
- Tables scroll horizontally inside their own container rather than forcing page-level horizontal scrolling.
- No control, toolbar or card may be clipped outside the viewport.
- Expanded search uses available width and the `+` control remains reachable.
- Buttons may become full-width on narrow screens when this improves touch use.
- Safe-area inset must be respected for sticky mobile dialog actions.
- Reduced-motion preferences are respected.

## 12. Feature CSS rule

Feature modules may define domain-specific classes, but MUST NOT redefine shared fundamentals without an explicit reason:

- base font sizes;
- global input/button heights;
- general card radius/padding;
- table density;
- focus ring behavior;
- modal geometry;
- global spacing scale;
- Add/search toolbar interaction.

The runtime `assets/ui-standard.css` and minimal-control layer are intentionally loaded after feature CSS. If a feature needs an exception, document the reason in the feature scope rather than using escalating `!important` rules to fight the standard.

## 13. Definition of done — UI

A beta UI change is not complete until all of the following are checked:

- shared spacing scale used;
- persistent field labels used;
- no helper/body text below the minimum readable size;
- controls use canonical heights/radii;
- top-level Add action uses the standard circular `+` where applicable;
- list search uses the standard collapsed magnifier where applicable;
- primary/secondary/destructive hierarchy is correct;
- tables use canonical density and numeric alignment;
- loading/empty/error/success states exist;
- mobile collapse is intentional and tested conceptually at phone/tablet widths;
- 48px mobile touch targets are preserved;
- keyboard focus works;
- no hidden overflow prevents required data access;
- permission/LOA visibility follows `BETA_AUTHORIZATION_STANDARD.md`;
- reliability/usability review follows `OMNICHANNEL_IDENTITY_STANDARD.md`;
- implementation visually checked against at least one existing MERDPOS screen before deployment.

## 14. Current implementation

`namecheap_beta_live/timesheet_portal/assets/ui-standard.css` is the binding CSS enforcement layer for the web beta. `assets/minimal-controls.css` and `assets/minimal-controls.js` enforce the shared `+` and expandable-search interaction. They are loaded after feature and experience CSS by `management.js` so current modules are normalized as well as future modules.
