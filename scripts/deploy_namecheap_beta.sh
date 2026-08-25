#!/usr/bin/env bash
set -euo pipefail

# Cron has a much smaller environment than an interactive shell. Keep every
# dependency explicit so scheduled deploys behave the same as manual deploys.
export HOME="${HOME:-/home/dridsheikh}"
export PATH="/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin:${PATH:-}"
export GIT_SSH_COMMAND="ssh -i $HOME/.ssh/merdpos_github -o IdentitiesOnly=yes -o BatchMode=yes"

REPO="$HOME/git/MerdPOSDev-beta-mirror"
LIVE="$HOME/merdpos.com/app/beta"
BRANCH="namecheap-beta-live"
SCHEMA_BRANCH="namecheap-live-schema"
LOCK="$HOME/.merdpos-beta-deploy.lock"

# Always print a heartbeat before doing any work. The outer cron redirects this
# to ~/merdpos-beta-deploy.log, so a missing heartbeat means cron never invoked
# the script at all.
echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] MERDPOS beta deploy started (pid $$)"

exec 9>"$LOCK"
if ! flock -n 9; then
  echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] deploy skipped: another run holds $LOCK"
  exit 0
fi

for command_name in git ssh rsync php flock mktemp; do
  if ! command -v "$command_name" >/dev/null 2>&1; then
    echo "ERROR: required command not found in cron PATH: $command_name" >&2
    exit 1
  fi
done

if [[ ! -r "$HOME/.ssh/merdpos_github" ]]; then
  echo "ERROR: GitHub deploy key is not readable: $HOME/.ssh/merdpos_github" >&2
  exit 1
fi

cd "$REPO"
echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] fetching $BRANCH from GitHub"
git fetch origin "$BRANCH"
git switch "$BRANCH" >/dev/null 2>&1 || git checkout "$BRANCH"
git pull --ff-only origin "$BRANCH"

# Deploy backend source first so the server has matching migration code.
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

# Apply migrations validated against the live beta schema snapshot.
php "$LIVE/backend/cli/apply_022_management_roles.php"
php "$LIVE/backend/cli/apply_023_employee_store_access.php"
php "$LIVE/backend/cli/apply_024_store_weekly_hours.php"
php "$LIVE/backend/cli/apply_025_employee_hourly_rate_history.php"
php "$LIVE/backend/cli/apply_026_store_code_uniqueness.php"
php "$LIVE/backend/cli/apply_027_store_profile_defaults.php"

# Only deploy the portal once validated migrations have succeeded.
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

publish_schema_snapshot() {
  local snapshot_tmp worktree snapshot_path
  snapshot_tmp="$(mktemp)" || return 1
  worktree="$HOME/.merdpos-schema-worktree.$$"
  snapshot_path="LIVE_SCHEMA_SNAPSHOT.json"

  if ! php "$LIVE/backend/cli/export_schema_metadata.php" > "$snapshot_tmp"; then
    rm -f "$snapshot_tmp"
    return 1
  fi

  git -C "$REPO" fetch origin "$SCHEMA_BRANCH" >/dev/null 2>&1 || {
    rm -f "$snapshot_tmp"
    return 1
  }

  rm -rf "$worktree"
  if ! git -C "$REPO" worktree add --detach "$worktree" "origin/$SCHEMA_BRANCH" >/dev/null 2>&1; then
    rm -f "$snapshot_tmp"
    return 1
  fi

  cp "$snapshot_tmp" "$worktree/$snapshot_path"
  rm -f "$snapshot_tmp"

  if [[ -n "$(git -C "$worktree" status --porcelain -- "$snapshot_path")" ]]; then
    git -C "$worktree" add "$snapshot_path"
    git -C "$worktree" \
      -c user.name='Namecheap Schema Snapshot' \
      -c user.email='schema-snapshot@merdpos.local' \
      commit -m 'Update sanitized Namecheap beta schema snapshot' >/dev/null
    if ! git -C "$worktree" push origin HEAD:"$SCHEMA_BRANCH" >/dev/null 2>&1; then
      git -C "$REPO" worktree remove --force "$worktree" >/dev/null 2>&1 || true
      rm -rf "$worktree"
      return 1
    fi
    echo "Live schema snapshot updated on $SCHEMA_BRANCH."
  else
    echo "Live schema snapshot unchanged."
  fi

  git -C "$REPO" worktree remove --force "$worktree" >/dev/null 2>&1 || true
  rm -rf "$worktree"
  return 0
}

if ! publish_schema_snapshot; then
  echo "WARNING: live schema snapshot could not be published; beta deployment itself succeeded." >&2
fi

echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] MERDPOS beta deploy finished"
