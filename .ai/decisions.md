# MERDPOS Beta Decisions

This file records durable decisions. Later entries may explicitly supersede earlier ones. Historical decisions remain for provenance but are not binding once superseded.

## 2026-08-27 — Keep `design-audit.js` during recovery

**Decision:** Do not delete `namecheap_beta_live/timesheet_portal/assets/design-audit.js` as part of recovery initialization.

**Reason:** The current authoritative beta source explicitly treats the file as a canonical runtime invariant. It is loaded by `assets/management.js`, cache-revalidated by portal `.htaccess`, required by `validate_beta_runtime_contract.php`, and checked by `scripts/deploy_namecheap_beta.sh` before the deployed marker is written.

Deleting it in isolation would intentionally make the source/deploy contract fail and could remove regression checks for headings, contrast, touch targets, Search/Add placement, accessibility names and page overflow.

**Supersedes:** A stale transferred handoff expectation that a new recovery session would automatically remove `design-audit.js`.

## 2026-08-27 — Add beta-specific CI rather than repurpose main CI

**Decision:** Use a dedicated `beta-guardrails.yml` workflow for `namecheap-beta-live` and keep broader product-area CI path-scoped.

**Reason:** Beta requires fast deterministic checks over the actual deployable tree without forcing unrelated Flutter/Android/root-backend suites to run for portal-only work. Product-area checks should run when their own paths change, while beta guardrails remain authoritative for portal source/runtime contracts.

## 2026-08-27 — Defer authenticated Playwright until fixture strategy exists

**Historical decision:** Do not add production-credential-dependent browser tests until explicit non-production fixture isolation exists.

**Reason at the time:** Recovery tests needed to be reproducible and safe.

**Status:** Superseded by the DUMMY-isolated authenticated regression decision below.

## 2026-08-27 — Use DUMMY-isolated authenticated regression, never MERD mutation

**Decision:** Authenticated regression is allowed when credentials/storage state remain external and all destructive writes are guarded to the exact DUMMY tenant. General authenticated audit may remain read-only; destructive workflows must abort before mutation if DUMMY identity/context is not proven.

**Reason:** This provides real business-path evidence without putting MERD production data at risk.

**Supersedes:** The earlier blanket deferral of authenticated Playwright. The safety principle remains; the fixture/isolation strategy now exists.

## 2026-08-27 — Stage-aware testing: do not over-automate an actively redesigned UI

**Decision:** While the beta webapp is actively being moved, redesigned, included/excluded and restructured, keep a compact safety net rather than exhaustive permanent UI-flow automation.

**Keep now:** runtime smoke, security/authorization/tenant contracts, stable business invariants, deployment guards and targeted verification of changed behavior.

**Defer by default:** brittle contracts around navigation labels, panel order, DOM structure, cosmetic placement and exhaustive CRUD click paths for workflows still being redesigned.

**Promotion rule:** changing features follow `BUILD/CHANGE → QUICK SMOKE → PERMISSION/SECURITY CHECK → DEPLOY → VISUAL/RUNTIME VERIFY`; promote important behavior to permanent regression when the workflow is reasonably stable or the product owner explicitly prioritizes it.

**Reason:** The current goal is product evolution. Tests should protect stable value and safety boundaries, not freeze an intentionally changing interface.

## 2026-08-27 — GitHub is the standalone source of truth and viable AI seed

**Decision:** The authoritative GitHub beta branch must contain enough curated knowledge for a fresh AI/chat/coding session with GitHub access to reconstruct the project and work safely without prior chat history, project memory, local workstation state or undocumented human context.

**Repository bootstrap:**

- root `AGENTS.md` is the entrypoint;
- `.ai/README.md` defines reading order and authority hierarchy;
- `.ai/invariants.md` stores binding rules;
- `.ai/memory.md` stores current state and product stage;
- `.ai/decisions.md` stores durable choices/supersessions;
- `.ai/playbook.md` stores reusable learned procedures;
- test/deployment/component docs beside code remain task-specific authority.

**Maintenance rule:** substantive work must update the knowledge layer when it changes reality. A future session should not need the conversation that produced a change.

**Reason:** Chat context is ephemeral and tool/session-specific. The repository must be self-sustainable, portable and independently understandable.

## 2026-08-27 — Require affected-path history and implementation evidence

