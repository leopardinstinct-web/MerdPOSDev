# MERDPOS Beta Regression Inventory

**Updated:** 2026-08-29

This inventory describes the current safety net. It must be read with `.ai/README.md`, `.ai/memory.md` and `.ai/playbook.md`.

The beta portal is in active redesign. This file is **not** a mandate to automate every current screen. Permanent regression should protect stable business/security/runtime contracts; evolving UI workflows should use targeted smoke and runtime verification until they stabilise.

Source/CI inspection does not equal Namecheap deployment verification.

## Current permanent safety net

| Area | Current protection | Status / intent |
|---|---|---|
| Portal runtime contract | PHP/JS source validators, canonical asset checks, loader/deploy guardrails | Permanent |
| Authorization model | Permission-policy validator, authorization matrix tooling, server-side route/data checks | Permanent |
| DEV-only boundary | Actual DEV identity required; LOA 1000 alone is insufficient | Permanent |
| Shared beta-state scope | Validator protects consumer-based route access and permission-scoped payload sections | Permanent |
| Dynamic loader order | Roles-before-Navigation and `async=false` loader behavior guarded | Permanent |
| Permission-hidden legacy runtime | Chrome smoke protects against hidden-DOM JS crashes | Permanent |
| Timesheet runtime injection | Chrome smoke guards duplicate `timesheet-app.js` loading and request multiplication | Permanent |
| Mobile UX contract | 390x844 Chrome smoke protects four-destination primary nav, utility bottom sheet, contextual page header/subtabs, labelled table cards and horizontal-overflow safety | Permanent usability/runtime contract; do not freeze exact cosmetic coordinates |
| Brand master palette | Source contract + Chrome regression protect the five canonical brand colors and reject retired extended-spectrum literals from brand-facing CSS | Permanent brand/design-system contract |
| DEV UI Studio | Source contract forbids network/mutation ownership and third-party circular runtimes; Chrome smoke checks actual-DEV gating, native Popover nested sector editing, locally vendored Google Material Symbols, navigation/transient-menu selection interception, text/comment/add patches, navigable local history, matching scope, draggable viewport-safe placement, scrollable color/layout/scope/move layers and Copy/Chat handoff | Permanent tooling/safety contract |
| Shared Add/Search runtime | Chrome smoke protects duplicate mutation/click behavior | Keep while component remains canonical |
| DEV Stores identity | Browser regression accepts backend `Developer` label for DEV store enrichment | Permanent incident guard |
| Authenticated read-only live audit | Reusable external-storage-state runner | Available; run when meaningful, not after every cosmetic change |
| DUMMY Financial | Open Day → Cash In → Cash Out → Z Report with final-state assertions | Live DUMMY workflow previously VERIFIED |
| DUMMY core transactions | Workforce, Stores, Roles/permissions, report surfaces, mobile basics | Live DUMMY run previously `DUMMY_CORE_OK total=30 passed=30`; use as an opt-in safety tool, not a mandatory loop for every redesign |
| DUMMY destructive workflow | Manual/opt-in GitHub workflow with external auth material | Permanent safety mechanism |
| CI product-area scoping | Portal-only changes skip unrelated mobile/root-backend heavy jobs | Permanent workflow principle |

## Intentionally not promoted to exhaustive permanent regression

### Disputes lifecycle

The existing API supports dispute create/decision operations, but full DUMMY-native employee-owned lifecycle automation is not a current product-stage requirement.

An experimental branch `dummy-native-disputes` exists. It is unmerged and should be treated as exploration, not pending mandatory work. Reassess current product UX/security requirements before using or merging it.

### Attendance QR/device workflow

Server-side attendance QR verification/scanning scaffolding exists, including cooldown handling, but the complete POS device/QR product flow was not established as a stable end-to-end feature during the audit.

Do not treat endpoint presence as feature completeness. Attendance automation remains deferred until the product flow is sufficiently complete/stable or explicitly prioritised.

### Navigation/panel/layout details

Do not permanently assert exact current:

- navigation labels;
- panel order;
- DOM hierarchy;
- cosmetic layout;
- exact wording;
- every CRUD click sequence.

These are expected to change while the product is being redesigned.

## Verified live baselines already obtained

### Read-only Developer baseline

A previous authenticated live audit completed 24/24 across core read-only Developer portal surfaces, report switching and mobile 390×844 checks with no browser runtime errors or failed application HTTP responses.

### DUMMY Financial baseline

Previously live-verified on DUMMY:

- Open Day;
- Cash In;
- Cash Out;
- Z Report / close;
- final statement reload/closed balances.

### DUMMY core baseline

Previously live-verified:

`DUMMY_CORE_OK total=30 passed=30`

