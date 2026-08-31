# MERDPOS Beta — Task Execution Gates

This file is a mandatory operating contract for AI/coding sessions working on MERDPOS Beta. It exists to prevent recurring failures around stale branch context, lost mid-task state, provenance/root-cause claims without history, concurrent-chat drift, and stopping at analysis/planning when the product owner explicitly asked for implementation.

## Gate 0 — Canonical branch entry before any Beta work

For every MERDPOS Beta task, independently resolve the current GitHub HEAD of `leopardinstinct-web/MerdPOSDev:namecheap-beta-live` before planning or changing code. Do not trust the repository default branch, the current checkout, a pre-existing feature branch, or branch/session state inherited from a chat that started outside the MERDPOS BETA project.

This re-bootstrap is mandatory when:

- a chat/session is moved into the MERDPOS BETA project;
- an existing chat changes repository or branch context;
- the session started outside the project and the product owner later says `implement`, `fix`, `apply`, `do`, `start` or `continue`;
- a feature branch is already checked out but has not been compared with the current authoritative Beta HEAD.

Read `AGENTS.md` and the bootstrap files from the current `namecheap-beta-live` ref, not from the active working branch. If a feature branch is genuinely required, create it from the current authoritative HEAD after bootstrap. Do not continue substantive Beta work on a stale/diverged branch merely because it already exists; reconcile/recreate it first. Small bounded Beta changes should prefer direct edits on `namecheap-beta-live`.

The default branch may contain a discovery pointer for Beta, but it is not authoritative Beta source.

## Gate 0.5 — Resumable work packet + concurrency guard

Use `.ai/work/` for non-trivial work that spans multiple meaningful checkpoints, tools/environments, deployment/verification stages, a real blocker, or likely continuation across chats. A small isolated change completed and evidenced safely in one continuous turn may remain packetless.

For packeted work:

1. Read `.ai/work/ACTIVE.yaml`.
2. Resume the matching packet if one exists; do not reconstruct canonical task state from chat first.
3. If none exists, create `.ai/work/active/<task-id>.yaml` before substantial execution and add it to `ACTIVE.yaml`.
4. Record `base_head`, `last_seen_head`, affected/owned paths, objective, acceptance criteria, constraints, packet checkpoint, project lifecycle state, completed evidence, remaining work and exactly one `next_action`.
5. Checkpoint only at meaningful boundaries: reconnaissance complete, implementation complete, targeted checks complete, deployment complete, runtime/visual verification complete, or a genuine blocker. Do not checkpoint every tool call.
6. Keep secrets, credentials, cookies, storage state and sensitive private output out of packets.

### Concurrency rule

Before modifying a packet-owned path after `namecheap-beta-live` has moved beyond the packet's `last_seen_head`:

- inspect the intervening commits/diff for the owned/affected paths;
- if there is no relevant overlap, refresh `last_seen_head` and continue;
- if there is overlap, reread current source plus relevant history and revise stale assumptions/plan before editing;
- if a newer durable decision changes the task contract, the newer decision/current source wins;
- do not treat a packet as an exclusive lock. It is resumable state, not ownership authority.

A packet must make another fresh session able to continue from `next_action` without reading the originating conversation.

## Gate 1 — Current source + affected-path history before substantive work

For every substantive code change, and for every question about why prior work behaved or failed a certain way:

1. Confirm repository `leopardinstinct-web/MerdPOSDev` and authoritative branch `namecheap-beta-live` using Gate 0.
2. Satisfy Gate 0.5 when the task qualifies for a work packet.
3. Read the current authoritative version of the affected file/component.
4. Inspect recent Git history for the affected path/component and open the relevant commit diff(s).
5. Read applicable `.ai/invariants.md`, targeted `.ai/decisions.md` sections and task-specific canonical docs.
6. Distinguish explicitly between current-source evidence, historical evidence and inference.

Do not begin a shared-runtime, design-system, authorization, deployment, payroll, tenant-scope or other cross-cutting change until the relevant history is understood well enough to avoid undoing a prior deliberate decision.

For a small isolated change, the history pass may be narrow, but it must still cover the affected owner/path. For a historical/root-cause question, current source alone is insufficient.

### Cross-cutting UI/design-system special case