**Decision:** Every substantive Beta code change must include a narrow preflight over the current affected source plus relevant Git path/component history. Questions about why prior work behaved or failed a certain way must inspect the relevant commit history/diffs before a confident root-cause answer. When the product owner explicitly requests implementation, analysis/planning alone is not completion if write/execution tools are available.

**Execution contract:** `.ai/task-gates.md` is a mandatory operating contract. It requires concrete implementation evidence for CODED/WIRED claims and deployment/runtime evidence for DEPLOYED/VERIFIED claims.

**Special UI lesson:** Cross-cutting design-system work must inspect both shared primitives and feature-specific styling/history. Token usage alone does not prove readability, semantic correctness or successful propagation.

**Reason:** Two preventable failures occurred: provenance was inferred from current source without checking the relevant history, and implementation requests risked being answered with analysis instead of actual repository changes. These gates make both failures explicit and recoverable in GitHub for future sessions.

## 2026-08-27 ? Apply Concept 7 connected brand kit across Beta UI

**Decision:** Concept 7 is the canonical MERDPOS Beta identity: an interwoven ribbon M representing connected retail channels and one continuous customer journey. The shared product palette is navy `#031B4B`, cyan `#12BDF3`, blue `#1D6CFF`, indigo `#586CFF`, violet `#8B2EFF` plus the supporting brand-kit colors recorded in `BRAND_IDENTITY_STANDARD.md`.

**Typography:** Space Grotesk is the display/brand heading preference; Inter remains the operational UI/body typeface.

**UI application:** The dark navy navigation rail, light workspace, blue functional accent and subtle connected-path treatments carry the brand through the product. The cyan?blue?violet gradient is reserved for identity moments and must not replace success/warning/danger/info semantics. Brand rollout belongs in shared tokens, shared design-system/shell ownership and the isolated `assets/brand/brand.css`, not copied feature CSS.

**Outcome:** The visual system should reinforce the product promise `SMARTER ? FASTER ? TOGETHER` and the business outcome of better customer satisfaction through omnichannel integration without adding decorative friction to operational workflows.

**Supersedes:** The earlier 2026-08-27 interim brand color values (`#111827`, `#2563EB`, `#4F46E5`, `#7C3AED`) in the first connected-brand standard.

## 2026-08-28 - Mobile UX uses One UI interaction principles, MERDPOS visual identity

**Decision:** MERDPOS phone layouts use the supplied Samsung One UI guidelines as an interaction reference, not as a visual clone. MERDPOS branding, semantic tokens and authorization remain canonical.

**Mobile contract:** primary bottom navigation is role-aware and limited to four high-frequency destinations; secondary client/account/theme/About/system utilities belong in a thumb-reachable bottom sheet; phone pages use a consistent title/helper/client-context header with contextual subtabs; safe gutters target 24px and touch targets remain at least 48px; desktop data tables adapt to labelled card rows on phones unless a feature owns a stronger mobile renderer; important actions and loading states stay reachable and local to the affected content.

**Verification rule:** cross-cutting mobile changes require a 390px runtime smoke plus authenticated light/dark rendered verification. The contract protects usability outcomes (overflow, reachability, readable hierarchy, utility access), not exact cosmetic coordinates.
## 2026-08-28 ? Adopt five-color MERDPOS brand master palette

**Decision:** The canonical MERDPOS brand master palette is restricted to White `#FFFFFF`, App Background `#F5F7FC`, Brand Navy `#031B4B`, Brand Cyan `#12BDF3`, and Violet `#8B2EFF`.

**Runtime application:** `design-tokens.css` owns the five master literals. Primary interactive emphasis derives from Violet; Navy owns structural/navigation foundations; Cyan is the identity/accent color; White and App Background own light surfaces. Hover/active/dark/border/muted/overlay treatments must derive from those masters rather than introducing additional brand literals.

**Semantic exception:** Success, warning, danger and information remain dedicated operational semantic colors for meaning and accessibility. They are not additions to the brand master palette.

**Artwork exception:** Approved logo raster assets stay unchanged. Any baked-in intermediate gradient pixels in those supplied assets do not become UI palette tokens.

**Supersedes:** The 2026-08-27 connected-brand palette decision that treated Blue `#1D6CFF`, Indigo `#586CFF` and supporting extended spectrum values as canonical brand colors.

## 2026-08-29 — DEV UI Studio is a preview/handoff tool, not a source editor

