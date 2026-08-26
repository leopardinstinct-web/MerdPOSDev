# MERDPOS Beta AI State

**Updated:** 2026-08-27
**Authoritative repository:** `leopardinstinct-web/MerdPOSDev`
**Authoritative branch:** `namecheap-beta-live`
**Deployable tree:** `namecheap_beta_live/`

## Current source state

- Recovery baseline documented at handover: `c4d1b0d4d50a7ecf6bf60a49f85410842f4c5888` (`c4d1b0d`).
- `444e790357e1a006d8120ece9598081df45c77c2` — `Hotfix LOA permission runtime crashes`.
- `4002bec01fb38706cf9126daef4e48cd32bec061` — `Stop shared Add control mutation loop`.
- `e5a8e9cb930f04fb0669639a7dd7be67e80032a1` — `Add beta recovery state and guardrails`.
- `8034463affb1eb7ecbd3ddde84c2efd55d83cc6d` — `Guard permission-gated portal runtime wiring`.

## Recovery progress

### Phase 1 — source containment

Source-level containment is established:

- `.ai/` state files are source controlled;
- `namecheap-beta-live` now has beta-specific GitHub guardrails;
- the guardrails lint beta PHP, validate the runtime contract, validate authorization/permission coverage, syntax-check portal JavaScript, reject forbidden tracked beta paths and run redacted secret scanning;
- the first two beta-guardrail runs passed.

This is not a Namecheap deployment claim. The current live `.beta_deployed_commit` marker has not been read in this session.

### Phase 2 — regression inventory and incident hardening

Started.

The 2026-08-26 LOA crash hotfix is now protected by deploy-time source validation. `validate_portal_permission_policy.php` verifies the compatibility shims in `assets/app.js`, the isolated `assets/timesheet-app.js` wiring, script load order (`app.js` before `beta.js`) and cache revalidation for the split runtime.

The latest Add-control hotfix was inspected. `assets/minimal-controls.js` now avoids replacing an already-normalized Add SVG from inside its MutationObserver, preventing the observer from continually retriggering itself and invalidating the pointer target.

Mobile navigation source was inspected. Its contextual submenu state is explicit through `merd-mobile-subnav-open`, and parent-group clicks open the group without navigating.

An additional loader-order risk is under investigation: `management.js` comments that Roles mounts before navigation, but both are dynamically inserted scripts. Do not change this loader until the dependent module behavior is fully traced and a deterministic regression check can accompany the change.

## Important conflict resolved

A transferred handoff note said a new session would remove `design-audit.js`. That instruction is stale relative to current authoritative source.

Current beta runtime/deployment validators explicitly require `assets/design-audit.js`, its `management.js` loader wiring and cache revalidation. It supplies heading, contrast, touch-target, Search/Add placement, accessible-name and overflow regression checks.

Therefore `design-audit.js` must not be deleted unless the authoritative runtime contract, loader, deployment validator and replacement ownership are intentionally changed together.

## Implementation-state discipline

`REQUESTED → DOCUMENTED → CODED → WIRED → DEPLOYED → VERIFIED`

Do not call a source change live/fixed/working until the Namecheap `.beta_deployed_commit` marker confirms the intended commit and the actual runtime path is verified.

## Next recovery actions

1. Read the current Namecheap `.beta_deployed_commit` after deploying the current beta branch.
2. Continue the feature-path regression inventory in `.ai/regression-inventory.md`.
3. Resolve source-level loader/order risks only with deterministic regression coverage.
4. Add unauthenticated/local browser smoke coverage before introducing any authenticated Playwright fixtures.
5. Repair one runtime regression at a time and preserve frozen payroll/timesheet reconciliation.

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
