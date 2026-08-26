# Commit and Delivery Instructions — POS LATEST / MERDPOS Beta

## Active beta source

- Repository: `leopardinstinct-web/MerdPOSDev`
- Branch: `namecheap-beta-live`
- Deployable tree: `namecheap_beta_live/`
- Namecheap mirror: `~/git/MerdPOSDev-beta-mirror`
- Live beta target: `~/merdpos.com/app/beta`

Do not rely on historical documentation commit markers. Confirm actual HEAD before every change and the Namecheap `.beta_deployed_commit` before calling anything live.

## Mandatory implementation-state discipline

Every beta delivery must distinguish:

```text
REQUESTED → DOCUMENTED → CODED → WIRED → DEPLOYED → VERIFIED
```

- Documentation-only changes are not implementation.
- `Implemented in beta source` requires CODED + WIRED.
- `Live/fixed/working` requires DEPLOYED + VERIFIED.

Before committing a claimed feature, verify that the runtime entry point actually loads/calls any new JS/CSS/helper/API path. A file existing in Git is not sufficient.

## Never commit/expose

- private `config.php` files containing credentials;
- `.env`, `*.local.php`, credentials, SSH/private keys, signing keys;
- server deployed-version/deployed-commit files;
- generated build/cache output;
- plaintext passwords/PINs/secrets from Google legacy sources.

Sample configs contain placeholders only.

## Pre-commit review

Confirm:

- changed paths belong to the requested scope;
- no secrets/excluded artifacts are staged;
- PHP syntax/lint passes for changed PHP;
- permission-policy validator still passes for portal API changes;
- DB migrations have explicit runners and deployment order;
- DB-dependent portal code cannot publish before required schema verification;
- UI changes follow `GUI_STANDARD.md` and are mobile-ready;
- list Add/Search conventions use the shared circular `+` / expandable magnifier behavior where applicable;
- relevant README/context files describe the new runtime truth.

## README maintenance — default

For beta behavior/architecture changes, update the relevant README as part of the same delivery:

- `namecheap_beta_live/README.md`
- `namecheap_beta_live/timesheet_portal/README.md`
- `namecheap_beta_live/backend/README.md`

Also update relevant `docs/pos_latest/*.md` when standards/API/security/context/history changes.

Do not update README as a substitute for wiring. README is a runtime contract, not proof that the runtime code is active.

## Full-stack path check

For a DB-backed beta feature/fix, inspect:

```text
UI
→ runtime JS/CSS loading
→ API endpoint/action
→ permission/auth/client context
→ DB tables/columns/indexes/FKs
→ validation/transaction/storage/audit
→ response
→ UI re-render
→ mobile behavior
```

Do not claim completion if one of these required layers is only assumed.

## Deployment

After beta source changes, provide the immediate deployment command rather than relying on the scheduled cron:

```bash
cd ~/git/MerdPOSDev-beta-mirror

GIT_SSH_COMMAND="ssh -i $HOME/.ssh/merdpos_github -o IdentitiesOnly=yes -o BatchMode=yes" \
git pull --ff-only origin namecheap-beta-live

/bin/bash scripts/deploy_namecheap_beta.sh

echo "=== DEPLOYED ==="
cat ~/merdpos.com/app/beta/.beta_deployed_commit
```

When migration/schema behavior matters, also provide an appropriate deploy-log tail.

## Post-deploy verification

A successful deploy script is not by itself functional verification.

Before reporting `live/fixed/working`, confirm as applicable:

- deployed marker is the intended commit;
- migration/schema verification passed;
- expected asset/script is present in the live runtime chain;
- API returns expected authorization/data behavior;
- UI behavior is visible through the actual interaction path;
- mobile/responsive interaction remains usable;
- Google migration remains read-only to source Sheets and Sync safety gates hold.