Coverage included:

- Workforce create/edit/pay-rate/credential-reset/deactivate;
- Store create/edit/deactivate;
- Role create/edit/delete;
- permission LOA change + restoration;
- report/panel read surfaces;
- desktop/mobile runtime checks;
- DUMMY context preservation.

These baselines prove those workflows at that checkpoint. They do not freeze the UI or guarantee later deployment state.

## Incident-derived permanent guards

### LOA permission runtime crash

Protected by portal permission-policy validation and browser smoke. Permission-hidden UI must not crash legacy JS; API/backend permission enforcement remains authoritative.

### Shared Add MutationObserver loop

Protected by browser smoke: canonical Add control must not duplicate or trigger multiple actions under unrelated DOM mutations.

### Dynamic Roles → Navigation loader race

Protected by deterministic classic-script insertion/order validation.

### Shared state accidentally required Dashboard

Protected by `validate_beta_state_scope.php`; shared state follows consuming feature permissions while sensitive sections remain independently scoped.

### Management cards implied unavailable data

Management surfaces should not imply access to data the identity cannot actually read.

### Duplicate Timesheet runtime injection

Protected by duplicate-loader guard and browser request-count assertions.

### DEV Stores role-label mismatch

Protected by `dev-stores-runtime.spec.js`; frontend normalization accepts backend Developer/DEV identity representation without weakening DEV-only authorization.

## When to add a new permanent regression

Add one when at least one is true:

1. a stable business/security invariant needs protection;
2. a real production/beta incident has been understood and needs a narrow guard;
3. the product owner considers the workflow sufficiently stable;
4. the cost of recurrence is materially higher than test-maintenance cost.

Do not add a permanent test merely because a screen currently exists.

### UI Studio hub/ring drift and undersized touch sectors

Protected by `ui-studio-runtime.spec.js` Galaxy-sized geometry/readability coverage. The hub and radial SVG must share one stage center; Android visual-viewport changes must re-render/re-clamp the menu; visible sector labels/icons must remain readable and hit-testable.

### UI Studio radial static-origin/touch regression

Protected by `ui-studio-runtime.spec.js`: at 394x512 in a touch-enabled mobile context, hub/menu computed origins must both be `0px,0px`, the centers remain concentric, and a real touchscreen tap on the rendered Edit sector must open its child ring.

### UI Studio touch-drag contact-offset regression

Protected by `ui-studio-runtime.spec.js`: in a 394x512 touch-enabled Chromium context, a drag starting off-center on the hub must move the hub only by the gesture delta. The hub must not snap so the initial finger coordinate becomes its new origin.

### UI Studio post-drag tap regression

Protected by `ui-studio-runtime.spec.js`: after a real touch drag at 394x512, the hub must preserve gesture delta and the next intentional touchscreen tap must open the root sector ring without being swallowed by stale click suppression.

## UI Studio compact-parent / touch activation regression
- 394x512 touch: off-center drag preserves finger-to-hub offset and one intentional tap immediately reopens the radial menu.
- Active ring: labels/icons meet readability thresholds and the tested label bounding box stays inside its sector bounding box.
- Submenu hierarchy: every ancestor ring has `.is-compact-parent`, contains icons, and contains no sector-label nodes.

## UI Studio Studio13 single-ring regressions

- Root renders one ring with Select/Edit/Changes/Exit; entering Edit removes root items rather than stacking ancestor rings.
- Hub shows the active layer and Back; Back walks Color → Edit → Root while keeping one sector ring.
- Color exposes BG/Text targeting plus Navy/Cyan/Violet/Canvas/White before More; More rotates through the extended palette in the same ring.
- Numeric Padding/Margin/Gap/Radius/Font render only Increase/Decrease and update the hub's current value plus the local preview patch.
- Galaxy-size regression asserts shared hub/ring center, dark `#25253D` sector surface, label containment and practical rendered icon/label size.
- Existing true-touch 394x512 tap, off-center drag/no-snap, post-drag single-tap reopen, sidebar/transient selection, comments/Add/History and Copy/Chat regressions remain mandatory.

## UI Studio14 prototype-parity regressions
- Galaxy-size radial regression asserts `0 0 760 760`, ring radii `84/220`, zero slice gap, and shared hub/ring center.
- Select uses the locally vendored Google `gesture_select_48px.svg`; Layout uses `dashboard_48px.svg`; active icons are 30 SVG units with no colored backplate circles.
- Icon and label share the same X coordinate; label Y remains exactly 38 SVG units below the icon center.
- The center diameter stays in the prototype `144/760` proportion to the menu shell across viewport changes.
- Transparent space outside the visible ring does not receive pointer input, and ring-shell resizing is not animated.
- Existing real-touch 394×512 taps, no-snap drag, post-drag reopen, comments/Add/History, color, numeric stepper and Copy/Chat regressions remain mandatory.

