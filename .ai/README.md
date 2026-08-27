# MERDPOS Beta — Repository Bootstrap Manifest

This directory is the durable project brain for MERDPOS Beta. It exists so a fresh AI session with GitHub access can become productive without prior conversation history.

## Authority hierarchy

When sources disagree, use this order:

1. Current code and schema on `namecheap-beta-live`.
2. Binding `.ai/invariants.md`.
3. Current `.ai/memory.md`.
4. Later entries in `.ai/decisions.md` that explicitly supersede earlier ones.
5. Task-specific canonical docs beside the code.
6. `.ai/playbook.md` operational procedures.
7. Historical recovery/handover documents and old commits.
8. Chat history or local-machine recollection.

A historical document never overrides newer code plus a newer recorded decision.

## Mandatory reading order for a fresh session

1. `AGENTS.md`
2. `.ai/README.md` (this file)
3. `.ai/invariants.md`
4. `.ai/memory.md`
5. `.ai/decisions.md`
6. `.ai/playbook.md`

Then load task-specific material below.

## Task-specific reading map

### Authorization, roles, LOA, tenant/client access

Read:

- `docs/pos_latest/BETA_AUTHORIZATION_STANDARD.md`
- `namecheap_beta_live/timesheet_portal/includes/beta_api.php`
- the exact API endpoint being changed
- corresponding UI/JS consumer

Binding model: `client role → LOA → named permission → UI/API/data scope`.

### Portal runtime/UI/navigation

Read:

- `.ai/invariants.md`
- `namecheap_beta_live/backend/cli/validate_beta_runtime_contract.php`
- `namecheap_beta_live/backend/cli/validate_portal_loader_order.php`
- relevant portal PHP/JS/CSS files

Do not restore retired corrective CSS layers just because older docs mention them.

### Beta state/data exposure

Read:

- `namecheap_beta_live/timesheet_portal/api/beta_state.php`
- `namecheap_beta_live/backend/cli/validate_beta_state_scope.php`
- permission catalog and affected consumers

### Testing/regression

Read:

- `.ai/memory.md` section `Product-stage testing strategy`
- `.ai/regression-inventory.md`
- `namecheap_beta_live/browser_tests/LIVE_REGRESSION.md`
- `.github/workflows/beta-guardrails.yml`

The current beta is in active redesign. Prefer business/security/runtime contracts over brittle UI-position tests. Promote a changing workflow into permanent regression only when it has become reasonably stable or the user explicitly requests it.

### DUMMY destructive testing

Read:

- `.ai/memory.md` DUMMY policy
- `namecheap_beta_live/browser_tests/LIVE_REGRESSION.md`
- existing DUMMY runners before creating new ones

Never mutate MERD production data for regression purposes. Resolve the exact DUMMY tenant at runtime; never assume its database ID.

### Deployment / Namecheap

Read:

- `scripts/deploy_namecheap_beta.sh`
- `namecheap_beta_live/backend/README.md`
- `.ai/playbook.md` deployment section

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

Update the knowledge layer when work changes reality:

- `.ai/memory.md`: current state, checkpoints, what is verified/not verified, current product stage and next priorities.
- `.ai/decisions.md`: architectural/product choices, including explicit `Supersedes` notes.
- `.ai/invariants.md`: only binding rules that should not drift casually.
- `.ai/playbook.md`: reusable learned procedures, debugging patterns, testing/deployment workflows and safety guards.
- `.ai/regression-inventory.md`: actual coverage and known gaps when tests materially change.

Keep these files concise enough to bootstrap a new session. Move long historical detail to ordinary docs rather than letting the bootstrap layer become an unstructured transcript.

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
