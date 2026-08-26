# MERDPOS Beta AI State

**Updated:** 2026-08-27
**Authoritative repository:** `leopardinstinct-web/MerdPOSDev`
**Authoritative branch:** `namecheap-beta-live`
**Deployable tree:** `namecheap_beta_live/`

## Recovery implementation checkpoint

Recovery implementation checkpoint: `5fc28354cb7372db7b93b0470543906076426497` — `Guard Namecheap deploy recovery checks in beta CI`.

Beta guardrails run `33010208846` for that exact implementation commit completed green with all three jobs:

- **Beta source contract** — PHP lint, runtime contract, portal permission policy, deterministic loader order, shared beta-state permission scope, Namecheap deploy-script syntax/recovery-guard wiring, JavaScript syntax and forbidden-path checks;
- **Beta Chromium smoke** — shared Add/Search behavior, permission-minimal legacy runtime, Timesheet loader/week switching and mobile contextual navigation;
- **Beta secret scan** — redacted gitleaks scan of the beta change.

The state-note commit containing this file follows the implementation checkpoint and contains no runtime behavior change.

This is **CODED + WIRED + source/Chrome-CI verified** only. The Namecheap `.beta_deployed_commit` marker has not been read in this session, so these changes are not claimed DEPLOYED or live-VERIFIED.

## Recovery regressions resolved and guarded

### Dynamic Roles → Navigation race

`assets/management.js` sets dynamically inserted classic scripts to `async=false`, preserving insertion/execution order. `backend/cli/validate_portal_loader_order.php` requires ordered execution and Roles-before-Navigation wiring.

### Shared state accidentally required Dashboard

`api/beta_state.php` is no longer reachable only through `dashboard.view`. Its route follows the consuming Dashboard, Disputes, Finance or Password capability while each sensitive response section is separately permission-scoped. Broad route access is not broad data access.

Current boundaries include:

- shifts only for required Timesheet/dispute consumers;
- disputes only for own-view/review permission;
- attendance flags only for resolve permission;
- working-now only for Workforce, own Timesheet or Finance consumers, self-only without Workforce access;
- Finance-only store context limited to the user's active clock-in store unless broader scope is granted;
- management summaries separately permission-gated.

`backend/cli/validate_beta_state_scope.php` protects these boundaries and corresponding management-card visibility.

### Management Dashboard implied unavailable data

Workforce, Finance, Dispute, Sync and Recent Attendance management surfaces mirror the permissions of the data they display instead of rendering misleading empty/zero cards for a management identity that lacks that capability.

### LOA permission-hidden DOM crash

`assets/app.js` retains inert compatibility shims for permission-hidden IDs still directly used by legacy `assets/beta.js`. Chrome executes a permission-minimal `app.js → beta.js` DOM and requires zero page-level JavaScript errors.

### Shared Add MutationObserver loop

`assets/minimal-controls.js` keeps the canonical Add SVG stable once normalized. Chrome verifies repeated unrelated DOM mutations do not replace that SVG, Search+Add remain clustered and one click fires exactly one action.

### Duplicate Timesheet runtime injection

`assets/app.js` refuses to inject a second `timesheet-app.js` while the first runtime is pending or initialized. Chrome evaluates `app.js` twice and requires one Timesheet script request, one initial weeks/report load and one report request per week change.

### Mobile contextual navigation

Chrome at a 390×844 viewport verifies:

- a contextual parent group opens without navigating away from the current panel;
- `merd-mobile-subnav-open` is present only while contextual subnavigation is active;
- selecting a contextual child changes the visible panel;
- selecting a direct section such as Finance clears contextual mobile-subnav state.

The first mobile-navigation CI failure was a fixture-only problem: `page.setContent()` produced an opaque document where Chromium denied `sessionStorage`. The fixture now runs on a normal HTTPS origin and the runtime assertions pass.

## Deployment safety added during recovery

`scripts/deploy_namecheap_beta.sh` now runs all four source validators before rsync:

1. `validate_beta_runtime_contract.php`;
2. `validate_portal_permission_policy.php`;
3. `validate_portal_loader_order.php`;
4. `validate_beta_state_scope.php`.

Beta CI syntax-checks the deploy script and fails if either recovery validator call is removed.

## Existing invariants retained

- `assets/design-audit.js` remains canonical and must not be removed in isolation.
- DEV-only permissions require actual DEV identity, not only LOA 1000.
- Browser portal authorization remains `client role → LOA → named permission → UI/API/data scope`.
- Frozen payroll/timesheet reconciliation remains unchanged.
- Retired corrective CSS layers remain retired.

## Implementation-state discipline

`REQUESTED → DOCUMENTED → CODED → WIRED → DEPLOYED → VERIFIED`

Never call a source change live/fixed/working until Namecheap `.beta_deployed_commit` confirms the intended commit and the affected real runtime path is checked.

## Next recovery actions

1. Deploy the current beta branch through the established Namecheap pull/mirror process and confirm `.beta_deployed_commit`.
2. Run authenticated USER, management and DEV verification, including unusual client thresholds where Dashboard is denied but Finance/Password/Disputes remain allowed.
3. Verify Dashboard add/remove/reorder plus Store/Workforce/Role/Client workflows against deployed beta.
4. Perform real phone/tablet checks for navigation, dialogs/software keyboard, Dashboard edit controls and Add/Search touch behavior.
5. Continue one-regression-at-a-time hardening without changing frozen payroll logic.

## Deployment command after beta source changes

```bash
cd ~/git/MerdPOSDev-beta-mirror

GIT_SSH_COMMAND="ssh -i $HOME/.ssh/merdpos_github -o IdentitiesOnly=yes -o BatchMode=yes" \
git pull --ff-only origin namecheap-beta-live

/bin/bash scripts/deploy_namecheap_beta.sh

echo "=== DEPLOYED ==="
cat ~/merdpos.com/app/beta/.beta_deployed_commit
```

When a deploy/schema/runtime failure matters:

```bash
tail -n 60 ~/merdpos-beta-deploy.log
```
