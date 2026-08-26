# MERDPOS Beta AI State

**Updated:** 2026-08-27
**Authoritative repository:** `leopardinstinct-web/MerdPOSDev`
**Authoritative branch:** `namecheap-beta-live`
**Deployable tree:** `namecheap_beta_live/`

## Current source state

- Recovery baseline documented at handover: `c4d1b0d4d50a7ecf6bf60a49f85410842f4c5888` (`c4d1b0d`).
- Hotfix after that baseline: `444e790357e1a006d8120ece9598081df45c77c2` — `Hotfix LOA permission runtime crashes`.
- Current branch tip before recovery-infrastructure commit: `4002bec01fb38706cf9126daef4e48cd32bec061` — `Stop shared Add control mutation loop`.
- `444e790` moved Timesheet-only browser logic from shared `assets/app.js` into `assets/timesheet-app.js` and added cache revalidation for the split assets.
- `4002bec` patched the shared Add-control mutation loop in `assets/minimal-controls.js`.

## Current recovery assessment

The beta was already receiving emergency runtime hotfixes after the documented handover baseline. The first recovery priority is therefore regression containment and deterministic validation, not additional UI redesign.

A key repository gap was found: the existing general CI and secret-scan workflows only run on pushes to `main`; the authoritative beta branch did not have push-time source validation. A beta-specific guardrail workflow is being added as Phase 1 recovery infrastructure.

## Important conflict resolved

A transferred handoff note said a new session would remove `design-audit.js`. That instruction is stale relative to current authoritative source.

Current beta runtime and deployment validators explicitly require:

- `namecheap_beta_live/timesheet_portal/assets/design-audit.js`;
- `assets/management.js` to load it;
- `.htaccess` to revalidate it;
- the deploy script to verify its heading, contrast, touch-target, Search/Add placement, accessibility and overflow checks.

Therefore `design-audit.js` must **not** be deleted during recovery unless the authoritative runtime contract is intentionally redesigned in the same reviewed change.

## Implementation-state discipline

Use only:

`REQUESTED → DOCUMENTED → CODED → WIRED → DEPLOYED → VERIFIED`

Do not call a source change live/fixed/working until the Namecheap `.beta_deployed_commit` marker confirms the intended commit and the actual runtime path is verified.

## Next recovery actions

1. Run the new beta guardrail workflow on every beta push/PR.
2. Inventory the post-baseline runtime changes and identify regressions by feature path.
3. Add deterministic browser smoke coverage only where it can run without production credentials or secret fixtures.
4. Repair one regression at a time; preserve frozen payroll/timesheet reconciliation.
5. Deploy immediately after an approved beta source fix and verify the deployed marker plus the affected runtime path.

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
