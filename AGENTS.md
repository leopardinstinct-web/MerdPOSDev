# MERDPOS Beta — AI Bootstrap Entry Point

This repository is the standalone source of truth for MERDPOS Beta.

A fresh AI/chat/coding session with GitHub access must be able to reconstruct the project without relying on prior chat history, project memory, local files, or undocumented human context.

## Mandatory startup

Before planning or changing MERDPOS Beta, read in this order:

1. `.ai/README.md` — bootstrap manifest, authority hierarchy, reading map and update discipline.
2. `.ai/invariants.md` — binding product/security/runtime rules.
3. `.ai/memory.md` — current implementation state, deployment/testing stage and active priorities.
4. `.ai/decisions.md` — durable architectural/product decisions and superseded decisions.
5. `.ai/playbook.md` — learned operational procedures for coding, validation, deployment and regression work.

Then read the task-specific authoritative files referenced by `.ai/README.md`.

## Source-of-truth rule

- GitHub on the authoritative beta branch is primary.
- Chat history is optional context, never required state.
- Local PC files, browser state, credentials and temporary test outputs are not canonical.
- Historical handovers/recovery notes are evidence only when they conflict with current `.ai` state, current code or later decisions.
- Never assume a remembered fact is still true when the repository can verify it.

## Authoritative beta scope

- Repository: `leopardinstinct-web/MerdPOSDev`
- Branch: `namecheap-beta-live`
- Deployable tree: `namecheap_beta_live/`
- Portal: `namecheap_beta_live/timesheet_portal/`
- Supporting beta backend: `namecheap_beta_live/backend/`

Do not silently switch to `main`, an older portal tree, archived implementations or the Flutter app when the task is about Beta.

## Required state language

`REQUESTED → DOCUMENTED → CODED → WIRED → DEPLOYED → VERIFIED`

A merge/commit is not proof of deployment. Never call a source change live/fixed/working until deployment evidence and the affected runtime path are verified.

## Repository must remain a viable seed

After substantive work, update the repository knowledge layer when facts or rules changed. A future session should not need the conversation that produced the change.

At minimum:

- update `.ai/memory.md` for current state/checkpoints/priorities;
- update `.ai/decisions.md` for durable choices or superseded choices;
- update `.ai/invariants.md` only when a binding rule changes;
- update `.ai/playbook.md` when a reusable procedure or lesson changes;
- update relevant product/deployment/test docs next to the code they govern.

Do not store secrets, credentials, cookies or private storage state in the knowledge layer.