**Decision:** MERDPOS Beta provides a DEV-only UI Studio for rapid visual iteration on the currently rendered application. It may preview styling, visibility and DOM placement changes in the current browser and accumulate them into a structured change-set.

**Safety boundary:** UI Studio must require an actual DEV identity and must not call application mutation APIs, write database state, modify repository source, or affect other users. Its draft is local browser state and must be visibly labelled `PREVIEW ONLY`.

**Handoff contract:** A UI Studio change-set is design intent, not deployed code. Applying it to MERDPOS still requires editing the canonical owner files, running normal guards, deploying the authoritative branch and visually verifying the affected runtime.

**Reason:** This preserves fast interactive UI iteration without creating a second visual source of truth or bypassing MERDPOS authorization/deployment discipline.

## 2026-08-29 — UI Studio mobile editing stays compact and scope is explicit

**Decision:** UI Studio scope is part of each style patch: `element`, `component`, `matching`, or `pages`. Broad scopes preview shared CSS intent directly instead of requiring repeated per-page edits. DOM movement remains element-only.

**Mobile contract:** At phone widths UI Studio uses a compact floating quick-action wheel and opens the inspector/change sheet only on demand, capped at 46dvh so the underlying MERDPOS screen stays visible. The wheel vendors `yandongCoder/circular-menu` 1.0.6 under its declared ISC license; MERDPOS owns the wrapper, tokens, actions and safety boundary.

**Reason:** The visual editor is useful only when the product remains visible while editing, and change-set scope must represent design intent without ChatGPT inferring global rules from duplicated exact selectors.

## 2026-08-29 - UI Studio uses native draggable radial controls only

**Decision:** UI Studio has no persistent inspector/change-set panel on desktop or mobile. The rendered MERDPOS application is always the canvas; one draggable `UI` hub is the only persistent Studio chrome.

**Interaction:** The hub opens nested radial layers for Select, Color, Layout, Scope, Move, Hide/Show, Changes and Exit. Color opens the five-color master palette as a second ring. Changes exposes Copy/Chat/Undo/Reset/Clear from the circle. Scope remains explicit (`element`, `component`, `matching`, `pages`), and DOM movement remains element-only.

**Runtime:** The radial controller uses the browser Popover API plus local HTML/CSS/JavaScript and inline SVG icons. Item coordinates and opening direction are calculated in JavaScript so the menu adapts to visible viewport space without experimental CSS functions or third-party menu runtimes.

**Safety:** The controller remains actual-DEV-only, preview-only, local-browser state. Copy/Chat are clipboard handoffs; Studio still performs no source, API, or database writes.

**Supersedes:** The earlier 2026-08-29 mobile contract that used `yandongCoder/circular-menu` plus an inspector/change sheet. The subsequently explored `react-circular-menu` approach was not promoted to source and is not part of the runtime.


## 2026-08-29 - Material 3 is the component reference; Material Symbols are vendored locally

**Decision:** MERDPOS uses Material 3 as the web component/interaction reference while retaining MERDPOS tokens, typography, navigation identity and the five-color master palette. A portal-wide `@material/web` runtime dependency is not introduced while Material Web is in maintenance mode.

**UI Studio implementation:** Hand-drawn inline controller icons are replaced with locally vendored Google Material Symbols from `google/material-design-icons`, pinned in `assets/vendor/google-material-symbols/NOTICE.md` with Apache-2.0 attribution. No icon font or CDN request is required.

**Interaction additions:** Studio supports element-only inline text preview editing, cross-panel `matching` scope, temporary Reveal/Restore for hidden preview patches, and adaptive radial geometry that keeps controls inside mobile viewport bounds.

**Safety:** These remain DEV-only local preview capabilities and do not change source, APIs, database state, authorization, payroll or finance behavior.

## 2026-08-29 - UI Studio uses nested sector radial navigation and local design history

**Decision:** The DEV UI Studio controller uses connected nested radial **sectors** rather than detached circular buttons. Root sectors are Select, Edit, Changes and Exit. Parent sector rings remain visible and dimmed while child rings open; submenu ownership is signalled by an expand marker; the central hub becomes Back inside nested layers. The interaction follows the supplied radial-menu references while One UI remains the hierarchy/reachability reference.

