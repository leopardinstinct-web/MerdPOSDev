# DevStudio Structure interaction stability — 2026-09-03

## Root cause

The Structure `•••` actions menu used only the rendered element's `hidden` attribute as its open/closed state. Meanwhile the Structure `MutationObserver` rebuilt the entire tree whenever live MERDPOS panels changed `class` or `hidden` attributes. Dashboard/runtime mutations could therefore replace the action DOM between pointer events, making `•••` appear non-functional or causing the subsequent action button to detach.

The first full Chromium run exposed this directly: the `Add inside` control became unstable/detached. A later retry passed only because the race did not reproduce on that attempt. The retry was incorrectly treated as sufficient evidence.

## Canonical fix

Product implementation commit: `07ac62c03b623d4d5afb93e60b3f434408345b66`.

- `openActionsKey` is now durable Structure editor state.
- Rendering restores the correct action menu from editor state.
- The live-portal MutationObserver does not rebuild active Structure action or chooser DOM mid-interaction.
- Closing/finishing chooser actions clears the interaction state and performs an explicit refresh.
- The regression now mutates the underlying portal after opening `•••`, waits beyond the observer debounce, verifies the menu is still visible, and then activates `Add inside`.

## Permanent acceptance rule

A detached, unstable, or unexpectedly hidden DevStudio control in browser testing is a real interaction defect. A retry passing is not closure. Root-cause the race and add a mutation-under-interaction regression before accepting the behavior.

The same rule is recorded in `.ai/playbook.md` and `timesheet_portal/UI_STUDIO.md` by the canonical fix commit.
