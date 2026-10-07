# MERDPOS Beta — AI Operating Playbook

This file captures reusable procedures learned while recovering and developing MERDPOS Beta. It is intentionally tool-neutral: a future AI session may use different GitHub, browser, shell or deployment tools, but should preserve these operating principles.

## 1. Session bootstrap

Before acting:

1. Confirm repository `leopardinstinct-web/MerdPOSDev`.
2. Confirm authoritative branch `namecheap-beta-live`.
3. Read `AGENTS.md`, `.ai/README.md`, `.ai/invariants.md`, `.ai/task-gates.md` and `.ai/work/ACTIVE.yaml`; then load only task-relevant sections of memory/decisions/playbook/regression docs.
4. Inspect the current branch version and recent affected-path history for every file/component you plan to change.
5. Resolve contradictions by the authority hierarchy in `.ai/README.md`, not by remembered chat context.

Do not ask the user to restate repository facts already encoded here unless the repository itself is ambiguous.

### Handoff convention

The canonical implementer → reviewer handoff is the PR description plus **at most one PR comment** containing the exact head SHA, scope, CI links, lifecycle state and one next action. Do not create separate "handoff refresh" commits on the live branch. When handoff state changes, update the PR comment or the work packet instead.

The Kimi MCP bridge is **DEPRECATED / optional tooling**. Agent handoffs use PR comments and work packets. Bridge files such as `scripts/kimi*`, when present, are compatibility/history only; no new bridge development is allowed without an explicit product-owner decision.

### Tool/source editing preference

GitHub on the authoritative beta branch is the primary working surface as well as the source of truth.

For small, bounded source changes, prefer reading and updating the relevant files directly through GitHub. Do not involve a developer workstation merely to search, patch or commit a few files when the GitHub path can do the same work more directly.

Use a remote/local development machine only when it adds concrete value, such as:

- executing PHP/JS/browser tests that cannot run through the repository connector;
- authenticated live browser verification;
- complex multi-file transformations that are materially safer with local tooling;
- environment-specific diagnostics, deployment evidence or logs.

Tool choice should minimise hops while preserving verification. The repository remains canonical regardless of which execution environment is used.

### Windows Drupal PHP CLI

The MERDPOS Windows development workstation has a persistent PHP 8.4 CLI installed through WinGet. Use `php84` for Drupal validators and Composer/runtime checks that require PHP >=8.3. The old XAMPP CLI is PHP 8.2 and must not be used as evidence that Drupal validation is blocked. Before diagnosing a PHP compatibility issue, run `php84 -v`; normal PowerShell shells also alias `php` to PHP 8.4, but `php84` is the unambiguous automation command.

## 2. Implementation-state discipline

Use exactly:

`REQUESTED → DOCUMENTED → CODED → WIRED → DEPLOYED → VERIFIED`

Interpretation:

- **REQUESTED** — desired behavior stated.
- **DOCUMENTED** — contract/decision/runbook captured where needed.
- **CODED** — source implementation exists.
- **WIRED** — implementation is connected to its real consumer/runtime path.
- **DEPLOYED** — intended source is actually present on the target environment.
- **VERIFIED** — affected real runtime behavior was exercised and observed.

A Git commit/merge does not imply DEPLOYED. CI green does not imply live VERIFIED.

## 3. Product-stage testing strategy

The beta portal is currently in active product design/restructuring. The user expects to move, redesign, include and exclude screens/workflows.

### For unstable/evolving features

Use:

`BUILD/CHANGE → QUICK SMOKE → PERMISSION/SECURITY CHECK → DEPLOY → VISUAL/RUNTIME VERIFY`

Prefer:

- PHP/JS syntax and source-contract validation;
- one broad browser load/runtime-error smoke;
- authorization/tenant checks;
- business-rule validation;
- targeted manual/automated runtime verification of the changed behavior.

Avoid making these permanent contracts unless explicitly stabilised:

- navigation labels;
- panel ordering;
- DOM hierarchy;
- cosmetic placement;
- exact wording;
- exhaustive click paths for a feature still being redesigned.

### For stable features

Promote important behavior into regression coverage. Prefer outcome assertions such as permissions, balances, record states and tenant boundaries over incidental UI selectors.

## 3.5 Feature integration procedure

For every new feature or material expansion, use one end-to-end integration map instead of implementing UI/backend/database as separate mini-projects:

`data/schema → service/business logic → server-side auth/scope → API contract → client/session state → shared UI/navigation → loader/deploy → runtime verification`.

For each layer, record **DONE with evidence** or **NOT APPLICABLE with a reason**. The goal is complete integration with the existing app, not maximum new infrastructure.

