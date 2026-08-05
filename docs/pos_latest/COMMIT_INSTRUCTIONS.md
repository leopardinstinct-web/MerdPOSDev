# Commit and Delivery Instructions — POS LATEST

Current documentation baseline: source commit `29de6f4`. Production deployment
state was not verified during reconciliation; the historical marker `4a54c41`
must not be treated as current without an approved production check.

## Approval levels

- Branch implementation requires approved Level 2 scope.
- Commit, push, and pull request require explicit Level 3 approval.
- Merge, migration, credential/configuration change, and deployment each require
  explicit Level 4 approval.

## Never commit

- `backend/api/config.php`
- `backend/api/.deployed_version` or `backend/.deployed_version`
- `.env`, `*.local.php`, credentials, signing keys
- `merdpos_staff/build/`, `.dart_tool/`, APKs, generated caches

`backend/api/config.sample.php` must contain placeholders only. At the current
baseline it contains a real-looking value and must be resolved before a future
commit that includes it.

## Pre-commit review

Run from the repository after approval:

```bash
git status --short --branch
git diff --check
git diff --stat
git diff --name-only
```

Confirm every changed path belongs to the approved scope and no excluded file
is staged. Run CI checks defined by `CI_ENVIRONMENT_PLAN.md`.

## Commit flow after explicit approval

```bash
git add <approved-files-only>
git diff --cached --check
git diff --cached --stat
git commit -m "<clear scoped message>"
```

Push and pull request are separate explicitly approved actions.

## Deployment

There is no automatic deployment authorization. A deployment plan must state:

- exact artifact/file list and source commit;
- preflight and backups;
- schema migration and data compatibility, if any;
- secret/config preservation;
- health checks;
- rollback or forward-fix procedure;
- responsible approver.

Never overwrite server `api/config.php`. Never update a deployed-version marker
until the corresponding reviewed artifact is actually deployed.