## UI Studio14 first-touch synthetic-click regression
- The browser fixture includes the real portal viewport meta tag.
- On 394×512 touch, the first hub pointer-up may reposition the stage inward; any synthetic click from that same touch sequence must be consumed globally before it can retarget a newly rendered wedge or underlying MERDPOS control.
- If Chrome emits no synthetic click, the next new pointer-down clears suppression so the user’s next intentional tap is never swallowed.


## Desktop bottom-dock shell regression
- At desktop width the app frame must not allocate a left rail column; .app-rail is fixed to the viewport bottom.
- Only Home, Operations, Reports and Finance remain primary dock destinations; System/DEV is absent from primary navigation.
- The circular account trigger opens the utility surface containing working-client and signed-in-user context plus system/app utilities.
- Multi-item primary groups keep their secondary pages reachable through contextual sub-navigation above the bottom dock.

## UI Studio15 center/Move/history regressions
- Fine-pointer hover on the center hub opens the radial ring; wheel input over the hub skips disabled actions, highlights one candidate, displays its label in the hub and center-click executes it.
- With no armed candidate, center-click is Back inside nested menus. Undo is rendered on every radial level.
- Move from inner card content must promote source and destination to their MERDPOS component roots and visibly reorder the components while recording a move patch.
- Hide changes to Show after the local hide patch, and Show restores the preview. History exposes a per-row delete control that removes only that history event.
- Icon-only sectors use 48-unit Material Symbols; permanent sector-label nodes are absent from normal Studio menus.

## UI Studio15b touch-hover compatibility regression
- Hover-to-open is fine-pointer-only. A touch/mobile context may emit compatibility mouse events, but mouseenter must not open or menu-clamp Studio before a touch drag.
- The 394x512 drag regression dispatches mouseenter before measuring the hub and requires the radial menu to remain hidden, then preserves the exact touch drag delta.

## Studio16 selection-flow / DEV role-view regressions
- Actual DEV loads the yellow Studio hub automatically; non-DEV has no Studio runtime. The unselected root contains Select (+ shared Undo) and no Exit wedge.
- Selecting a second element removes the prior selection so exactly one target remains selected. The selected root exposes Select, Add, Edit, Move, Comment and state-aware Hide/Show.
- Edit drills Color → Palette and Layout. Add drills element type → Above/Below/Left/Right. Move drills Select Destination → target selection → Top/Bottom/Left/Right.
- The hub change-count badge opens History and each History row can be deleted independently. Existing 48-unit icon-only geometry, fine-pointer hover/wheel and touch no-snap contracts remain mandatory.
- The account sheet orders actual user/role → Working client → DEV Current role selector; DEV/Clients shortcut clones are absent. Current role offers only Admin/Super/User and must be universal: page rendering, dashboard/data and normal API permission checks follow the selected effective role while actual DEV identity remains intact for Studio/client-context tooling.
## Studio17 dashboard/settings/minimize regressions
- Current role exact options are Developer, Admin, Super, User; Developer returns effective website role to actual DEV without losing Studio or Working client.
- Studio root exposes Minimize first, Select, Edit Dashboard and Settings; desktop Minimize docks a restore button directly after the account circle and restore reopens the floating Studio.
- Settings Color persists Studio accent only; Size Font/Icon Increase/Decrease persist independent Studio scaling.
- Edit Dashboard invokes the shared Dashboard Builder through an explicit actual-DEV `dev_studio` mode for the current effective role; normal preview permission enforcement remains unchanged.
- Every dashboard widget catalogue row exposes Describe, and accepted text is handed to `MERDPOS_UI_STUDIO.addContextComment` with a stable dashboard-widget context key.


## Studio18 role/contrast/geometry regressions
- UI Studio browser coverage asserts Admin patches export Admin/Super/User role targets, palette backgrounds add readable foreground ink, radial Size changes both hub diameter and sector geometry, and wheel navigation skips disabled actions.
- Studio19: regression coverage checks independent Button Size/Icon Size geometry, hidden dashboard + control in DevStudio edit mode, and runtime-contract guards for newly-allowed widget materialization.


## Studio20 dependency/toggle/hover-selection regressions
- Dashboard allowance is based on the dedicated widget visibility permission; standard data dependencies are resolved inside dashboard_data.php without granting the matching page/API permission.
- Workforce count/list, dispute count and other scoped payloads stay least-data for the allowed widgets.
- DEV account sheet exposes the Studio enable toggle and accent-linked avatar; no separate Studio restore control is created.
- Fine-pointer hover exposes Select without firing the underlying control; selected root exposes Unselect and no Select wedge. Touch selection remains direct and synthetic hover stays inactive.

