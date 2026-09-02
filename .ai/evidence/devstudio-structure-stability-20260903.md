# DevStudio Structure interaction stability — 2026-09-03

## Root cause

The Structure `•••` actions menu used only the rendered element's `hidden` attribute as its open/closed state. Meanwhile the Structure `MutationObserver` rebuilt the entire tree whenever live MERDPOS panels changed `class` or `hidden` attributes. Dashboard/runtime mutations could therefore replace the action DOM between pointer events, making `•••` appear non-functional or causing the subsequent action button to detach.

The first full Chromium run exposed this directly: the `Add inside` control became unstable/detached. A later retry passed only because the race did not reproduce on that attempt. The retry was incorrectly treated as sufficient evidence.

A second delivery defect was then found: after the runtime race was fixed, `management.js` still loaded the Structure runtime with the pre-fix cache key `v=20260902structure1`. An existing browser session could therefore keep executing the broken cached script even though the corrected file was present on the server.

## Canonical fixes

Interaction-state implementation commit: `07ac62c03b623d4d5afb93e60b3f434408345b66`.

- `openActionsKey` is now durable Structure editor state.
- Rendering restores the correct action menu from editor state.
- The live-portal MutationObserver does not rebuild active Structure action or chooser DOM mid-interaction.
- Closing/finishing chooser actions clears the interaction state and performs an explicit refresh.
- The regression mutates the underlying portal after opening `•••`, waits beyond the observer debounce, verifies the menu is still visible, and then activates `Add inside`.

Cache-delivery implementation commit: `2c980a4b31017dafc1d871c08ee261164cdd16d7`.

- The Structure JS loader cache key advances to `v=20260903structure2`.
- The runtime contract binds the new cache key plus durable `openActionsKey` and active-interaction observer guard.
- The Namecheap deploy contract now requires both Structure assets and verifies the corrected live runtime behavior before the release marker can be written.

## Permanent acceptance rules

A detached, unstable, or unexpectedly hidden DevStudio control in browser testing is a real interaction defect. A retry passing is not closure. Root-cause the race and add a mutation-under-interaction regression before accepting the behavior.

Behavioral changes to browser-loaded DevStudio assets must advance their loader cache/version key in the same change. Server-side file replacement alone is not sufficient delivery evidence; deployment guards should bind both the cache key and corrected behavior.

The same rules are recorded in `.ai/playbook.md` and `timesheet_portal/UI_STUDIO.md`.