**DEV-tool visual exception:** Studio chrome may use a bright high-chroma tool palette distinct from the five-color MERDPOS product master palette. This exception is confined to actual-DEV Studio chrome and must not leak into product UI tokens/components. MERDPOS remains the branded canvas.

**Review workflow:** Studio may record local element comments, add safe preview-only Text/Button/Card/Divider nodes, select navigation/transient-menu controls without activating them, and maintain navigable local history. History stores page/panel/selector context and can return DEV to the recorded panel/element. These are local design metadata/preview patches and remain subject to the existing no-API/no-DB/no-source-write safety boundary.

**Shell interaction:** Desktop navigation and mobile shell utilities must ignore pointer interactions originating from `[data-ui-studio]`, so switching attention back to Studio does not collapse an expanded rail or close an opened utility surface before DEV can select its contents.

**Supersedes:** The prior 2026-08-29 floating circular-button geometry. Its safety, scope, Material Symbol and local-preview contracts remain binding.

## 2026-08-29 - UI Studio radial geometry uses one fixed stage on touch devices

**Decision:** The Studio hub and radial SVG must share a single fixed-position stage and therefore one center coordinate. They must not be independently fixed/positioned siblings.

**Mobile viewport:** `visualViewport` resize/scroll changes re-render and re-clamp the open radial menu. This protects Samsung/Android browser-chrome changes, rotation and narrow visual viewports from hub/ring drift.

**Readability/touch:** Sector bands are deliberately thicker and Studio Material Symbols/labels are larger than the earlier compact prototype. Phone touchability and label legibility take priority over maximizing the number of tiny sectors in a fixed diameter.

**Regression evidence:** Galaxy-sized and 681x598 browser checks assert hub/menu concentricity, readable label/icon bounds, visible-sector hit targeting and viewport containment.

## 2026-08-29 - UI Studio radial children require an explicit shared origin

**Decision:** The hub and radial menu must be positioned from one fixed stage and both absolute children must declare `left:0; top:0`. Sharing a parent alone is insufficient because an absolutely positioned element with auto insets may use a browser-defined static-position origin.

**Touch contract:** Phone regression coverage must use a real touch-enabled browser context and touchscreen taps at the rendered sector coordinates, not mouse-only activation helpers.

**Reason:** A 394x512 Chrome mobile screenshot showed the hub and ring diagonally offset even after the shared-stage refactor; the missing explicit child insets were the remaining geometry bug.

## 2026-08-29 - UI Studio touch drag preserves contact offset

**Decision:** A Studio drag must preserve the offset between the initial pointer contact and the visual hub center. Pointer movement updates the hub to `pointer + initialOffset`; it must never assign the raw pointer coordinate directly to the hub center.

**Reason:** On a real phone, touching near the edge of the Studio hub caused the first move to snap the hub toward the finger, making the contact point feel like the menu origin/corner and breaking touch confidence.

## 2026-08-29 - UI Studio drag click suppression is event-cycle scoped

**Decision:** The click-suppression flag used after a drag must expire on the next event-loop turn even if the browser emits no synthetic click for that drag.

**Reason:** Real touch validation showed that Android/Chromium may omit the post-drag click. A persistent boolean would then swallow the user's next intentional tap on the Studio hub.

## 2026-08-29 — UI Studio active-ring readability hierarchy
- Active radial rings own the readable labels and large touch slices; ancestor rings collapse to compact icon-only breadcrumbs.
- Active Material Symbol size is 44 SVG units and active labels are 24px desktop / 25px mobile in the 500-unit viewBox.
- Touch/pen hub activation is handled on pointer-up so Android does not depend on a synthesized click after drag; duplicate clicks are suppressed.

## 2026-08-29 — UI Studio single-ring drill-down

- Adopt the uploaded radial prototype's interaction architecture: one active operational ring at a time; selecting a parent replaces that ring and the center hub becomes contextual Back.
- Keep MERDPOS as the canvas and preserve Studio's existing DEV-only preview engine, draggable hub, comments, Add, History, selection scopes, Copy/Chat handoff, and sidebar/transient-control selection interception.
- Studio chrome uses dark neutral surfaces independent of product branding: `#1A1A2E` background/history/toast, `#25253D` normal sectors, `#30304C` active/hover sectors, white labels and `#A8AEC1` muted hub text.
- Bright action accents identify functions but do not recolor entire sector surfaces.
- Color shows Background/Text targeting and the five MERDPOS master colors first; More opens the extended rotating palette.
- Numeric Padding/Margin/Gap/Radius/Font use two radial controls (increase/decrease) with the current value shown in the center hub. Width retains direct presets.