## Studio21 context-click interaction regressions
- Enabled Studio starts with its radial hidden; right-click selection opens it and exposes Unselect.
- Hover Select UI and hover-to-open behavior remain absent.
- Wheel input anywhere while open cycles enabled slices and still skips disabled items.
- Middle-click activates the armed radial action.
- Right-click while open navigates Back; right-click at root closes the radial.
- Left-click outside hides the radial without disabling Studio.
- Existing touch direct-selection and hub drag regressions remain mandatory.

## Studio22 live icon-path regression
- When the radial is open and wheel selection previews an action in the center hub, the hub icon URL must resolve to the document-root `assets/vendor/google-material-symbols/` path.
- Browser coverage rejects `/assets/assets/` in the generated hub icon URL.
- Live verification must include failed-response monitoring for Studio asset 404s before the interaction model is marked VERIFIED.

### Studio23 hover/docked-radial regressions
- Enabled Studio must show `.merd-ui-studio-hover` on selectable elements while the radial is closed, with no hover Select control.
- Right-click selection must position the radial away from the target, not at the click coordinate.
- While the radial is open, wheel/middle/right controls belong to Studio and an outside left-click must dismiss without activating the page control beneath it.
- Ctrl+D must toggle Studio enabled state and emit the same state synchronization used by the account-menu toggle.

## 2026-08-30 - Dashboard status pills
- Regression: Current User pill must always describe only the authenticated employee and their own shop shift.
- Regression: DEV preview-role pill must never imply impersonation; actual DEV identity remains unchanged.
- Regression: preview-role switch timestamp must survive reload and render as a relative `Switched ... ago` action.
- Regression: non-DEV users must never receive the preview-role pill.
- Regression: user/client pills remain visible on phone layouts without horizontal overflow.


## Studio24 global history regressions
- Root radial must expose Changes and Changes must expose History, Copy, Chat, Undo, Reset and Clear.
- `ui_studio_history.php` must be actual-DEV-only through preserved actual identity, including while Current role is USER/ADMIN/SUPER.
- Migration 035 and deploy wiring must create/verify `ui_studio_state` and `ui_studio_history` before portal publish.
- Two independent Developer browser contexts must observe one shared revision/history stream and global trash deletion.
- Changed visible elements must render a stable green change dot; hovering it must show element-scoped history without MutationObserver detach/recreate churn.
- Local Studio cache must carry Working-client identity so a newer client-tagged cache is never applied to another Working client.


### Studio25 cursor/action + About release regressions
- Selected item must show the cursor-follow pill and move with the mouse.
- Mouse-wheel radial focus must update pill icon and label; unarmed state must read `Select action…`.
- Existing change dots must repaint to the current DevStudio accent without requiring a page reload.
- About splash must preserve the supplied white-left / gradient-right brand composition, show MERDPOS + DevStudio Git refs/dates, and render exactly three release highlights.
- Namecheap deploy must generate and validate `.beta_release.json` before writing the deployed marker.


### Studio26 master-inheritance / proposal regressions
- Legacy stored DEV patches stamped with `roleTargets:[DEV]` must normalize to DEV/ADMIN/SUPER/USER and apply in every lower preview.
- Fresh DEV and Admin patches must export canonical downward targets; lower-role Undo must remove only that preview layer and preserve upstream master patches.
- Add in USER/ADMIN/SUPER must create a `requestType:add` proposal with `implementationOrigin:DEV`, never an implemented `add`; its visual placeholder is Studio-owned and appears in DEV plus the requested preview context only.
- Comment/Describe in a lower preview must create a `requestType:comment` proposal, not a lower-role implemented comment.
- Server patch normalization must accept `request`, canonicalize role targets, and convert any lower-role persisted add/comment into proposals on read/write/replay.


### Studio27 master-Undo / rich-comment / window-sync regressions
- A legacy USER/ADMIN/SUPER style patch that merely duplicates an active upstream master value and lacks explicit-override metadata must collapse as redundant; DEV Undo must then restore the element/value in every inherited preview.
- A newly created lower-role style override must carry `explicitOverride:true` and must survive a later DEV master Hide → Undo cycle.
- Comment and Request Note use the Studio multiline composer; text preserves line breaks and a note may contain only attachments.
- Context upload accepts at most six image files per comment. Upload route is actual-DEV-only; public read requires a 64-hex random token; SVG upload is sanitized and SVG read is sandboxed.
- Copy for ChatGPT must include every attached token URL plus JSON attachment metadata.
- Changing Studio accent in one same-origin browser window must update an already-open second window and its existing change markers without requiring reload.
