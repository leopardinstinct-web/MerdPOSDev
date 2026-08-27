# MERDPOS Beta — Task Execution Gates

This file is a mandatory operating contract for AI/coding sessions working on MERDPOS Beta. It exists to prevent recurring failures around stale branch context, provenance/root-cause claims without history, and stopping at analysis/planning when the product owner explicitly asked for implementation.

## Gate 0 — Canonical branch entry before any Beta work

For every MERDPOS Beta task, independently resolve the current GitHub HEAD of `leopardinstinct-web/MerdPOSDev:namecheap-beta-live` before planning or changing code. Do not trust the repository default branch, the current checkout, a pre-existing feature branch, or branch/session state inherited from a chat that started outside the MERDPOS BETA project.

This re-bootstrap is mandatory when:

- a chat/session is moved into the MERDPOS BETA project;
- an existing chat changes repository or branch context;
- the session started outside the project and the product owner later says `implement`, `fix`, `apply`, `do`, `start` or `continue`;
- a feature branch is already checked out but has not been compared with the current authoritative Beta HEAD.

Read `AGENTS.md` and the bootstrap files from the current `namecheap-beta-live` ref, not from the active working branch. If a feature branch is genuinely required, create it from the current authoritative HEAD after bootstrap. Do not continue substantive Beta work on a stale/diverged branch merely because it already exists; reconcile/recreate it first. Small bounded Beta changes should prefer direct edits on `namecheap-beta-live`.

The default branch may contain a discovery pointer for Beta, but it is not authoritative Beta source.

## Gate 1 — Current source + affected-path history before substantive work

For every substantive code change, and for every question about why prior work behaved or failed a certain way:

1. Confirm repository `leopardinstinct-web/MerdPOSDev` and authoritative branch `namecheap-beta-live` using Gate 0.
2. Read the current authoritative version of the affected file/component.
3. Inspect recent Git history for the affected path/component and open the relevant commit diff(s).
4. Read applicable `.ai/invariants.md`, `.ai/decisions.md` and task-specific canonical docs.
5. Distinguish explicitly between current-source evidence, historical evidence and inference.

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

- first satisfy Gate 0 if the session/branch context could have been inherited or changed;
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

## Gate 4 — Completion report

After an implementation request, the response must make these facts recoverable without chat archaeology:

- canonical branch/HEAD and history/provenance checked;
- files/artifacts actually changed;
- commit SHA(s) or equivalent concrete implementation evidence;
- targeted checks actually run and their result;
- exact lifecycle state reached;
- any remaining deployment/runtime verification gap.

Keep the report concise, but do not omit evidence.

## Gate 5 — Repository knowledge update

When a failure teaches a reusable operating lesson, update the appropriate GitHub knowledge layer in the same workstream. A fresh session should inherit the prevention mechanism from GitHub rather than needing the conversation that discovered it.
