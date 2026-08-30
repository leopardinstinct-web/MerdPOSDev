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


## Studio18 role inheritance, contrast and radial sizing

- New style/Hide patches record the effective **Current role** plus explicit downward targets: Developer ? Developer only; Admin ? Admin + Super + User; Super ? Super + User; User ? User only. Legacy draft patches without role metadata remain readable for backward compatibility. This is Studio preview inheritance only and does not alter MERDPOS permissions.
- Background palette changes now pair the chosen background with computed readable light/dark text ink instead of relying on the old hand-maintained swatch brightness flags. Studio accent chrome uses the same computed contrast rule.
- Settings ? Size is one Increase/Decrease control for radial geometry: it changes the center-button diameter and sector-ring thickness together. Existing font/icon scales remain readable from older local settings but are no longer separate menu branches.
- Wheel/center candidate navigation skips disabled menu definitions; disabled slices remain visible as unavailable context but cannot become the armed scroll action.


## Studio19 dashboard policy + size behavior

- Dashboard policy saves compare each role's allowed widget set before and after the save. Widgets that become newly allowed after all visibility/data permissions are satisfied are appended to that role's dashboard; widgets that were already allowed but intentionally removed are not re-added.
- Superseded by Studio20: widget visibility permissions no longer require granting the matching whole-application page/API permission. Standard data dependencies are resolved only inside the dashboard endpoint and do not expose the corresponding navigation or general API surface.
- DevStudio dashboard editing uses the widget drawer directly; the redundant circular `+` dashboard control stays hidden while Studio edit mode is active.
- Settings → Size now separates `Button Size` from `Icon Size`. Button Size scales the center hub and the radial ring's inner/outer radii together, changing hub diameter, slice thickness, and slice distance from center. Icon Size changes icons only.


## Studio20 scoped widget dependencies + account toggle

- A dashboard widget may declare a standard data dependency such as `workforce.view`, while its visibility remains controlled by its dedicated `dashboard.widget.*` permission. The dashboard endpoint resolves that dependency only for the allowed widget; it does not grant the role the corresponding page, navigation item or general API permission.
- Dashboard responses are narrowed to the widgets actually present. Count widgets receive counts; roster/list widgets receive the rows they need; unrelated dashboard payloads are not broadened by another widget's dependency.
- Actual DEV controls DevStudio from a toggle in the account sheet. The account avatar follows the Studio accent while enabled. The old separate restore/minimized icon and duplicate DEV-panel launcher are retired.
- Fine-pointer hover on a selectable element exposes a small Select affordance. The radial Select action is retired; once selected, the root exposes Unselect. Touch devices select directly because hover is unavailable.

## Studio21 mouse interaction model

- Enabling DevStudio keeps the radial controller hidden until it is needed.
- Right-click a selectable MERDPOS element to select it and open the radial menu at that pointer location. There is no hover Select affordance.
- While the radial is open, mouse-wheel input anywhere cycles enabled slices as if the pointer were over the center hub.
- Middle-click activates the currently armed slice. If no slice is armed, no action is executed.
- Right-click while the radial is open acts as Back. At the root level, Back closes the radial.
- Left-click outside Studio hides the radial but leaves DevStudio enabled and keeps the current selection.
- Touch keeps direct selection behavior; the radial opens after a touch selection.

## Studio22 icon URL resolution

- Studio icon URLs are now resolved from `document.baseURI` before use.
- This keeps radial SVG images and center-hub CSS masks on the same canonical `assets/vendor/google-material-symbols/` path.
- The change prevents stylesheet-relative `assets/assets/vendor/...` requests in the live beta runtime.
