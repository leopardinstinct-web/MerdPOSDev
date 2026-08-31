# MERDPOS UI Studio

UI Studio is an actual-DEV-only preview/handoff tool. MERDPOS remains the canvas. Studio preview state and history are synchronized through a dedicated client-scoped DEV API, but Studio never writes canonical source or operational business data.

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
- **Comment** records Developer design-review metadata against the selected element, synchronizes it with global Studio history, and includes it in Copy/Chat handoff.
- **Add** can create safe preview-only Text, Button, Card or Divider elements, then place the new element Above, Below, Left or Right of the selected target. Added nodes use DOM creation/textContent only; arbitrary HTML is not accepted.

## History

Studio maintains chronological client-global Developer history separately from the current active patch set. Every edit/comment/add/move/reset/undo/clear event records page/panel/selector context, actor, preview role and server revision.

**The change-count badge on the hub** opens a temporary history card. Clicking an entry returns to its recorded portal panel/page where possible, reopens relevant navigation/dialog/tool context, selects the recorded element and scrolls it into view. Each row also has a trash-can delete control. Deletion is persisted globally and the server replays surviving mutation steps to recompute the active preview. Cross-page jumps use session storage only for the one navigation handoff.

## Copy and Chat handoff

`window.MERDPOS_UI_STUDIO.getChangeSet()` returns active preview patches plus history metadata. `getHistory()` exposes a defensive copy of synchronized Developer history. Copy writes structured JSON; Chat writes an apply-to-canonical-source instruction, readable summary and the same JSON to the clipboard.

Comments and added-element patches are design intent only. They become MERDPOS only after canonical owner files are edited, committed to `namecheap-beta-live`, deployed through the normal Namecheap process and runtime-verified.

## Safety boundary

UI Studio requires preserved actual DEV identity even while Current role previews Admin/Super/User. Preview patches/comments/history synchronize only through the dedicated `ui_studio_history.php` DEV API and migration 035 tables; ordinary operational APIs remain governed by the effective preview role. Device-only Studio settings and hub position stay browser-local. Edit Dashboard remains a separate authenticated Dashboard Builder path.


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

## Studio23 hover, docked radial, and keyboard toggle
- When DevStudio is enabled and its radial is closed, hovering a selectable MERDPOS element shows the dashed Studio target outline. No hover Select button is rendered.
- Right-click selects the element but docks the radial at the viewport-safe corner farthest from the selected target, keeping the target visible.
- While the radial is open, wheel and middle-click remain global Studio controls. Right-click remains Back/root-close.
- A left-click outside the radial dismisses the radial and is consumed; it does not activate the underlying MERDPOS control. Normal page interaction resumes after dismissal.
- Ctrl+D toggles DevStudio enabled/disabled for actual DEV identity and uses the same persisted state/event path as the account-menu toggle.


## Studio24 global history and element change markers

- **Changes** is restored to the radial root and exposes History, Copy JSON, Chat, Undo, Reset and Clear.
- Migration 035 adds client-scoped `ui_studio_state` and `ui_studio_history`. Actual Developer sessions share one revisioned preview/history stream for the active Working client.
- Writes are CSRF-protected and use optimistic `base_revision` checks. A stale Developer session is rejected and refreshed instead of silently overwriting another machine.
- Local draft cache records its Working-client ID. A legacy unscoped draft may bootstrap an empty server journal once; afterward server state is authoritative.
- Every persisted history step stores actor, preview-role scope, page/panel/selector context and a normalized patch mutation. Deleting a step with the trash icon is global and recomputes active patches by replaying surviving mutations.
- Elements with active Studio patches receive a small green change dot while Studio is enabled and the radial is closed. Hover/focus/click on the dot opens a viewport-safe branded floating history card for that element, including per-step trash actions.
- The floating marker layer is Studio-owned and ignored by the application MutationObserver, preventing marker recreation loops while the pointer enters the dot/history card.
- JSON/Chat handoff is version 5 and includes `global:true`, the synchronized server revision, canonical role inheritance, proposal/request metadata, and optional image-context attachments.


## Studio25 cursor action guidance and accent artifacts

- Once an element is selected, a pointer-transparent branded pill follows the mouse cursor and reads `Select action…` until a radial action is armed.
- Global wheel navigation updates that pill with the currently armed DevStudio action label and its local Material Symbol. Middle-click continues to execute the armed radial action; the radial may remain docked away from the selected target.
- Clearing/unselecting the element hides the cursor pill. Closing the radial while keeping the selection restores the pill to `Select action…`.
- Change dots use `--merd-ui-accent` for fill, glow and focus chrome, so previously rendered change markers repaint immediately when the Developer changes the Studio accent.


