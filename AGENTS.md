# MERDPOS Beta — AI Bootstrap Entry Point

This repository is the standalone source of truth for MERDPOS Beta.

A fresh AI/chat/coding session with GitHub access must be able to reconstruct the project without relying on prior chat history, project memory, local files, or undocumented human context.

## Mandatory branch-entry guard

For any task that is about MERDPOS Beta, do not trust the repository's default branch, the current checkout, a feature branch already open in the session, or branch state inherited from a chat that started elsewhere.

Before planning or implementing Beta work, independently resolve the current `namecheap-beta-live` HEAD from GitHub and read this bootstrap from that exact branch/ref. This re-bootstrap is mandatory when a chat is moved into the MERDPOS BETA project, when an existing session changes repository/branch context, and immediately before an explicit `implement`/`fix`/`apply`/`do` request if the session began outside the project.

If a feature branch is genuinely needed, create it from the current `namecheap-beta-live` HEAD only after this bootstrap. Do not continue substantive Beta work on a stale or diverged branch merely because it was already checked out. Reconcile/recreate it against the current authoritative branch first. Small bounded Beta changes should continue to prefer direct edits on `namecheap-beta-live`.

The repository default branch may carry only a discovery pointer for Beta. It is not the Beta source of truth.

## Mandatory lean startup

Before planning or changing MERDPOS Beta, read in this order:

1. `AGENTS.md` — this entry point.
2. `.ai/README.md` — authority hierarchy, lean reading router and task map.
3. `.ai/invariants.md` — binding product/security/runtime rules.
4. `.ai/task-gates.md` — mandatory provenance, execution, checkpoint and evidence gates.
5. `.ai/work/ACTIVE.yaml` — active resumable work index.
6. If the task matches an active packet, read that packet before reconstructing anything from chat.
7. Read only the task-specific canonical files and targeted sections of `.ai/memory.md`, `.ai/decisions.md`, `.ai/playbook.md` or regression docs that the router says are relevant.

Do not eagerly load every durable project document into context when a narrow task does not need it. The repository must remain fully discoverable, but active reasoning context should stay task-scoped.

## Mandatory task preflight

Before every substantive code change:

- inspect the current authoritative version of the affected file/component;
- inspect recent Git history for the affected path/component and open relevant commit diffs;
- inspect applicable invariants, decisions and task-specific canonical docs;
- understand whether the current behavior is deliberate, accidental, superseded or incomplete before changing it.

For questions about why earlier work behaved or failed a certain way, current source alone is not sufficient: relevant repository history must be checked before giving a confident root-cause answer.

Follow `.ai/task-gates.md` for the full contract.

## Resumable work rule

For non-trivial work that spans multiple meaningful checkpoints, tools/environments, deployment/verification stages, or is likely to continue across chats, use `.ai/work/` as the canonical mid-task state layer.

- `.ai/work/ACTIVE.yaml` lists active packets.
- `.ai/work/active/<task-id>.yaml` contains the compact resumable checkpoint.
- `.ai/work/archive/<task-id>.yaml` preserves the final execution record after closure.
- Checkpoint at meaningful boundaries such as reconnaissance complete, implementation complete, checks complete, deployment complete, verification complete, or a real blocker. Do not update a packet after every tool call.
- Chat history may explain a packet, but must not be required to resume it.
- Before continuing an existing packet, compare its recorded HEAD with current `namecheap-beta-live` and follow the concurrency rule in `.ai/task-gates.md`.

Small bounded changes that can be safely completed and evidenced in one continuous turn do not require a packet.

## Source-of-truth rule

- GitHub on the authoritative beta branch is primary.
- Chat history is optional context, never required state.
- Local PC files, browser state, credentials and temporary test outputs are not canonical.
- Historical handovers/recovery notes are evidence only when they conflict with current `.ai` state, current code or later decisions.
- Never assume a remembered fact is still true when the repository can verify it.
- When provenance matters, use Git history/commit diffs as evidence rather than reconstructing history from memory.

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

When the product owner explicitly asks to implement/fix/apply/do/start/continue, planning or analysis alone is not task completion. Use available write/execution tools in the same turn unless blocked, then report concrete changed artifacts/commit evidence, checks actually run and the exact lifecycle state reached.

## Repository must remain a viable seed

After substantive work, update the repository knowledge layer when facts or rules changed. A future session should not need the conversation that produced the change.

At minimum as applicable:

- update or close the relevant `.ai/work/` packet for resumable execution state;
- update `.ai/memory.md` for curated current project state/checkpoints/priorities, not step-by-step task transcripts;
- update `.ai/decisions.md` for durable choices or superseded choices;
- update `.ai/invariants.md` only when a binding rule changes;
- update `.ai/task-gates.md` when mandatory execution/provenance/checkpoint gates change;
- update `.ai/playbook.md` when a reusable procedure or lesson changes;
- update relevant product/deployment/test docs next to the code they govern.

Do not store secrets, credentials, cookies or private storage state in the knowledge layer or work packets.

When continuity/knowledge files change, `php namecheap_beta_live/backend/cli/validate_ai_continuity.php` is the fail-closed consistency check before the change is considered complete.
