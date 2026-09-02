# MERDPOS DevStudio — Current Contract

DevStudio is an actual-DEV-only design preview, unresolved implementation inbox, and LLM handoff tool. MERDPOS remains the product canvas and canonical source remains GitHub on `namecheap-beta-live`.

This document describes the **current** contract. Historical Studio24–27 behavior is preserved in `.ai/decisions.md`, archived work packets and Git history; it is not a second current specification.

## Safety boundary

- Actual DEV identity is mandatory. Preview role, LOA or permissions alone do not grant DevStudio.
- DevStudio may write only its dedicated client-scoped Studio state/audit subsystem through actual-DEV-only, CSRF/revision-checked Studio APIs.
- Studio state/audit writes are design-workflow metadata, not operational MERDPOS business mutations.
- DevStudio must not directly edit repository source or mutate payroll, finance, workforce, stores or ordinary product authorization data.
- Backend audit/history is retained for audit/learning and is not exposed as a browsable/deletable DS History UI.
- A patch becomes real MERDPOS behavior only after canonical implementation, tests, deployment, live verification and receipt confirmation.

## Global unresolved inbox

Current browser sessions receive only global unresolved patches.

Each active patch has a stable `patchId` and status:
- `pending`
- `implementing`
- `implemented`
- `blocked`

`confirmed_applied` is terminal for the working inbox: the matching patch leaves active DS state while backend audit remains.

Copy payloads use JSON v6 with:
- `global: true`
- synchronized `revision`
- `workflow: unresolved-patches`
- unresolved `patches` only

No audit-history array is included in the DS payload or Copy for ChatGPT handoff.

## LLM round trip

Copy for ChatGPT instructs the LLM to process only unresolved patches and return a receipt using stable patch IDs.

Receipt contract:

```json
{
  "merdposDevStudioReceipt": 1,
  "sourceRevision": 62,
  "updates": [
    {
      "patchId": "patch-example",
      "status": "confirmed_applied",
      "commit": "<deployed commit>",
      "verification": "passed",
      "note": "Implemented in canonical MERDPOS source and live verified."
    }
  ]
}
```

Paste LLM Receipt applies revision-checked status transitions. It must never confirm a patch that was not actually implemented and verified.

Standard implementation lifecycle:

`active patch → canonical source → tests → deploy exact commit → live verification → receipt → confirmed_applied`

Patch cleanup/confirmation is the **last step**. Never clear unrelated or unverified patches.

## Role inheritance and proposals

Developer is the visual master:
- DEV → DEV, ADMIN, SUPER, USER
- ADMIN → ADMIN, SUPER, USER
- SUPER → SUPER, USER
- USER → USER

Lower roles may specialize inherited existing elements. Lower-role Add or Comment/Describe actions are proposals/requests anchored to that preview; real implementation must originate from Developer master. Authorization is unchanged by this presentation inheritance.

## Selection and radial behavior

- DevStudio enable state is independent from radial visibility.
- Fine-pointer selection is context-click driven; touch selects directly.
- Full radial dismissal clears the previously selected MERDPOS element. This includes outside dismissal, Ctrl+D disable and Minimize.
- Move destination mode is the explicit exception: its temporary radial hide preserves the source selection until destination placement completes or is cancelled.
- Studio interactions are consumed so they do not accidentally activate the underlying MERDPOS control.
- The draggable hub/radial share one viewport-clamped stage and preserve touch contact offset.
- Studio appearance settings remain browser-local and synchronize across same-profile windows where supported.

## Current radial branches

The current root keeps DevStudio controls compact and context-aware. Core branches include Settings, Changes, Edit Dashboard and shared Undo; selected-element actions add Edit, Move, Add, Comment and Hide/Show as appropriate.

Changes is an unresolved-inbox workflow, not History. It exposes current patch actions such as Copy for ChatGPT and Paste LLM Receipt.

## Comments and image context

Comments support multiline text and optional image context. Up to six PNG/JPEG/WebP/GIF/SVG files may be attached per note. Upload is actual-DEV-only and CSRF-protected; SVG is sanitized.