## Studio26 Developer master and lower-role proposals

- **Developer is the visual master template.** Implemented DEV patches inherit to DEV + ADMIN + SUPER + USER; Admin inherits to Admin + Super + User; Super to Super + User; User to User. Runtime and server normalization derive targets from `roleScope`, so older saved DEV patches that explicitly carried `roleTargets:[DEV]` are interpreted as master-wide without recreating them.
- Implemented style, text, move, hide and DEV Add patches are layered in hierarchy order so a downstream override applies after its upstream template regardless of edit timestamp. Lower previews may specialize existing inherited elements but cannot delete an upstream element with Reset/Undo/Clear.
- Add while previewing ADMIN/SUPER/USER is **not** an implemented lower-role element. It records a `kind:request`, `requestType:add` proposal with `requestedFromPreview`, `implementationOrigin:DEV`, `status:proposed`, placement/type/content context, and a Studio-owned dashed visual placeholder. The proposal is visible in the requested preview and DEV master context only while Studio is active.
- Comment/Describe while previewing ADMIN/SUPER/USER is likewise a proposal request rather than an implemented comment. It remains anchored to the selected element/widget and is included in global history, change markers, JSON and Chat handoff.
- Lower-role proposals mean "design/request observed in this preview; implementation must begin in Developer master." They do not alter product authorization and do not create production DOM outside the Developer master inheritance chain.
- Undo, Reset and Clear operate on the current role's own Studio layer. An inherited master element can be hidden downstream but cannot be physically removed or restored against an upstream hide from a lower preview.


## Studio27 symmetric master Undo, rich comments and shared appearance

- DEV master Hide/Style and its Undo are symmetric across the inheritance chain. Legacy lower-role style patches that exactly duplicate an active upstream value and were never marked as explicit overrides are collapsed as redundant, so a plain DEV Hide → DEV Undo restores DEV/Admin/Super/User together.
- New Admin/Super/User implemented changes are stamped `explicitOverride:true`. Those real downstream decisions survive a later DEV master Undo; only inherited/redundant effects disappear with the master action.
- Comment / Request Note now opens a branded multiline composer rather than a browser prompt. The note may contain line breaks and may be saved with text, images, or both.
- Up to six PNG, JPEG, WebP, GIF or SVG context files may be attached per comment. Upload is actual-DEV-only and CSRF-protected. SVG is sanitized before storage; uploads are kept under private Namecheap backend runtime storage rather than the public portal directory.
- Each stored image receives a 256-bit random read token. `studio_context_asset.php?t=<token>` is intentionally read-only and sessionless so a copied ChatGPT handoff can fetch the referenced image without receiving MERDPOS credentials or directory access. SVG reads are sandboxed and all reads are MIME/nosniff guarded.
- Attachment name, MIME, size, SHA-256 and token URL are stored in the Studio patch. Copy JSON and Copy for ChatGPT include those URLs directly.
- Studio accent, font/icon scale and radial size use the browser profile's localStorage and listen for `storage` changes, so already-open MERDPOS windows repaint when another window changes Studio appearance. Studio state remains device/browser-profile local; it is not made global across machines.

## Canonical implementation-patch execution rule

- DevStudio comments/style/request patches are implementation instructions, not permanent product source.
- Execute the requested change in canonical MERDPOS source, run regressions, deploy the exact commit, and live-verify the resulting runtime first.
- Only after successful live verification, programmatically remove the matching active patches from the global DevStudio state through the revisioned Studio API.
- Do not delete the corresponding Studio history/audit entries; history remains the implementation trail.
- Remove only patches whose requested behavior is demonstrably implemented and verified. Unfinished or failed requests remain active.
## Studio28 unresolved patch inbox + LLM receipt
- DevStudio shows and copies only global patches that are not `confirmed_applied`; backend history is not exposed in the DS UI or copy payload.
- Every active patch has a stable `patchId` and status: `pending`, `implementing`, `implemented`, or `blocked`. `confirmed_applied` removes it from the active inbox.
- Copy for ChatGPT emits JSON v6 plus the required `merdposDevStudioReceipt: 1` response contract.
- Paste LLM Receipt applies revisioned patch-status updates. The backend `ui_studio_history` journal remains the immutable audit/learning record.
- The actual-DEV account summary shows compact unresolved counters; the two supplied folder SVGs are canonical assets.