## 2026-08-29 — UI Studio14 prototype visual parity
- Match the approved uploaded HTML geometry exactly for the radial controller: `760×760` viewBox, hub radius `72`, ring radii `84 → 220`, and zero wedge gaps.
- Use dark neutral sector surfaces (`#25253D`, active `#30304C`) with bright semantic color on the Material Symbol itself; do not use colored circular icon backplates.
- Align each icon and label on one X axis: icon center is 16 units above the slice midpoint and the label is 38 units below the icon center.
- Keep the transparent area outside the visible ring non-interactive and do not animate ring-shell resizing, preventing transient touch/geometry drift during viewport changes.

## 2026-08-29 — UI Studio touch click retarget guard
- Touch/pen hub activation remains pointer-up driven. When opening or dragging repositions the Studio under the same gesture, consume the synthetic click at document capture so it cannot retarget a newly appeared radial wedge or MERDPOS control.
- A subsequent new pointer-down clears stale suppression before the next intentional gesture.

## 2026-08-30 � Unified desktop/mobile bottom navigation

- Desktop no longer uses the left navigation rail. Authenticated desktop and mobile share the same four primary destinations in a persistent dark bottom dock: Home, Operations, Reports and Finance.
- Secondary destinations remain reachable through contextual sub-navigation above the dock.
- Working client, signed-in user/role, DEV/system links and account/app utilities are progressively disclosed from one circular account control rather than occupying primary navigation.

## 2026-08-30 � UI Studio15 center-hub interaction

- Studio sectors are icon-only with 48-unit Material Symbols; permanent wedge labels are removed.
- The amber center hub is the primary action surface: fine-pointer hover opens the menu, wheel input cycles enabled actions, click executes the armed action, and an unarmed click is Back inside a submenu.
- Undo is available at every radial level. Hide/Show is state-aware, individual history rows can be deleted, and Move promotes inner content to a recognized component root for source/destination targeting.

## 2026-08-30 — Studio16 selection workflow and DEV role presentation
- UI Studio is visible by default for actual DEV identities. The radial workflow is selection-first: no selection exposes Select; one selection exposes Select/Add/Edit/Move/Comment/Hide-Show, with Undo appended on every level and no Exit wedge.
- Select is replacement-only: exactly one rendered element is selected at a time. Add chooses a preview element type then Above/Below/Left/Right; Move chooses a destination first then Top/Bottom/Left/Right; Edit is limited to Color → Palette and Layout.
- History remains local and is opened from the hub change-count badge; individual history entries remain deletable.
- The account sheet orders actual signed-in identity first, Working client second, and a DEV-only Current role selector third. DEV and Clients shortcut buttons are not duplicated into the account sheet.
- Current role is a universal DEV preview. Admin/Super/User becomes the effective website role for server rendering, dashboard layout/data, normal API permission checks and feature actions. Authentication/audit identity remains the real DEV account; only explicit DEV tools such as UI Studio and Working client switching use the actual DEV identity.
## 2026-08-30 — Studio17 dashboard integration and controls
- Current role offers Developer/Admin/Super/User. Developer means the actual DEV website view; Admin/Super/User remain universal effective-role previews.
- Studio root begins with Minimize, Select, Edit Dashboard and Settings. Desktop Minimize docks a restore control immediately to the right of the account circle.
- Edit Dashboard delegates to the existing Dashboard Builder for the current effective role. Its persistent layout API is an explicit actual-DEV tool exception; ordinary Studio patches/comments/settings remain local preview state.
- Dashboard widget Describe writes/replaces a widget-keyed Studio comment/context note and includes that context in History/Copy/Chat.
- Studio Settings persist browser-locally: Color controls Studio accent chrome only; Size independently scales Studio Font and Icon controls.


## 2026-08-30 ? Studio18 downward role-scoped visual drafts
- New Studio style patches carry explicit effective-role scope. Admin inherits downward to Admin/Super/User; Super to Super/User; User to User; Developer stays DEV-only. This is preview/design inheritance, not authorization.
- Palette background selection owns readable foreground pairing through computed luminance contrast; manual per-swatch brightness flags are not authoritative for text visibility.
- Studio radial Size is one geometry setting controlling center diameter and slice thickness together. Disabled radial actions are skipped by wheel candidate selection.

