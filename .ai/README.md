# MERDPOS Beta — Repository Bootstrap Manifest

This directory is the durable project brain for MERDPOS Beta. It exists so a fresh AI session with GitHub access can become productive without prior conversation history.

## Authority hierarchy

When sources disagree, use this order:

1. Current code and schema on `namecheap-beta-live`.
2. Binding `.ai/invariants.md`.
3. Mandatory `.ai/task-gates.md` execution/provenance/checkpoint contract.
4. The active `.ai/work/active/<task-id>.yaml` packet for resumable state of that task only.
5. Current `.ai/memory.md`.
6. Later entries in `.ai/decisions.md` that explicitly supersede earlier ones.
7. Task-specific canonical docs beside the code.
8. `.ai/playbook.md` operational procedures.
9. Historical recovery/handover documents and old commits.
10. Chat history or local-machine recollection.

A work packet records execution state; it never overrides current code, binding invariants, task gates, or a newer durable decision. A historical document never overrides newer code plus a newer recorded decision.

## Mandatory lean reading order for a fresh session

1. `AGENTS.md`
2. `.ai/README.md` (this file)
3. `.ai/invariants.md`
4. `.ai/task-gates.md`
5. `.ai/work/ACTIVE.yaml`
6. If the task matches an active packet, read that packet.
7. Load the task-specific material below.
8. Read targeted sections of `.ai/memory.md`, `.ai/decisions.md`, `.ai/playbook.md` and regression docs only when they are relevant to the affected area, provenance question, deployment step, or packet.

Do not eagerly read every durable file into active context. Discoverability is mandatory; context saturation is not. Prefer narrow reads/searches over whole-file loading when only one section is relevant.

## Mandatory canonical-branch entry guard

For MERDPOS Beta, the bootstrap must be fetched from the current `namecheap-beta-live` ref explicitly. Do not assume the repository default branch, current checkout, or an inherited feature branch contains the current seed.

Re-bootstrap from the authoritative branch whenever a chat/session is moved into the MERDPOS BETA project, changes repository/branch context, or began outside the project before an explicit implementation request. Before using an existing feature branch, compare it with the current authoritative Beta HEAD. Stale/diverged branches must be reconciled/recreated before substantive Beta work continues.

The repository default branch carries a discovery pointer so sessions that land there can find Beta, but `main` is not the Beta source of truth.

## Resumable work packets

`.ai/work/` is the canonical mid-task state layer for work that cannot be safely treated as a single bounded turn.

Use a packet when the task spans multiple meaningful implementation checkpoints, multiple tools/environments, deployment plus runtime verification, a real blocker, or likely cross-chat continuation. Small isolated changes completed and evidenced in one continuous turn may remain packetless.

The packet must stay compact and factual: objective, acceptance criteria, packet state, project lifecycle state, starting/current HEAD, owned/affected paths, constraints, completed checkpoints, exact checks/evidence, remaining work and one explicit `next_action`.

Checkpoint only at meaningful boundaries. Do not turn work packets into transcripts.

Before resuming a packet, compare its recorded `last_seen_head` with current `namecheap-beta-live`. If HEAD moved, inspect the intervening diff for packet-owned paths. Non-overlap can refresh the packet and continue; overlap requires rereading current source/history and revising stale assumptions before editing.

See `.ai/work/README.md` for the schema and `.ai/task-gates.md` for the mandatory concurrency/checkpoint rules.

## Mandatory task preflight

For every substantive code change, read the current affected source and inspect recent Git history for the affected path/component before editing. For questions about why earlier work behaved or failed a certain way, inspect the relevant commit history/diffs before giving a confident root-cause answer.

When the product owner explicitly asks to implement/fix/apply/do/start/continue, analysis alone is not completion. Follow `.ai/task-gates.md`: use available write/execution tools, report concrete changed artifacts/commit evidence, checks actually run and the exact lifecycle state reached.

## Task-specific reading map

### Authorization, roles, LOA, tenant/client access

Read:

- `docs/pos_latest/BETA_AUTHORIZATION_STANDARD.md`
- `namecheap_beta_live/timesheet_portal/includes/beta_api.php`
- the exact API endpoint being changed
- corresponding UI/JS consumer
- recent history for the affected auth/API/UI paths
- relevant authorization sections from `.ai/decisions.md` / `.ai/playbook.md` when needed

Binding model: `client role → LOA → named permission → UI/API/data scope`.

### Portal runtime/UI/navigation

Read:

