# Drupal Post-DEV-Cutover Verified Baseline

Frozen: 2026-09-09 20:55 +05:00

This is the verified successor to the pre-role-sweep production-readiness freeze in `PRODUCTION_READINESS_BASELINE.md`. It freezes the Drupal Beta state after platform DEV separation, four-role live acceptance, post-cutover regression hardening, and clean-checkout deployment hardening. It does not claim that destructive critical-write or rollback rehearsals are complete.

## Exact live anchors

- Drupal deployed runtime: `23dc6328b92f2b00b34d2355ff85afef65dc0ea2` on `beta/drupal-webapp`.
- Backend deployed runtime: `5f7bccbac89eaff6f0e0bd3f0785b1813227590b` on `namecheap-beta-live`.
- Post-cutover acceptance harness: `drupal/tools/live_post_cutover_acceptance.js`.
- Drupal static deployment validators: `32/32` passing at this release.
- PR #108 CI: success; Secret scan: success.
- Namecheap tracked Git status after deployment: clean.

## Platform identity invariants

- DEV is a platform identity, not a client workforce employee.
- Active client-bound DEV employee rows after migration 038 finalization: `0`.
- Active platform DEV identities: `2`.
- Active platform DEV Working Client preferences: `2`.
- DEV remains actual-identity scoped at LOA 1000 and cannot be delegated as a client role.
- Client workforce role selectors expose USER, ADMIN and SUPER only; DEV is not assignable.
- Working Client changes context for platform DEV without moving DEV into the client workforce model.

## Role-authority invariants
- DEV defines role identity, LOA and permission ceilings.
- ADMIN cannot create/delete roles, redefine role identity/LOA, or edit DEV permission thresholds.
- ADMIN application-usability management is limited to exactly SUPER and USER and remains bounded by the DEV-defined ceiling.
- SUPER has no role-definition, permission-threshold or usability-management controls.
- USER cannot access Administration or DEV surfaces.

## Exact live four-role acceptance

The authenticated post-cutover browser suite was rerun against the deployed `23dc6328b92f...` release and returned `verified: true`.

| Role | Home | Operations | Disputes | Reports | Finance | Administration | DEV |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| DEV | 200 | 200 | 200 | 200 | 200 | 200 | 200 |
| ADMIN | 200 | 200 | 200 | 200 | 200 | 200 | 403 |
| SUPER | 200 | 200 | 200 | 200 | 200 | 200 | 403 |
| USER | 200 | 200 | 200 | 200 | 200 | 403 | 403 |

Additional live assertions at the same release:

- report CSV export returned 200 for all four roles;
- all six POST-only routes rejected GET with 405;
- ADMIN tabs matched role access boundaries;
- DEV role controls contained create-role and permission-threshold controls and no DEV workforce assignment option;
- ADMIN contained exactly two usability forms targeting SUPER and USER;
- dark theme applied and persisted;
- 390x844 Home, Operations, Disputes, Reports and Finance had no horizontal overflow;
- page JavaScript exceptions: 0;
- failed browser requests outside deliberate authorization/method probes: 0;
- unexpected client errors: 0;
- server 5xx responses: 0;
- logout returned to login and clearing the session cookie required authentication again.

## Deployment cleanliness invariant

Composer/Drupal scaffold is allowed to regenerate scaffold files during install, but `namecheap_deploy.sh` must immediately restore the Git-owned `drupal/.gitattributes` and `drupal/web/.htaccess`. Before recording a release marker, deployment now fails if `git status --short --untracked-files=no` reports any tracked drift. `validate_deploy_clean_checkout_v1.php` protects this contract.

## Runtime rollback anchor

The exact code rollback anchor for this verified post-cutover runtime is `23dc6328b92f2b00b34d2355ff85afef65dc0ea2`. The named runtime baseline pointer is `baseline/drupal-runtime-23dc6328b92f`.

A code rollback does not imply database rollback. Migration/database rollback decisions remain a separate rehearsal requirement.

## What remains

The next production-readiness stage is critical-write safety rehearsal, followed by rollback execution rehearsal, production cutover rehearsal, and the final go-live checklist. Those stages must preserve this baseline's platform-DEV and role-authority invariants.