## 2026-08-30 ? Deploy script self-refresh
- The Namecheap beta deploy script must restart itself under the inherited deploy lock when `git pull` changes `scripts/deploy_namecheap_beta.sh`. This prevents an older in-memory deploy process from validating a freshly pulled runtime against stale cache-version guards.
- 2026-08-30: Studio19 keeps dashboard authorization dual-gated by widget visibility + underlying data permission, but policy relaxation now materializes only widgets that become newly allowed in that save. DevStudio hides the redundant dashboard + control, and radial Button Size is independent from Icon Size while scaling hub and ring distance/thickness together.


## 2026-08-30 - Studio20 scoped dashboard dependencies and account-owned enable state
- Dashboard widget data dependencies are capability inputs to the dashboard endpoint, not whole-application grants. A role can receive an approved widget such as Who is working now without receiving the Workforce page/navigation/API surface.
- Dashboard payloads must be least-data for the widgets actually allowed; count-only widgets must not receive roster payloads merely because they share a dependency family.
- Actual DEV enables/disables DevStudio from the account sheet toggle. The account avatar uses the Studio accent while enabled; the separate restore/minimized icon and duplicate DEV launcher are retired.
- Desktop selection is hover affordance then Select; the radial Select action is replaced by Unselect after selection. Touch selects directly.

## 2026-08-30 - Studio21 context-click radial controls
- DevStudio enable state is independent from radial visibility. Enabling Studio does not display the radial until a target is selected.
- Desktop selection is context-click driven: right-click selects a MERDPOS target and opens the radial at the pointer. Hover Select and hover-to-open are retired.
- While open, wheel input anywhere cycles enabled radial actions, middle-click activates the armed action, right-click is Back (root Back closes), and left-click outside hides the radial without disabling Studio.
- Touch direct-selection behavior remains available.

## 2026-08-30 - Studio22 icon URL resolution
- Studio icon asset URLs are resolved against `document.baseURI` before being used by SVG images or CSS masks.
- Do not use stylesheet-relative `assets/vendor/...` custom-property URLs for the Studio center icon, because external CSS resolves them as `assets/assets/vendor/...` in live beta.

### 2026-08-30 - Studio23 interaction ownership
- DevStudio enabled state is independent from radial visibility; Ctrl+D and the account toggle share the same persisted state/event path.
- Dashed hover targeting is available whenever Studio is enabled and the radial is closed.
- Right-click selection docks the radial away from the selected target using the farthest viewport-safe corner.
- While the radial is open, Studio owns mouse navigation; outside left-click dismisses and is consumed rather than activating MERDPOS.

## 2026-08-30 - Shared dashboard status pills
- Reuse the existing client freshness pill grammar for Current User and DEV Current Preview Role.
- Current User presence is actual-identity/self-only and does not inherit DEV preview permissions or expose workforce data.
- Preview-role status is presentation-only; account role changes persist a local switch timestamp before reload.
- Pill cache generation is `20260830pills1`; live deployment must wire the matching management, design-system, and omnichannel identity assets.


## 2026-08-30 - Studio24 global Developer history
- Restore the DevStudio Changes branch (History / Copy JSON / Chat / Undo / Reset / Clear) after the Studio21-23 interaction refactor made it unreachable.
- DevStudio preview/history is client-scoped server state via migration 035, not browser-only history. Actual DEV identity is preserved for this dedicated API even while Current role previews ADMIN/SUPER/USER; ordinary product authorization still follows the preview role.
- Shared writes use optimistic revisions and CSRF. Cross-machine conflicts refresh to the latest global revision rather than silently overwriting.
- Each history event stores a patch mutation. Trash deletion is global and recomputes active preview patches by replaying surviving events.
- Active changed elements expose a Studio-owned green change dot; hover/focus opens a branded viewport-safe per-element history card with trash actions. Studio-owned marker mutations are ignored by the application MutationObserver.


