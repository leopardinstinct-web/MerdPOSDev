# MERDPOS UI Studio

UI Studio is a DEV-only visual preview tool for fast Beta UI iteration. MERDPOS stays visible as the canvas: Studio has no inspector drawer, textarea, or persistent editor panel.

## Native circular interaction

- Opening Studio shows one draggable circular **UI** hub on desktop and mobile.
- The hub uses native browser Popover plus local HTML/CSS/JavaScript only; controls use locally vendored Google Material Symbols, with no circular-menu, React, icon-font, or CDN runtime dependency.
- Clicking **UI** opens an adaptive radial menu toward the visible space around the hub.
- Dragging the hub moves it around the viewport and stores desktop/mobile positions separately in browser local storage.
- **Select** closes the menu so DEV can click a rendered MERDPOS element without triggering its normal action.
- **Text** enables inline preview editing for a selected text-only element; Enter saves and Esc cancels.
- **Color** opens a nested palette ring for White, Canvas (`#F5F7FC`), Navy, Cyan and Violet plus a BG/Text target toggle.
- **Layout** opens nested rings for padding, margin, gap, radius, width and font-size presets.
- **Scope** exposes This element, This component type, All matching elements and All pages.
- **Move** exposes Before, After and Inside; structural movement remains This element only.
- **Hide/Show** changes visibility in the preview. **Reveal** temporarily exposes preview-hidden items without deleting the draft patches.
- **Changes** exposes Copy, Chat, Undo, Reset and Clear without displaying the JSON on screen.

## Copy and Chat handoff

The structured draft remains internal. **Copy** writes the raw JSON change-set to the clipboard. **Chat** writes an apply-to-canonical-source instruction, readable change summary and the same JSON to the clipboard for pasting into ChatGPT.

`window.MERDPOS_UI_STUDIO.getChangeSet()` remains available to an authorized connected-browser workflow.

## Safety boundary

UI Studio never writes source files, calls MERDPOS APIs, changes database state, or affects another user's browser. It is gated by the actual `is_dev` identity flag and labels its temporary work `DEV - PREVIEW ONLY`.

A UI Studio change-set is design intent. It becomes MERDPOS only after canonical owner files are edited, committed to `namecheap-beta-live`, deployed through the normal Namecheap process, and runtime-verified.

## Scope semantics

- **This element** targets only the clicked DOM node.
- **This component type** targets the same element role inside the current component on the current page.
- **All matching elements** targets the same matching selector across portal panels.
- **All pages** removes panel-specific scope and targets the shared matching component across portal pages.

The radial layout computes item coordinates in JavaScript, clamps the open ring into visible viewport space, and avoids experimental CSS `@function` / `sibling-index()` support. Google Material Symbols are vendored locally from the pinned upstream source recorded in `assets/vendor/google-material-symbols/NOTICE.md`.
