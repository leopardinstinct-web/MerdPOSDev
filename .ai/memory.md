# MERDPOS Beta AI State

**Updated:** 2026-08-27
**Authoritative repository:** `leopardinstinct-web/MerdPOSDev`
**Authoritative branch:** `namecheap-beta-live`
**Deployable tree:** `namecheap_beta_live/`

## Current source checkpoint

Current checked source tip: `9860f1b70f5de3f3cc0e5ada895c66617239a9b9` — `Fix beta state validator literal check`.

The Beta guardrails run for this exact commit (`33008493860`) completed successfully. The green suite includes:

- PHP lint across the beta backend and portal;
- beta runtime-contract validation;
- portal permission-policy / LOA runtime validation;
- deterministic portal loader-order validation;
- shared `beta_state.php` route/data-scope and management-card permission validation;
- portal JavaScript syntax checks;
- forbidden tracked beta path checks;
- redacted secret scanning.

This is a **CODED + WIRED + source-CI verified** checkpoint only. The current Namecheap `.beta_deployed_commit` marker has not been read in this session, so these changes are not claimed DEPLOYED or VERIFIED.

## Recovery history

- `c4d1b0d4d50a7ecf6bf60a49f85410842f4c5888` — handover recovery baseline.
- `444e790357e1a006d8120ece9598081df45c77c2` — `Hotfix LOA permission runtime crashes`.
- `4002bec01fb38706cf9126daef4e48cd32bec061` — `Stop shared Add control mutation loop`.
- `e5a8e9cb930f04fb0669639a7dd7be67e80032a1` — source-controlled recovery state + beta guardrails.
- `8034463affb1eb7ecbd3ddde84c2efd55d83cc6d` — incident guard for permission-gated DOM and Timesheet runtime split.
- `b906b4cc0f329d69d7c55ff3e9ebf352c9b9220b` — force dynamically inserted classic scripts to ordered execution.
- `44b9a5b02c370dea1e8896d62a0f60b486573160` — loader-order guard wired into beta CI.
- `429a75285de96f61a1045d6ab4e333d4168fc235` — permission-scope shared beta-state payload.
- `dad011cd9b7453bd0e32964a6893d61c7539f5d0` — authorize shared beta state by consuming feature instead of Dashboard only.
- `6f79511f7ab69544d31df897c7fe1a76b8124521` — shared beta-state scope validator wired into beta CI.
- `fc0db7c81a07afab0ca1d36f3bf36728ee8ef25d` — management Dashboard cards/KPIs now mirror their data permissions.
- `9860f1b70f5de3f3cc0e5ada895c66617239a9b9` — corrected a validator-only false failure; full source suite green.

## Confirmed source regressions resolved

### Dynamic Roles → Navigation race

`assets/management.js` dynamically inserts multiple classic scripts. Dynamic classic scripts are async by default, so merely inserting Roles before Navigation did not guarantee execution order.

The loader now sets `script.async=false` before `src`, preserving insertion/execution order. `backend/cli/validate_portal_loader_order.php` permanently checks both ordered execution and Roles-before-Navigation wiring.

### Shared state accidentally required Dashboard

`assets/beta.js` uses `api/beta_state.php` for multiple feature flows, but its route was guarded only by `dashboard.view`. A client could raise the Dashboard threshold while leaving Finance, Disputes or Password available, causing otherwise-authorized features to fail initialization.

The route now admits the actual consuming feature permissions, while `api/beta_state.php` scopes every sensitive data section separately. Broad route access is not broad data access.

Current safeguards include:

- shifts only for required Timesheet/dispute consumers;
- disputes only for own-view/review permission;
- attendance flags only for resolve permission;
- working-now data only for Workforce, own Timesheet or Finance consumers, and self-only without Workforce access;
- store enumeration constrained by Stores/Workforce/cross-store Finance scope; Finance-only users receive their active clock-in store context;
- management summaries separately permission-gated.

### Management Dashboard implied unavailable data

Management mode can result from different permission combinations. Previously all management Workforce/Dispute/Finance/Sync cards rendered even when only one of those capabilities was allowed.

`assets/management.js` now hides/constructs cards and KPIs according to `workforce.view`, `finance.management_summary`, `disputes.review`, `system.sync_status` and Timesheet permissions. `validate_beta_state_scope.php` guards both backend data scope and frontend management-card scope.

## Existing incident guards retained

- The 2026-08-26 LOA DOM crash remains protected by `validate_portal_permission_policy.php`.
- `assets/app.js` retains compatibility shims for permission-hidden legacy IDs still directly used by `beta.js`.
- Timesheet logic remains isolated in `assets/timesheet-app.js`, loaded only when the Timesheet DOM exists.
- `assets/minimal-controls.js` retains the Add MutationObserver idempotency fix.
- `assets/navigation.js` retains explicit contextual mobile subnavigation state.
- `assets/design-audit.js` remains canonical and must not be removed in isolation.

## Implementation-state discipline

`REQUESTED → DOCUMENTED → CODED → WIRED → DEPLOYED → VERIFIED`

Never call a source change live/fixed/working until the Namecheap `.beta_deployed_commit` marker confirms the intended commit and the real affected runtime path is checked.

## Next recovery actions

1. Add deterministic browser smoke coverage for the shared Add MutationObserver incident and other dependency-sensitive browser behavior without production credentials.
2. Continue source/runtime-path audit of Finance, Disputes, Password, Dashboard Add/Search and mobile navigation under unusual permission combinations.
3. Deploy the current branch to Namecheap and confirm `.beta_deployed_commit` before live verification.
4. Verify representative USER, management and DEV paths on desktop/mobile.
5. Preserve frozen payroll/timesheet reconciliation throughout recovery.

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
