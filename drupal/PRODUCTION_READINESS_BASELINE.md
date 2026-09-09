# Drupal Production-Readiness Baseline

Frozen: 2026-09-09 09:29 +05:00

> Historical pre-role-sweep freeze. The current verified successor is `POST_DEV_CUTOVER_BASELINE.md`; this file is retained as the earlier rollback/provenance anchor.

This document freezes the verified Drupal Beta parity state before role-acceptance and cutover-rehearsal work begins. It is a reproducibility and rollback anchor, not a claim that production cutover has been rehearsed.

## Immutable anchors

- Canonical verified continuity head: `3bfd403e356b03ea669b0fda57b5a883aacc8f1d`.
- Named canonical convenience pointer: `baseline/drupal-parity-verified-20260909`.
- Exact deployed Drupal runtime release: `822003d90f520aec762583ccae950324f3b19e68`.
- Named runtime rollback pointer: `baseline/drupal-runtime-822003d90f52`.
- Backend gateway prerequisite used by this Drupal baseline: `48ca08b22ece5afca307249c338aec3adebce3d7` on `namecheap-beta-live`.

Commit SHAs are the source of truth. Baseline branches are human-readable pointers and must not be moved as part of normal development.

## Verified parity boundary

The canonical parity matrix at the freeze point contains no remaining `PARTIAL`, `MISSING`, or `COVERED` user-facing rows. `check_sheet` remains internal-only. DevStudio/UI Studio remains explicitly outside Drupal parity scope.

The last functional release passed the complete Namecheap deployment gate and authenticated DUMMY browser closure. Store Identity, Account/Dark and Dashboard Layout regressions were green on the same runtime release.

## Deployment contract pinned by the canonical commit

The Git commit already pins every deployment artifact. The following Git blob IDs are recorded as quick tamper/change references:

- `.cpanel.yml`: `af886b5535cdec6c04274426ba007ce78dd3fad0`
- `drupal/tools/namecheap_deploy.sh`: `0e40afda53b07b2e921a9783e0f60ef1beae24ac`
- `drupal/tools/namecheap_remote_deploy.py`: `bfb6b98ffb3799db8940c720627612110c349ae1`
- `drupal/deploy/settings.php`: `87e976ef907da3978b59c917f1dd7d04ae4998c9`
- `drupal/deploy/php84.ini`: `2ff3b48cc5ef54d5876c4bbde61bb591120dff8c`
- `drupal/composer.lock`: `035829e14abbc1dae2f1e30650e1a5a141352dea`

These are Git object IDs, not secret values or runtime credentials.

## Runtime assumptions frozen

- cPanel deployment invokes `/bin/bash /home/dridsheikh/merdpos-drupal/drupal/tools/namecheap_deploy.sh`.
- Drupal checkout: `/home/dridsheikh/merdpos-drupal`.
- Drupal public document root: `/home/dridsheikh/merdpos-drupal/drupal/web`.
- Drupal canonical branch: `beta/drupal-webapp`.
- Backend mirror: `/home/dridsheikh/git/MerdPOSDev-beta-mirror` on `namecheap-beta-live`.
- Remote deployment helper targets `198.187.29.30:21098` as user `dridsheikh` and uses an ignored local RSA identity; no deployment credential is stored in Git.
- Server PHP contract is Alt-PHP 8.4. Deployment aborts if PHP 8.4 or required extensions are unavailable.
- Composer dependencies are installed from the committed lockfile.
- Private runtime config is read from `/home/dridsheikh/.merdpos_drupal_runtime.php` and is not committed.
- Drupal private files and config sync remain outside the web root at `/home/dridsheikh/.merdpos_drupal_private` and `/home/dridsheikh/.merdpos_drupal_config_sync`.
- The tracked settings template trusts only `drupal-beta.merdpos.com`.
- MERDPOS remains authoritative for authentication, roles/LOA/named permissions, operational business logic, idempotency, audit and gateway-owned persistence.

## Exact code rollback point

For a Drupal code rollback to the frozen functional release, the named rollback branch points exactly to `822003d90f520aec762583ccae950324f3b19e68`.

Manual rollback procedure to rehearse before production cutover:

```bash
cd /home/dridsheikh/merdpos-drupal
git fetch origin baseline/drupal-runtime-822003d90f52
git checkout baseline/drupal-runtime-822003d90f52
/bin/bash drupal/tools/namecheap_deploy.sh
```

After the incident/rehearsal, return the checkout to the protected canonical line with the normal remote deployment helper or by checking out `beta/drupal-webapp` and fast-forwarding from origin.

This code rollback does **not** promise automatic database rollback. Any future release that introduces irreversible Drupal schema/data migrations must add a database-specific rollback decision before production promotion.

## Freeze rule

Until the role sweep is reviewed, production-readiness work must branch from canonical head `3bfd403e356b03ea669b0fda57b5a883aacc8f1d` or from a later protected-branch commit that contains only reviewed readiness evidence. Functional changes invalidate this baseline and require a new freeze record.

## What remains deliberately unfrozen

The following are next-stage readiness activities, not completed by this baseline freeze:

- full DEV / SUPER / ADMIN / USER role acceptance;
- critical-write safety rehearsal;
- rollback execution rehearsal;
- production cutover rehearsal;
- final go-live checklist and post-cutover smoke test.

Role acceptance must not begin until the requested user review is incorporated.