### 2026-08-30 - Studio25 cursor guidance and release metadata
- Selected DevStudio elements expose a cursor-follow action pill. It begins with `Select action…` and mirrors the wheel-armed radial slice with icon + label.
- DevStudio change dots are accent-token driven; no change marker may retain a stale hard-coded color after an accent switch.
- About MERDPOS reads deployment-generated Git metadata from `.beta_release.json`. The overall MERDPOS ref is deployed HEAD; DevStudio ref is the newest commit touching `ui-studio.js`/`ui-studio.css`; dates come from Git commit dates; highlights are the three newest commit subjects.


## 2026-08-31 - Studio26 Developer-master inheritance and preview requests
- Developer is the authoritative visual master: DEV implemented patches target DEV/ADMIN/SUPER/USER; Admin targets Admin/Super/User; Super targets Super/User; User targets User. Stored `roleTargets` are normalized from `roleScope` so old DEV-only stamps do not remain isolated.
- Lower-role previews may specialize existing inherited elements, but new element placement cannot originate below DEV. Add in ADMIN/SUPER/USER records a proposal request with its preview/placement context; Comment/Describe in those previews records a request note. Both declare `implementationOrigin: DEV` and remain non-production Studio artifacts until implemented from the master.
- Role-layer Undo/Reset/Clear cannot delete upstream template work. Downstream overrides are applied after upstream patches independent of journal insertion order. This is a presentation/design rule only; authorization remains actual identity → preview role → LOA/permission policy.
- Studio JSON handoff advances to v4 for request metadata; global revision/history synchronization and history-step deletion remain unchanged.


### 2026-08-31 - Studio27 symmetric inheritance reversal and image context
- DEV master mutations and reversals are one inheritance decision: an inherited DEV Hide must disappear from Admin/Super/User when that DEV patch is undone. Exact legacy child duplicates without explicit override metadata are redundant and collapse into the upstream master value.
- New lower-role implemented patches carry `explicitOverride:true`, so an intentional Admin/Super/User override remains independent when the parent master later changes or is undone.
- Studio comments use a multiline composer and may attach image context. Upload is actual-DEV-only/CSRF-protected; PNG/JPEG/WebP/GIF are validated and SVG is sanitized. Files live in private backend runtime storage and are exposed only through 256-bit token read URLs for ChatGPT/context handoff.
- Studio JSON/Chat handoff is version 5 and includes attachment metadata/URLs. Same-browser-profile windows synchronize Studio appearance settings through localStorage `storage` events; settings remain local to that browser profile rather than server-global.

- 2026-08-31: DevStudio implementation patches follow `patch -> canonical source -> tests -> deploy -> live verification -> programmatic active-patch removal`. Patch cleanup is part of completion; global history remains intact as audit. Never clear unrelated or unverified Studio patches.

## 2026-08-31 - Shared analytics/dashboard interaction model
- Reuse the useful dashboard-template and Google Charts concepts without importing React/Tailwind/Google Charts as runtime dependencies.
- MERDPOS owns one feature-scoped analytics runtime with typed datasets/views and responsive SVG bar/line/donut renderers.
- Charts emit `merdpos-chart-select`; Dashboard Builder may translate a permitted chart selection into coordinated server-side filters/drill-down.
- Dashboard Store and reporting-period filters are API inputs, not client-only masking. Backend authorization/data dependencies remain authoritative.
- Store-filter choices must be scoped to data the effective role is already permitted to know. Own-attendance-only users cannot discover the client's complete Store directory through dashboard filters.
## 2026-08-31 - Studio28 unresolved patch inbox and LLM receipt
- DevStudio is an unresolved implementation inbox, not an audit-history viewer. The browser receives active patches only; history remains backend-only.
- Stable `patchId` is the identity for LLM round trips. Copy emits v6; Paste LLM Receipt records status transitions and removes only `confirmed_applied` patches.
- Audit history is retained for learning/compliance and cannot be deleted from DevStudio.

## 2026-08-31 - Studio29 palette patches and radial dismissal
- MERDPOS Palette changes are first-class global unresolved DevStudio patches, not direct edits to canonical `design-tokens.css`.
- Developer can view/edit/reorder/add/delete palette entries in the radial Settings branch; preview overrides brand variables and chart slots where possible.
- Palette implementation still follows Copy â†’ canonical source â†’ test â†’ deploy â†’ live verify â†’ receipt confirmation.
- A full radial dismissal must deselect the prior element. Move is the only intentional preserve-selection hide.
- Working client and Current role utility contexts are minimizable; the old DEV explanatory helper is removed.
