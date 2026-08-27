# MERDPOS Beta — Task Execution Gates

This file is a mandatory operating contract for AI/coding sessions working on MERDPOS Beta. It exists to prevent two recurring failures: answering provenance/root-cause questions without checking repository history, and stopping at analysis/planning when the product owner explicitly asked for implementation.

## Gate 1 — Current source + affected-path history before substantive work

For every substantive code change, and for every question about why prior work behaved or failed a certain way:

1. Confirm repository `leopardinstinct-web/MerdPOSDev` and branch `namecheap-beta-live`.
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

Never convert intent, analysis or a passing unrelated test into a higher lifecycle state.

## Gate 4 — Completion report

After an implementation request, the response must make these facts recoverable without chat archaeology:

- history/provenance checked for the affected path;
- files/artifacts actually changed;
- commit SHA(s) or equivalent concrete implementation evidence;
- targeted checks actually run and their result;
- exact lifecycle state reached;
- any remaining deployment/runtime verification gap.

Keep the report concise, but do not omit evidence.

## Gate 5 — Repository knowledge update

When a failure teaches a reusable operating lesson, update the appropriate GitHub knowledge layer in the same workstream. A fresh session should inherit the prevention mechanism from GitHub rather than needing the conversation that discovered it.
