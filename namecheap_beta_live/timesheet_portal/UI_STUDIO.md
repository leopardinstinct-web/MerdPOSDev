# MERDPOS UI Studio

UI Studio is an actual-DEV-only local preview/handoff tool. MERDPOS remains the canvas; Studio never writes source, APIs or database state.

## Sector radial interaction

- One draggable **UI / DEV** hub is the persistent controller and opens automatically for an authenticated DEV session. Dragging preserves the original finger-to-hub-center offset, so touching near an edge never snaps the hub underneath the finger. Touch/pen taps activate directly on pointer-up and any later synthetic click is treated only as a duplicate, so a real tap after dragging still opens Studio normally. If opening the ring repositions the stage inward, that same gesture’s synthetic click is consumed at document capture so it cannot retarget a newly appeared wedge or underlying MERDPOS control.
- The hub uses native browser Popover plus local HTML/CSS/JavaScript and locally vendored Google Material Symbols.
- The Studio17 root begins with **Minimize**, then **Select**, **Edit Dashboard** and **Settings**, plus shared **Undo**. Once one element is selected, Add, Edit, Move, Comment and Hide/Show join those root actions. Select always replaces the prior selection, so only one element is selected at a time. There is no Exit wedge. Minimize is desktop-only and docks a Studio restore circle immediately to the right of the account circle.
- Studio uses **single-ring drill-down**: selecting a parent replaces the current ring with its child ring instead of stacking or shrinking ancestor rings.
- The ring is icon-only. Hover/focus previews an action name in the center hub. On fine-pointer devices, hovering the hub opens the ring; wheel input over the hub cycles available actions, the armed sector is highlighted, and clicking the hub executes it. With no armed action, the hub acts as **Back** inside a submenu.
- **Edit** opens **Color** and **Layout** only. Color drills into **Palette**; Layout retains Padding, Margin, Gap, Radius, Width and Font.
- **Add** chooses Text, Button, Card or Divider, then chooses **Above / Below / Left / Right** relative to the selected target.
- **Move** first enters **Select Destination** mode; after a destination is chosen the ring offers **Top / Bottom / Left / Right**.
- **Comment** and **Hide/Show** are direct selected-element actions. The hub change-count badge opens History, where individual steps can be deleted.
- **Edit Dashboard** launches the existing persistent Dashboard Builder for the currently effective Current role. This is an explicit actual-DEV tool exception: the normal Admin/Super/User website remains permission-faithful, while the Dashboard Builder alone may save that role's dashboard layout through its existing API.
- **Settings → Color** changes Studio-only accent chrome (hub, armed borders, selection outline and restore control). **Settings → Size → Font/Icon** adjusts Studio font or icon scale with Increase/Decrease. These preferences are browser-local and never recolor MERDPOS product UI.
- The Dashboard widget drawer exposes **Describe** for each widget. Its text is stored as Studio comment/context metadata keyed to that widget and is included in Studio history/Copy/Chat handoff; Describe itself does not change application data.
- Wheel/trackpad input over the **hub** cycles selectable actions without moving the pointer. Wheel input over the ring still rotates the ring; **Color → More** cycles through the extended preview color library while remaining a single ring.
- Studio chrome is deliberately independent from MERDPOS product branding: background `#1A1A2E`, normal sectors `#25253D`, active sectors `#30304C`, white primary text and muted `#A8AEC1`. Bright action accents identify functions without recoloring the sector surfaces.
- The hub and radial SVG share one fixed stage/center, and both are explicitly anchored at `left:0; top:0` inside that stage so browser static-position rules cannot give them different origins. Android visual-viewport resize/scroll events re-render and re-clamp the stage.
- Studio17 keeps the approved prototype geometry: a `760×760` SVG viewBox, center radius `72`, ring radii `84 → 220`, and zero wedge gaps. Permanent wedge labels are removed; 48-unit Google Material Symbols are centered in the slices and the amber hub becomes the live action label/display. Normal sectors stay `#25253D`, active/armed sectors use `#30304C`, and only the icon carries the bright semantic accent.

## Selection and transient UI

**Select** intercepts pointer-down/click before the application action executes, so DEV can select rendered navigation buttons, sidebar submenu items, mobile utility items, dialogs/popovers and other dynamically opened controls without activating them.

Desktop navigation and mobile shell tools treat `[data-ui-studio]` as a non-closing surface. Clicking the Studio hub/menu therefore does not collapse an already-expanded sidebar or close an opened shell utility menu while DEV is preparing to select one of its elements.

## Preview operations

- **Color → Palette** exposes Background/Text targeting, then the five MERDPOS master colors (Navy, Cyan, Violet, App Background, White) before **More** opens the extended rotating palette.
- **Layout** exposes padding, margin, gap, radius, width and font-size. Padding, Margin, Gap, Radius and Font use a two-sector **▲ / ▼** stepper with the current value displayed in the hub; Width keeps direct preset choices.
- **Move** is destination-first and remains single-element preview behavior. When a selected/destination node is inside a recognized MERDPOS component, Move promotes it to that component root so moving a card/feature does not accidentally move only its inner text/icon. Top/Left insert before the destination; Bottom/Right insert after it in DOM order.
- **Hide/Show** is state-aware: a visible selection shows **Hide**; after hiding, the same action becomes **Show** and restores the preview. Reveal/Restore support remains in the patch engine for hidden-preview recovery.
- **Comment** records local design-review metadata against the selected element and includes it in Copy/Chat handoff.
- **Add** can create safe preview-only Text, Button, Card or Divider elements, then place the new element Above, Below, Left or Right of the selected target. Added nodes use DOM creation/textContent only; arbitrary HTML is not accepted.

## History

Studio maintains chronological local history separately from the current active patch set. Every edit/comment/add/move/reset/undo/clear event records page/panel/selector context.

**The change-count badge on the hub** opens a temporary history card. Clicking an entry returns to its recorded portal panel/page where possible, reopens relevant navigation/dialog/tool context, selects the recorded element and scrolls it into view. Each row also has a delete control that removes only that history step. Cross-page jumps use session storage only for the one navigation handoff.

## Copy and Chat handoff

`window.MERDPOS_UI_STUDIO.getChangeSet()` returns active preview patches plus history metadata. `getHistory()` exposes a defensive copy of local history. Copy writes structured JSON; Chat writes an apply-to-canonical-source instruction, readable summary and the same JSON to the clipboard.

Comments and added-element patches are design intent only. They become MERDPOS only after canonical owner files are edited, committed to `namecheap-beta-live`, deployed through the normal Namecheap process and runtime-verified.

## Safety boundary

UI Studio requires the actual `is_dev` identity flag. Its draft/history remain local browser state and are labelled `DEV - PREVIEW ONLY`. Ordinary Studio preview patches, comments, history and settings contain no application API calls, fetch/XHR mutation path or source writer. Edit Dashboard is deliberately outside that preview engine and delegates to the existing authenticated Dashboard Builder, whose dashboard-layout save API remains separately permissioned and actual-DEV gated when launched from Studio.
