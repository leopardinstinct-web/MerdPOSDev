# MERDPOS UI Studio

UI Studio is an actual-DEV-only local preview/handoff tool. MERDPOS remains the canvas; Studio never writes source, APIs or database state.

## Sector radial interaction

- One draggable **UI / DEV** hub is the persistent controller.
- The hub uses native browser Popover plus local HTML/CSS/JavaScript and locally vendored Google Material Symbols.
- The root ring is four bright DEV-tool sectors: **Select**, **Edit**, **Changes**, **Exit**.
- Selecting a parent keeps its sector layer visible and opens the child sector ring around it. Parent rings dim rather than disappearing.
- Items that own another layer show an expand marker. While inside a submenu the hub becomes **Back**.
- **Edit** opens Color, Text, Layout, Hide/Show, Move, Scope, Comment and Add.
- **Changes** opens History, Copy, Chat, Undo, Reset and Clear.
- Wheel/trackpad input over the radial controller rotates the nearest active ring. In Color, the outer ring scrolls through the full preview color library.
- Studio chrome uses a bright DEV-tool palette and is intentionally visually independent from MERDPOS product branding. Selection outlines on the MERDPOS canvas still use canonical semantic/product tokens.

## Selection and transient UI

**Select** intercepts pointer-down/click before the application action executes, so DEV can select rendered navigation buttons, sidebar submenu items, mobile utility items, dialogs/popovers and other dynamically opened controls without activating them.

Desktop navigation and mobile shell tools treat `[data-ui-studio]` as a non-closing surface. Clicking the Studio hub/menu therefore does not collapse an already-expanded sidebar or close an opened shell utility menu while DEV is preparing to select one of its elements.

## Preview operations

- **Text** edits a selected leaf text element inline; Enter saves and Esc cancels.
- **Color** targets background/text color and provides a scrollable outer color-picker ring.
- **Layout** exposes padding, margin, gap, radius, width and font-size presets.
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
