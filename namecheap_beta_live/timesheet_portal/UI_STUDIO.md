# MERDPOS UI Studio

UI Studio is an actual-DEV-only local preview/handoff tool. MERDPOS remains the canvas; Studio never writes source, APIs or database state.

## Sector radial interaction

- One draggable **UI / DEV** hub is the persistent controller. Dragging preserves the original finger-to-hub-center offset, so touching near an edge never snaps the hub underneath the finger. Touch/pen taps activate directly on pointer-up and any later synthetic click is treated only as a duplicate, so a real tap after dragging still opens Studio normally.
- The hub uses native browser Popover plus local HTML/CSS/JavaScript and locally vendored Google Material Symbols.
- The root ring is four actions: **Select**, **Edit**, **Changes**, **Exit**.
- Studio uses **single-ring drill-down**: selecting a parent replaces the current ring with its child ring instead of stacking or shrinking ancestor rings.
- Items that own another level show an expand marker. While inside a submenu the center hub shows the current context and acts as **Back**.
- **Edit** opens Color, Text, Layout, Hide/Show, Move, Scope, Comment and Add.
- **Changes** opens History, Copy, Chat, Undo, Reset and Clear.
- Wheel/trackpad input rotates the active ring. **Color → More** cycles through the extended preview color library while remaining a single ring.
- Studio chrome is deliberately independent from MERDPOS product branding: background `#1A1A2E`, normal sectors `#25253D`, active sectors `#30304C`, white primary text and muted `#A8AEC1`. Bright action accents identify functions without recoloring the sector surfaces.
- The hub and radial SVG share one fixed stage/center, and both are explicitly anchored at `left:0; top:0` inside that stage so browser static-position rules cannot give them different origins. Android visual-viewport resize/scroll events re-render and re-clamp the stage.
- Studio14 mirrors the approved prototype proportions: a `760×760` SVG viewBox, center radius `72`, ring radii `84 → 220`, zero wedge gaps, and 30-unit Google Material Symbols. Icons sit 16 units above each slice midpoint; labels share the exact icon X coordinate and sit 38 units below the icon center. Normal sectors stay `#25253D`, active sectors `#30304C`, and only the icon carries the bright action accent.

## Selection and transient UI

**Select** intercepts pointer-down/click before the application action executes, so DEV can select rendered navigation buttons, sidebar submenu items, mobile utility items, dialogs/popovers and other dynamically opened controls without activating them.

Desktop navigation and mobile shell tools treat `[data-ui-studio]` as a non-closing surface. Clicking the Studio hub/menu therefore does not collapse an already-expanded sidebar or close an opened shell utility menu while DEV is preparing to select one of its elements.

## Preview operations

- **Text** edits a selected leaf text element inline; Enter saves and Esc cancels.
- **Color** exposes Background/Text targeting, then the five MERDPOS master colors (Navy, Cyan, Violet, App Background, White) before **More** opens the extended rotating palette.
- **Layout** exposes padding, margin, gap, radius, width and font-size. Padding, Margin, Gap, Radius and Font use a two-sector **▲ / ▼** stepper with the current value displayed in the hub; Width keeps direct preset choices.
- **Scope** supports This element, This component type, All matching elements and All pages.
- **Move** exposes Before, After and Inside and remains This-element-only.
- **Hide/Show** changes visibility in the local preview. Reveal/Restore support remains in the patch engine for hidden-preview recovery.
- **Comment** records local design-review metadata against the selected element and includes it in Copy/Chat handoff.
- **Add** can create safe preview-only Text, Button, Card or Divider elements. Added nodes use DOM creation/textContent only; arbitrary HTML is not accepted.

## History

Studio maintains chronological local history separately from the current active patch set. Every edit/comment/add/move/reset/undo/clear event records page/panel/selector context.

**Changes → History** opens a temporary history card. Clicking an entry returns to its recorded portal panel/page where possible, reopens relevant navigation/dialog/tool context, selects the recorded element and scrolls it into view. Cross-page jumps use session storage only for the one navigation handoff.

## Copy and Chat handoff

`window.MERDPOS_UI_STUDIO.getChangeSet()` returns active preview patches plus history metadata. `getHistory()` exposes a defensive copy of local history. Copy writes structured JSON; Chat writes an apply-to-canonical-source instruction, readable summary and the same JSON to the clipboard.

Comments and added-element patches are design intent only. They become MERDPOS only after canonical owner files are edited, committed to `namecheap-beta-live`, deployed through the normal Namecheap process and runtime-verified.

## Safety boundary

UI Studio requires the actual `is_dev` identity flag. Its draft/history remain local browser state and are labelled `DEV - PREVIEW ONLY`. The Studio runtime contains no application API calls, fetch/XHR mutation path or source writer.