Uploaded context lives in private backend runtime storage. A random token read URL may be included in ChatGPT handoff so the LLM can fetch the image without MERDPOS credentials or directory access.

## MERDPOS Palette

Settings → **MERDPOS Palette** exposes the current brand palette in Developer preview.

Palette operations:
- View
- Edit
- Move Up
- Move Down
- Add
- Delete

A palette edit creates or replaces one global unresolved `kind: palette` patch. The server validates palette IDs, CSS token names, hex values and the maximum item count, and forces palette patches to DEV/global scope.

Preview applies CSS custom-property overrides and remaps chart color slots where possible. Canonical `design-tokens.css` remains unchanged until the palette patch is implemented through the normal lifecycle.

### Palette-standard escalation

The binding product standard is still exactly five master colors unless the product owner explicitly changes that standard. DevStudio may preview a different set, but implementing a palette patch that adds/removes a master color is a **design-standard change**, not an ordinary token edit.

Such an implementation must update together:
- canonical `design-tokens.css` master tokens;
- `.ai/invariants.md` brand-palette rule;
- `browser_tests/brand-palette-runtime.spec.js`;
- runtime/deploy validators;
- affected brand/component documentation.

Do not weaken the five-color guard simply because an unresolved preview patch conflicts with it.

## Account utility integration

The actual-DEV account summary displays compact unresolved DevStudio counters. The supplied Create Folder and Folder Match SVGs are canonical assets for these metrics.

Working client and Current role utility contexts are independently minimizable. Their collapsed state persists locally. The retired verbose DEV role helper text must not return.

## Audit retention

`ui_studio_history` is backend audit/learning data. DevStudio does not expose a History browser, a browser history getter, trash/delete controls or history in Copy for ChatGPT.

Status transitions, receipt application and other Studio mutations remain auditable on the backend. Operational product APIs and permissions remain separate.

## Deployment/release contract

Current Studio cache generation is `20260831studio29` until deliberately superseded. Loader wiring, runtime validator and Namecheap deploy guards must advance together when the generation changes.

Relevant permanent coverage lives primarily in:
- `browser_tests/ui-studio-runtime.spec.js`
- `browser_tests/shell-account-runtime.spec.js`
- `backend/cli/validate_ui_studio_inheritance.php`
- `backend/cli/validate_beta_runtime_contract.php`
- `backend/cli/validate_ai_continuity.php`

Passing source/CI checks does not itself mean a visual behavior is live-verified. Use the project lifecycle and deployment marker/runtime evidence.


## Structure / Layers editor

Developer sessions expose a Structure action in the DevStudio radial menu. It opens a persistent layers tree over the currently visible portal page and uses MERDPOS semantic nodes instead of raw DOM noise:

- Page
  - Section
    - Container
      - Text
      - Metric Card
      - Chart
      - Employee Status
      - Data Table

A Section may also own content directly (for example `Section > Data Table`). Page accepts Sections; Sections accept Containers or content modules; Containers accept nested Containers or content modules. Leaf content modules do not accept children.

Tree selection is synchronized with the existing DevStudio live-preview selection. Drag/drop uses before, inside, and after placement and records the same canonical move patches used by radial Move. Add Above / Inside / Below uses the canonical add patch engine. Duplicate creates a sanitized preview clone with duplicate IDs/form ownership attributes stripped. Existing source elements use Hide rather than destructive deletion; elements created by DevStudio can be removed from the current preview layer.

The Structure runtime and stylesheet are loaded only for actual DEV sessions. On narrow screens the layers panel becomes a bottom sheet. The panel is an editor view only: patch history, role scope, copy-for-ChatGPT, receipt status, and deployment truth remain owned by DevStudio's existing canonical state.

### Structure interaction stability

Structure action menus and the Add chooser are stateful editor surfaces. They must remain open and clickable across unrelated live portal mutations; the Structure observer must not rebuild active action DOM underneath the pointer. A detached-control browser failure is treated as a product defect even if a retry later passes. Permanent regression coverage must exercise a portal mutation while the action menu is open.