- **Database/schema:** prefer existing canonical tables and relationships. When schema must change, use an explicit migration with safe existing-record behavior, backfill where required, and a rollback/recovery plan appropriate to the risk.
- **Backend:** keep business rules in the existing canonical service/runtime owner; do not duplicate authoritative calculations in the browser.
- **Auth/tenant scope:** extend the established role → LOA → named permission → UI/API/data-scope chain and verify both allowed and denied behavior.
- **API compatibility:** preserve existing consumers. Introduce a versioned contract only for a real breaking change, not as a default feature-development ritual.
- **Client/session state:** trace active user, client, store, theme and session context plus post-write refresh/re-render behavior.
- **UI:** reuse the shared MERDPOS primitives/tokens and integrate navigation/discoverability/permissions into the existing information architecture.
- **Feature flags:** add one only when staged rollout, rollback or conditional exposure genuinely needs it.
- **Deployment:** update loaders, cache/version keys, deploy copies and validators together when a new runtime asset/path is introduced.
- **Verification:** use deterministic business/security checks first, then affected-role runtime and visual verification.

Do not import LLM-app patterns such as vector namespaces, RAG metadata or model routers unless the MERDPOS feature actually introduces those subsystems.

## 4. Authorization change procedure

Binding model:

`client role → LOA → named permission → UI/API/data scope`

For any authorization-sensitive change, trace the whole path:

1. role/identity source;
2. LOA/authority resolution;
3. named permission catalog/threshold;
4. server-side API enforcement;
5. data-query scope;
6. UI visibility/interaction;
7. live behavior for an allowed and denied identity when practical.

Rules:

- UI hiding is never security.
- DEV-only requires actual DEV identity, not LOA 1000 alone.
- Do not invent parent/child permission dependencies unless product rules explicitly define them.
- When a DEV switches active client, do not assume employee-owned actions become tenant-native; inspect whether the endpoint binds to `auth_client_id`/owning employee.

## 5. DUMMY destructive testing procedure

All regression mutations must be isolated to DUMMY.

Before the first write:

1. Resolve the active DUMMY client by exact client code at runtime.
2. Do not assume a database ID.
3. Assert the returned active client is exactly DUMMY.
4. Abort before mutation on any mismatch.

During tests:

- use unique `AUTOTEST` names/identifiers;
- avoid MERD production records;
- use real DUMMY-native identities for employee-owned workflows when cross-client DEV context is insufficient;
- deactivate/void/delete temporary records when the product supports safe cleanup;
- keep destructive workflows manual/opt-in while the product is evolving.

Never commit credentials, cookies or Playwright storage-state.

## 6. Portal runtime debugging procedure

When the browser is broken, do not start with broad rewrites.

Trace:

1. HTTP status of the page/API.
2. asset versions/order actually returned by the live server.
3. first browser console/page error.
4. first failed application HTTP response.
5. permission-hidden DOM assumptions.
6. dynamic loader/script ordering.
7. cache/stale-asset possibility.
8. server deployment marker versus intended commit.

Fix the earliest proven failure, rerun the narrow path, then run beta guardrails.

Known classes of prior failures include:

- permission-hidden DOM elements referenced by legacy JS;
- dynamic script `async` ordering races;
- shared state being gated by the wrong feature permission;
- duplicate Timesheet runtime injection;
- MutationObserver loops;
- DEV role-label normalization mismatches;
- stale browser profile/cache creating false negatives.

## 7. Canonical portal runtime ownership

Treat the canonical assets in `.ai/invariants.md` as an integrated contract. Do not restore retired corrective CSS layers or delete a canonical asset in isolation.

When replacing an asset or runtime owner, change together as applicable:

- loader/import wiring;
- validator/source contract;
- deployment guard;
- documentation;
- regression coverage.

## 8. Deployment procedure

Architecture: Namecheap-side server process pulls/mirrors the beta branch and deploys via `scripts/deploy_namecheap_beta.sh`.

Do not recreate a GitHub→Namecheap SSH push pipeline unless the product owner explicitly changes architecture.

After source changes intended for live beta:

1. merge/update `namecheap-beta-live` through the normal repository process;
2. allow/trigger the established Namecheap pull/mirror process;
3. verify the deployed marker matches the intended commit;
4. verify the affected public runtime path;
5. only then mark DEPLOYED/VERIFIED.

If deployment fails, inspect `scripts/deploy_namecheap_beta.sh`, validators and server deploy log rather than bypassing recovery guards.

