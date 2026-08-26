# MERDPOS Beta — Visual Consistency & Universal Design Audit

Date: 2026-08-26
Branch: `beta-ui-system-refactor-20260826`
Scope: `namecheap_beta_live/timesheet_portal/` only

## Critical inconsistencies found

1. **Search/Add vertical misalignment from inherited label margin**
   - Affected: Employee and Store directory toolbars.
   - Cause: legacy `styles.css` applies top/bottom margins to every `label`; directory Search is itself a `<label>`. When `minimal-controls.js` converts it into the circular Search primitive, the inherited margin can remain while the adjacent Add button has no equivalent margin.
   - Fix: `universal-design.css` explicitly resets Search wrapper margins and enforces one shared action cluster geometry.

2. **Binding control dimensions drifted from GUI standard**
   - Standard: input/select 42px, standard button 40px, compact button 36px, Search/Add 46px desktop; 48px touch on tablet/phone.
   - Previous token layer used a shared 44px control value, so inputs and buttons could not simultaneously comply with the beta standard.
   - Fix: `design-tokens.css` now exposes separate input/button/compact/touch primitives and the final normalization layer consumes them consistently.

3. **Mobile runtime and mobile CSS were separated**
   - `mobile-runtime.js` adds keyboard/viewport state such as `body.merd-keyboard-open`.
   - The CSS rules that hide fixed mobile navigation while the software keyboard is open live in `mobile-hardening.css`, but that stylesheet was not loaded by `management.js`.
   - Fix: `management.js` now loads `mobile-hardening.css` whenever the mobile runtime is loaded.

4. **Too many competing visual sources of truth**
   - Legacy feature CSS and newer design-system files define overlapping geometry, typography and button/table behavior.
   - Fix: `design-tokens.css` is the numeric/semantic source of truth and `universal-design.css` is the final shared normalization layer. Feature CSS remains responsible only for feature composition.

5. **Runtime audit did not verify the binding geometry contract**
   - Existing audit covered headings, contrast, overflow and basic mobile touch size, but not exact Search/Add equivalence or canonical input/button heights.
   - Fix: `design-audit.js` now checks labelled controls, input/button minimum geometry, 46/48px icon actions, Search/Add alignment, inherited Search label margin, contrast, heading hierarchy, duplicate IDs, overflow and mobile targets.

## Refactor implemented

- Updated `assets/design-tokens.css`
  - binding spacing scale retained;
  - separate input/button/compact/touch dimensions;
  - 20px dialog radius;
  - beta typography weights/sizes aligned to `GUI_STANDARD.md`;
  - semantic focus and status tokens retained.

- Added `assets/universal-design.css`
  - final typography hierarchy;
  - 6px label-to-control spacing;
  - persistent visible focus treatment;
  - canonical button and compact-button geometry;
  - canonical 46px Search/Add primitive and right-aligned action cluster;
  - reset of legacy Search label margins;
  - unified table density;
  - unified card/dialog spacing;
  - 48px mobile touch geometry;
  - reduced-motion handling.

- Updated `assets/management.js`
  - loads token and shell versions consistently;
  - pairs `mobile-hardening.css` with `mobile-runtime.js`;
  - loads `design-system.css` followed by `universal-design.css` as the final visual contract;
  - keeps existing domain behavior unchanged.

- Updated `assets/design-audit.js`
  - adds geometry, label and Search/Add placement regression checks.

- Updated `index.php` and `scan.php`
  - login and attendance now load the same final Universal Design contract as the authenticated workspace.

## Accessibility / Universal Design result

The refactor enforces:

- one visible focus treatment that is not colour-only;
- WCAG-oriented semantic text/background pairs from the token system;
- minimum 48px touch targets on tablet/phone;
- persistent labels for form controls, with runtime detection of unlabelled controls;
- horizontally scrollable data tables instead of page-level compression;
- mobile dialog and software-keyboard compatibility through the restored hardening layer;
- reduced-motion preference support;
- text + semantic styling for status states.

## Remaining verification before merge/deploy

The branch still needs live-browser verification on representative roles and viewports before deployment:

- SUPER/ADMIN/DEV dashboard;
- USER dashboard;
- Employees Search + Add;
- Stores Search + Add;
- Timesheets report and PDF/print mode;
- Disputes and attendance flags;
- Financial forms/tabs;
- password/employee/store dialogs;
- mobile keyboard-open state;
- mobile bottom navigation and contextual subnavigation;
- login and attendance scan pages.

In a browser, run:

```js
window.MERDPOSDesignAudit.run()
```

Expected result after each representative screen is mounted: an empty array, with `document.documentElement.dataset.merdDesignAudit === "pass"`.

No production deployment or merge is included in this branch work.
