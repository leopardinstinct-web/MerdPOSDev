#!/usr/bin/env bash
set -euo pipefail

REPO="$HOME/git/MerdPOSDev-beta-mirror"
LIVE="$HOME/merdpos.com/app/beta"
BRANCH="namecheap-beta-live"
LOCK="$HOME/.merdpos-beta-deploy.lock"

exec 9>"$LOCK"
if ! flock -n 9; then
  exit 0
fi

cd "$REPO"
git fetch origin "$BRANCH"
git switch "$BRANCH" >/dev/null 2>&1 || git checkout "$BRANCH"
git pull --ff-only origin "$BRANCH"

# Deploy backend source first so the server has the matching migration code.
rsync -az \
  --exclude='config.php' \
  --exclude='.env' \
  --exclude='.env.*' \
  --exclude='.deployed_version' \
  --exclude='imports/' \
  --exclude='*.csv' \
  --exclude='*.log' \
  "$REPO/namecheap_beta_live/backend/" \
  "$LIVE/backend/"

# Apply required idempotent schema/data migration before exposing the portal UI.
php "$LIVE/backend/cli/apply_022_management_roles.php"

# Only deploy the portal once the required migration has succeeded.
rsync -az \
  --exclude='config.php' \
  --exclude='.env' \
  --exclude='.env.*' \
  --exclude='.deployed_version' \
  --exclude='*.log' \
  "$REPO/namecheap_beta_live/timesheet_portal/" \
  "$LIVE/timesheet_portal/"

printf '%s %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$(git rev-parse --short HEAD)" > "$LIVE/.beta_deployed_commit"

echo "MERDPOS beta deployed: $(git rev-parse --short HEAD)"