For Drupal shared UI primitive contract changes, treat deploy-time validators as dependent source. Before release, search validators for hard-coded geometry/state expectations that the shared primitive change supersedes and run the relevant deployment validators locally. If the server deploy stops on a stale validator, update that validator in Git to assert the new canonical contract, run its focused checks/CI, merge the source fix, then rerun deployment. Never skip or patch around the server guard in-place.

### 8.1 A deploy gate that cannot resolve its own inputs must FAIL, not pass (2026-10-07)

A gate that skips its check whenever it cannot find what it is looking for is not a
gate: it reports success while enforcing nothing. This is how the roster ordering
guard (`validate_roster_planning_v1.php`, added by PR #184) silently stopped
working:

```php
$portalRsyncAt = strpos($deploySource, '"$REPO/namecheap_beta_live/timesheet_portal/"');
$gateAt        = strpos($deploySource, 'validate_roster_planning_v1.php');
if ($portalRsyncAt !== false && $gateAt !== false && $gateAt < $portalRsyncAt) { ... }
```

Any innocent edit to the rsync line - requoting, `${REPO}`, a variable rename,
dropping the trailing slash - makes `$portalRsyncAt === false`, the conjunct
short-circuits, and validation reports SUCCESS without checking ordering. The
guard protecting the fix for an outage was itself the next outage.

Rules that follow:

- **Fail closed - inside the surface you are checking.** Resolve each input; when
  one cannot be resolved, add a `$failures[]` entry naming what could not be
  located. Never let "not found" mean "fine" *within* a surface the gate claims to
  verify. Scope this honestly: the roster validator still skips deploy-wiring
  assertions entirely when the deploy script is absent, because the deployed tree
  genuinely has no `scripts/` directory and a gate that cannot find the script
  would otherwise abort every deploy - the same mistake PR #183 fixed. That skip
  prints a note and is covered by CI instead. The residual hole is the deploy
  script CANDIDATE LOOKUP: a stale script at a parent path can still satisfy
  resolution (tracked in the roster packet as an open follow-up). Do not describe
  this class as closed while that remains open.
- **Resolve COMMAND lines, not raw offsets.** Match the actual `php ...` /
  `rsync ...` line, so a mention in a comment cannot satisfy or break the check.
  First-byte `strpos` over the whole file is fragile against both drift and
  comments - and a single needle test is not enough for a multi-line command: the
  rsync source sits on a backslash-continued argument line, so the check must
  track the rsync command across its continuation lines rather than ask whether
  any line mentions the path.
- **Prove it with negative controls, not with a green run.** A passing validator
  proves nothing about a guard that fails open. Perturb a COPY of the input (via
  `MERDPOS_DEPLOY_SCRIPT`, which the validator honours) and assert the exact
  failure for each case: input line removed, statement moved out of order,
  invocation removed. Only then is the guard known to enforce anything.
- **Independent review is what finds this class.** Both review transports flagged
  the fail-open conjunct independently, after the change had already merged and
  deployed unreviewed; CI could not see it because a fail-open guard is green by
  construction.


## 9. CI scoping procedure

Beta portal-only work should not pay for unrelated Flutter/Android/root-backend suites.

Current CI design path-scopes product areas. Preserve that principle:

- beta portal changes → beta guardrails + repository hygiene/secret checks;
- mobile app changes → Flutter/Android checks;
- root backend/catalogue changes → their own PHP/schema checks.

Efficiency rules:

- **many validation modules, few runner environments** — keep focused validator scripts but share checkout/runtime setup when they need the same environment;
- never commit a temporary workflow or temporary branch trigger merely to run a one-off check; use `workflow_dispatch` or an existing reusable workflow;
- use `cancel-in-progress` for replaceable PR checks so obsolete SHAs stop consuming runners;
- reserve full-history checkout for checks that genuinely need repository history (for example the dedicated Gitleaks scan); ordinary lint/scope/UI jobs should fetch only the revisions they need;
- do not run the same expensive security/test suite twice under different workflow names for the same event;
- consolidate repeated Flutter setup/dependency resolution before tests and debug build unless parallelism materially shortens a release-critical path.

Do not weaken a relevant check merely to make CI green. Skip unrelated product-area jobs by path detection instead.

## 10. Frozen payroll procedure

Before editing attendance/timesheet/payroll reconciliation, reread `.ai/invariants.md`.

Frozen behavior:

- pair IN to next OUT;
- newer IN replaces unmatched prior IN;
- orphan OUT ignored;
- IN and OUT independently rounded to nearest 15 minutes;
- payable = rounded OUT − rounded IN;
- cross-midnight allowed;
- no 16-hour cap;
- wage rate chosen by clock-in date.

Do not alter this logic as collateral work.

## 11. Feature completeness assessment

Do not equate files/endpoints with a finished feature.

Classify a feature by evidence across:

- UI entry point;
- client-side wiring;
- API contract;
- server-side authorization;
- persistence/schema;
- operational dependencies/devices/keys where relevant;
- deployment;
- real runtime verification.

Example lesson: Attendance QR had server-side verification/scanning scaffolding, but that did not mean the complete POS QR generation/device workflow was product-complete.

## 12. Live verification strategy

Prefer the least invasive evidence that proves the claim.

For read-only verification:

- authenticated page/API load;
- permission-scoped panels/data;
- browser runtime errors;
- failed app responses;
- mobile viewport/overflow when relevant.

For destructive verification:

- DUMMY-only;
- explicit tenant assertion before writes;
- reversible/cleanup behavior where possible;
- business outcome verification after mutation.

Do not broaden a successful narrow test into a global claim.

## 13. Fresh-context browser lesson

Persistent browser profiles can retain stale beta JS/CSS and produce false failures after deployment.

For deterministic regression, prefer a fresh browser context using external saved authenticated state rather than a long-lived cached profile. Never inspect or commit the secret contents of that state.

## 14. Repository knowledge maintenance

After meaningful work, ask: “Could a fresh session with only GitHub understand what just changed and why?”

If not, update the appropriate knowledge file.

- current facts/checkpoints/priorities → `.ai/memory.md`;
- durable choice/supersession → `.ai/decisions.md`;
- binding rule → `.ai/invariants.md`;
- reusable method/lesson → `.ai/playbook.md`;
- changed test coverage/gaps → `.ai/regression-inventory.md` or `LIVE_REGRESSION.md`;
- component-specific contract → docs beside that component.

The `.ai` layer should be curated state, not a transcript. Remove or explicitly supersede stale guidance.

## 15. Security hygiene

- Never read or expose plaintext credential files merely because they exist locally.
- Never commit auth storage state, cookies, passwords, `.env` files, private configs or build secrets.
- Preserve prepared SQL and server-side authorization.
- Prefer redacted/structural logs for security-sensitive checks.
- When automation needs authentication, keep the mechanism documented in GitHub but the secret material external.

## 16. Continuity truthfulness audit

When durable knowledge changes quickly, run a contradiction pass before calling the repository a reliable fresh-session seed.

1. Resolve current `namecheap-beta-live` HEAD and current Beta Guardrails status.
2. Check the authority chain in order: invariants/task gates → ACTIVE packets → memory → later decisions → component docs → playbook/regressions.
3. Reject stale higher-authority claims even when newer lower-authority notes are correct.
4. Reconcile `.ai/work/ACTIVE.yaml` with `.ai/work/active/`; archive completed/superseded packets without retroactively inflating lifecycle evidence.
5. Keep current component docs current-state-first. Historical behavior belongs in decisions/archive or an explicitly labelled historical section, not mixed into the current contract.
6. Run `php namecheap_beta_live/backend/cli/validate_ai_continuity.php` before release.
7. Treat encoding corruption in the durable knowledge layer as a continuity defect.

A fresh session should not have to infer which of two contradictory instructions is newer. If a rule changed, update or explicitly supersede the older higher-authority wording in the same workstream.

## DevStudio Structure interaction stability

Live MERDPOS panels can mutate `class` and `hidden` attributes continuously while DevStudio is open. Structure/Layers interaction state must therefore live in the editor state model, not only in transient DOM attributes. An open `•••` action menu or Add chooser must survive unrelated portal mutations, and the Structure MutationObserver must not replace those active controls mid-pointer interaction.

A browser failure reporting a detached, unstable, or suddenly hidden editor control is evidence of a real interaction race. A later retry passing is **not** sufficient closure. Root-cause the race, make the interaction state durable, and add a regression that mutates the underlying portal while the editor action remains open and usable.
Behavioral changes to browser-loaded DevStudio assets must advance the loader cache/version key in the same change; server-side file replacement alone is not sufficient live delivery evidence. Deployment guards should bind both the cache key and the corrected runtime behavior.

## DevStudio canvas-first editing

For visual editing, keep the product canvas primary and the hierarchy/layers panel secondary. Prefer contextual insertion points that determine *where* first (`+ Section`, `+ Container`, `+ Component`), then use a focused searchable picker to determine *what*. Object-local controls belong beside the selected object; global/power controls remain in the DevStudio radial or Structure view. Do not imitate another builder's branding or invent unsupported library/template capabilities. Every canvas action must route through the canonical DevStudio patch/history engine and must obey the same mutation-stability and cache-delivery rules as Structure.