- applicable UI/runtime sections of `.ai/invariants.md` and `.ai/task-gates.md`
- `namecheap_beta_live/backend/cli/validate_beta_runtime_contract.php`
- `namecheap_beta_live/backend/cli/validate_portal_loader_order.php`
- relevant portal PHP/JS/CSS files
- recent history for the shared runtime and feature-specific owner being changed
- targeted design/runtime decisions or playbook sections only when relevant

Do not restore retired corrective CSS layers just because older docs mention them.

For cross-cutting UI/design-system work, do not equate token usage with successful standardization. Inspect shared primitives, feature-specific styles, cascade/runtime ownership and actual component states/readability.

### Beta state/data exposure

Read:

- `namecheap_beta_live/timesheet_portal/api/beta_state.php`
- `namecheap_beta_live/backend/cli/validate_beta_state_scope.php`
- permission catalog and affected consumers

### Testing/regression

Read:

- targeted product-stage testing guidance from `.ai/memory.md` / `.ai/playbook.md`
- `.ai/regression-inventory.md` when permanent coverage or a known incident guard is relevant
- `namecheap_beta_live/browser_tests/LIVE_REGRESSION.md`
- `.github/workflows/beta-guardrails.yml`

The current beta is in active redesign. Prefer business/security/runtime contracts over brittle UI-position tests. Promote a changing workflow into permanent regression only when it has become reasonably stable or the user explicitly requests it.

### DUMMY destructive testing

Read:

- targeted DUMMY policy from `.ai/invariants.md` / `.ai/memory.md`
- `namecheap_beta_live/browser_tests/LIVE_REGRESSION.md`
- existing DUMMY runners before creating new ones

Never mutate MERD production data for regression purposes. Resolve the exact DUMMY tenant at runtime; never assume its database ID.

### Deployment / Namecheap

Read:

- `scripts/deploy_namecheap_beta.sh`
- `namecheap_beta_live/backend/README.md`
- deployment section of `.ai/playbook.md`

Namecheap pulls/mirrors the beta branch through the established server-side process. Do not reintroduce a GitHub-to-Namecheap SSH deployment architecture unless the product owner explicitly changes that decision.

### Payroll/timesheet reconciliation

Read `.ai/invariants.md` before touching code. The payroll pairing/rounding logic is frozen unless the product owner explicitly changes it.

## Current-stage product philosophy

The webapp is still being actively moved, redesigned, included/excluded and restructured. Current navigation labels, panel order, DOM shape and cosmetic arrangement are not permanent contracts by default.

Development loop for unstable features:

`BUILD/CHANGE → QUICK SMOKE → PERMISSION/SECURITY CHECK → DEPLOY → VISUAL/RUNTIME VERIFY`

For stable features, add durable regression coverage around business outcomes and authorization boundaries.

## Knowledge maintenance rule

The repository must remain a self-sustainable seed after every meaningful session.

Use the right layer for the right kind of state:

- `.ai/work/active/*.yaml`: current resumable execution state; compact and task-specific.
- `.ai/work/archive/*.yaml`: closed execution records worth preserving.
- `.ai/memory.md`: curated current project state, verified checkpoints and priorities; not a transcript.
- `.ai/decisions.md`: architectural/product choices, including explicit `Supersedes` notes.
- `.ai/invariants.md`: only binding rules that should not drift casually.
- `.ai/task-gates.md`: mandatory provenance/execution/checkpoint/evidence gates.
- `.ai/playbook.md`: reusable learned procedures, debugging patterns, testing/deployment workflows and safety guards.
- `.ai/regression-inventory.md`: actual coverage and known gaps when tests materially change.

Close/archive a packet when its task is complete and promote only durable lessons into the curated knowledge layer. Do not copy the packet's step-by-step history into `.ai/memory.md`. When knowledge/work-packet state changes, run `php namecheap_beta_live/backend/cli/validate_ai_continuity.php` so stale higher-authority guidance, orphan packets and common encoding corruption fail closed.

## What must never be required for bootstrap

A fresh session must not need:

- previous ChatGPT messages;
- project memory outside GitHub;
- a specific developer workstation;
- plaintext passwords;
- browser cookies/storage-state;
- private test output;
- undocumented manual steps.

Secrets and authentication artifacts may be required to execute privileged live tests, but the *knowledge of how and why to perform the task* must remain in GitHub without containing those secrets.

## Historical files

`.ai/recovery-plan.md` and old handover material describe earlier recovery stages. Use them for provenance only unless `.ai/memory.md` explicitly reactivates an item. Current state and current code outrank them.