When changing or assessing shared UI/typography/design-system behavior:

- inspect the shared primitive/token history;
- inspect the feature-specific stylesheet/component history;
- inspect the final cascade/runtime owner;
- do not treat token usage as proof of semantic/readability compliance;
- verify dark/light surface semantics, disabled/selected states, opacity and actual rendered readability where relevant.

## Gate 2 — Explicit implementation requests require implementation

When the product owner says `implement`, `fix`, `apply`, `do it`, `start`, `continue` or otherwise clearly asks for execution:

- first satisfy Gate 0 and Gate 0.5 as applicable;
- use available write/execution tools in the same turn before giving a plan-only response;
- analysis, recommendations, pseudocode or a proposed patch do not count as implementation;
- prefer direct GitHub edits for small bounded Beta source changes;
- use a remote/local machine only when it adds concrete execution or diagnostic value;
- if implementation is blocked by missing capability, permission or required evidence, state the blocker plainly and do not claim CODED/WIRED.

A user request to explain, review or diagnose does not by itself authorize a code change. Once the user explicitly asks to implement, the execution gate applies.

## Gate 3 — Evidence required for lifecycle claims

Use the project lifecycle exactly:

`REQUESTED → DOCUMENTED → CODED → WIRED → DEPLOYED → VERIFIED`

Minimum evidence:

- **CODED** — changed repository artifact plus commit SHA or equivalent concrete source evidence.
- **WIRED** — the implementation is connected to the actual consumer/runtime path, demonstrated by source inspection/change.
- **DEPLOYED** — deployment marker/log/server evidence shows the intended source reached the target.
- **VERIFIED** — the affected real runtime behavior was exercised and observed.

CI green proves only the checks that ran. A commit does not prove deployment. Deployment does not prove the affected behavior works.

### Visual UI verification gate

For visual changes involving alignment, spacing, typography, contrast, responsive geometry, layering or control placement:

- source/CSS correctness and passing CI are not enough for **VERIFIED**;
- inspect the rendered component at the affected viewport/surface before claiming **VERIFIED**;
- check the actual visual result, not only the intended grid/flex rule or DOM position;
- after the targeted element check, scan the whole affected surface for packing/whitespace, overflow, balance, clipping, alignment and obviously stranded controls or cards;
- when role/permission variants materially change composition, compare representative variants instead of verifying only one identity;
- for alignment changes, compare the rendered visual/box centers of the elements that are meant to align rather than assuming common top edges or shared containers imply optical alignment;
- if the intended runtime has not deployed yet, stop at **CODED/WIRED** and state that rendered verification remains pending.

Never convert intent, analysis or a passing unrelated test into a higher lifecycle state.

## Gate 4 — Completion/checkpoint report

After an implementation request, the response and (for packeted work) the latest packet checkpoint must make these facts recoverable without chat archaeology:

- canonical branch/HEAD and history/provenance checked;
- work-packet ID/state when applicable;
- files/artifacts actually changed;
- commit SHA(s) or equivalent concrete implementation evidence;
- targeted checks actually run and their result;
- exact lifecycle state reached;
- any remaining deployment/runtime verification gap;
- one exact next action if the task is not closed.

Keep the visible report concise. The packet, not the conversation, carries detailed resumable execution state.

## Gate 5 — Repository knowledge + packet closure

When a failure teaches a reusable operating lesson, update the appropriate GitHub knowledge layer in the same workstream. A fresh session should inherit the prevention mechanism from GitHub rather than needing the conversation that discovered it.

For packeted work, when the task is complete:

1. ensure the final packet records evidence, final lifecycle state and outcome;
2. promote only durable facts/rules/lessons into `.ai/memory.md`, `.ai/decisions.md`, `.ai/invariants.md`, `.ai/playbook.md` or component/test docs as appropriate;
3. remove the task from `.ai/work/ACTIVE.yaml`;
4. move the packet to `.ai/work/archive/`;
5. do not copy the packet's step-by-step transcript into curated project memory.

When the durable knowledge layer or work-packet index changes, run `php namecheap_beta_live/backend/cli/validate_ai_continuity.php` before completion. Treat a continuity-validator failure as a repository truthfulness defect, not as optional documentation lint.
