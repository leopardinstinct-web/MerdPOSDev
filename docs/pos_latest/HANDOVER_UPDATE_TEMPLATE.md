# Handover Update Template — MERDPOS Beta

Use at the end of any heavy coding/debugging chat to refresh the authoritative beta context.

## Required handover report

1. Current branch and exact HEAD.
2. Current Namecheap deployed marker, if deployment was performed/verified.
3. Feature/fix state using the mandatory lifecycle:

```text
REQUESTED → DOCUMENTED → CODED → WIRED → DEPLOYED → VERIFIED
```

4. Latest working files/paths on GitHub.
5. Files modified in this chat.
6. DB migration/schema changes and deploy order, if any.
7. API endpoints/actions changed and permission keys involved.
8. Bugs fixed.
9. Bugs still open.
10. Test/lint/validator/runtime-verification results.
11. Exact next step.
12. Assumptions made.

## Mandatory implementation truth check

For every item described as implemented, answer:

- Does the implementation file exist? **CODED**
- Is it actually loaded/called from the runtime entry point? **WIRED**
- Are API/auth/client/DB/storage/response/re-render paths connected where applicable?
- Is mobile behavior covered for UI changes?
- Was the intended commit confirmed deployed? **DEPLOYED**
- Was the real runtime path verified? **VERIFIED**

Do not call a Markdown standard or specification `implemented` unless the actual runtime behavior is coded and wired.

## Full-stack beta delivery check

For DB-backed/new features confirm:

- [ ] UI exists and follows `GUI_STANDARD.md`.
- [ ] Runtime JS/CSS/module is actually loaded by the portal entry path.
- [ ] API endpoint/action exists and is registered in the fail-closed permission policy.
- [ ] Named permission/LOA is enforced server-side.
- [ ] Client/store/employee tenant scope is enforced.
- [ ] Required SQL migration exists.
- [ ] Migration runner is wired into deploy in safe order.
- [ ] Required tables/columns/indexes/FKs are verified before dependent portal code is published.
- [ ] Writes use validation/CSRF/transactions/audit as applicable.
- [ ] API response and UI re-render are checked.
- [ ] Mobile/tablet behavior is accounted for.
- [ ] Relevant README/project context is updated.

## README/context maintenance — default

Update by default when relevant:

- `namecheap_beta_live/README.md`
- `namecheap_beta_live/timesheet_portal/README.md`
- `namecheap_beta_live/backend/README.md`
- `docs/pos_latest/PROJECT_CONTEXT.md`
- `docs/pos_latest/CHANGELOG.md`
- `docs/pos_latest/BUGS_AND_FIXES.md`
- `docs/pos_latest/API_CONTRACT.md` if API changed
- `docs/pos_latest/FILE_MANIFEST.md` if files added/removed
- `docs/pos_latest/SECURITY.md` if security contract changed
- `docs/pos_latest/GUI_STANDARD.md` / `DESIGN_TOKENS.md` if UI standard changed

README documentation must describe runtime truth; documentation does not count as runtime wiring.

## Security/process confirmation

- [ ] Real latest GitHub files inspected rather than guessed.
- [ ] No secrets/config/keys committed or exposed.
- [ ] Prepared statements/input validation used where applicable.
- [ ] Passwords/secrets hashed/redacted appropriately.
- [ ] Browser UI hiding is not treated as authorization.
- [ ] Google legacy import does not write to source Sheets.
- [ ] Frozen Timesheet/payroll reconciliation remains unchanged unless explicitly approved.

## Current beta source reminder

- Active branch: `namecheap-beta-live`.
- Active deployable tree: `namecheap_beta_live/`.
- `timesheet_portal/` is an active beta development surface.
- Historical instructions saying never to inspect/modify Timesheet Portal are obsolete.
- Never rely on a historical commit marker; record actual HEAD and deployed marker.

## Standard immediate deploy command after beta source changes

```bash
cd ~/git/MerdPOSDev-beta-mirror

GIT_SSH_COMMAND="ssh -i $HOME/.ssh/merdpos_github -o IdentitiesOnly=yes -o BatchMode=yes" \
git pull --ff-only origin namecheap-beta-live

/bin/bash scripts/deploy_namecheap_beta.sh

echo "=== DEPLOYED ==="
cat ~/merdpos.com/app/beta/.beta_deployed_commit
```

Add an appropriate `tail` of `~/merdpos-beta-deploy.log` when schema/migration verification matters.
