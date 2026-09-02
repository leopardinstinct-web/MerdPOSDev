# Divi GUI study -> MERDPOS DevStudio canvas editing — 2026-09-03

## Observation method

Read-only inspection of the live Divi Visual Builder running on the user's Namecheap-hosted WordPress site. No edit, save, publish, delete or module selection was performed by ChatGPT.

## GUI findings

- The live website remains visually dominant; editor chrome is layered over the actual page rather than replacing it with an IDE-like workspace.
- Structural hierarchy is communicated directly on canvas with contextual colored controls and insertion points; the layers/tree model is a secondary power view.
- Object-local controls sit beside the selected structure rather than requiring a trip to a distant inspector.
- `+` insertion affordances encode placement spatially, so the user chooses where before choosing what.
- The observed `Insert Module` surface uses a focused floating panel with a clear title, New Module / Add From Library navigation, immediate Search, a scrollable two-column icon+label module catalog, and the live page visible behind it.
- The Divi library tab corresponds to real saved/reusable content. MERDPOS must not expose a library affordance until it has an actual persistence contract.

## MERDPOS adaptation

Use MERDPOS vocabulary and palette, not Divi branding: Page > Section > Container > semantic MERDPOS components. Structure remains the layers/power view; canvas outlines, object-local actions and contextual `+ Section` / `+ Container` / `+ Component` insertion points become the default visual editing affordances while Structure is open. `+ Component` opens a searchable `Insert Component` grid containing only canonical MERDPOS component types. All operations continue through the existing DevStudio patch/history engine.

## Interaction scheduling finding

The canvas regression exposed a second-order race: guarding the MutationObserver is insufficient if a refresh timer was queued before pointerdown. Canvas pointerdown now cancels stale queued refresh work, and the refresh scheduler itself re-checks all active Structure interaction state before rendering. A pending timer may defer, but it may not replace controls underneath an active pointer interaction.
