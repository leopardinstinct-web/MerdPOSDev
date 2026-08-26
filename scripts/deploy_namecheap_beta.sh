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

echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] MERDPOS beta deploy started (pid $$)"

exec 9>"$LOCK"
if ! flock -n 9; then
  echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] deploy skipped: another run holds $LOCK"
  exit 0
fi

for command_name in git ssh rsync php flock mktemp find grep; do
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

# Fail closed before touching the live beta if any PHP endpoint, include or
# migration has a parse error. This prevents empty-response HTTP 500 regressions.
echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] linting MERDPOS PHP source"
php_lint_failed=0
while IFS= read -r -d '' php_file; do
  if ! php -l "$php_file" >/dev/null; then
    echo "ERROR: PHP syntax check failed: $php_file" >&2
    php_lint_failed=1
  fi
done < <(find "$REPO/namecheap_beta_live/backend" "$REPO/namecheap_beta_live/timesheet_portal" -type f -name '*.php' -print0)
if [[ "$php_lint_failed" -ne 0 ]]; then
  echo "ERROR: beta deployment aborted because PHP syntax validation failed." >&2
  exit 1
fi
echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] PHP lint passed"

# Authorization coverage is a release invariant. This checks the catalogue,
# widget permission bindings, every protected portal API and fail-closed route
# registration before any source is copied to the live beta.
echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] validating portal LOA permission coverage"
php "$REPO/namecheap_beta_live/backend/cli/validate_portal_permission_policy.php"

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

php "$LIVE/backend/cli/apply_022_management_roles.php"
php "$LIVE/backend/cli/apply_023_employee_store_access.php"
php "$LIVE/backend/cli/apply_024_store_weekly_hours.php"
php "$LIVE/backend/cli/apply_025_employee_hourly_rate_history.php"
php "$LIVE/backend/cli/apply_026_store_code_uniqueness.php"
php "$LIVE/backend/cli/apply_027_store_profile_defaults.php"
php "$LIVE/backend/cli/apply_028_client_role_authority.php"
php "$LIVE/backend/cli/apply_029_dashboard_layouts.php"
php "$LIVE/backend/cli/apply_030_dev_client_preferences.php"
php "$LIVE/backend/cli/apply_031_client_roles_dashboard_templates.php"
php "$LIVE/backend/cli/apply_032_seed_role_dashboards.php"
php "$LIVE/backend/cli/apply_033_portal_permission_levels.php"

rsync -az \
  --exclude='config.php' \
  --exclude='.env' \
  --exclude='.env.*' \
  --exclude='.deployed_version' \
  --exclude='*.log' \
  "$REPO/namecheap_beta_live/timesheet_portal/" \
  "$LIVE/timesheet_portal/"

# Role/LOA rendering requires dashboard.php to open the DB while it is still an
# HTML page. The secure backend DB config is shared with API code and is not in
# Git, so the portal must restore text/html after that config is loaded. Verify
# the exact boundary guard reached the live copy before marking deployment good.
if ! grep -q 'restoreHtmlResponse' "$LIVE/timesheet_portal/includes/database.php"; then
  echo "ERROR: live portal is missing the HTML/DB response-boundary guard." >&2
  exit 1
fi
if ! grep -q 'portal_html_response_headers' "$LIVE/timesheet_portal/includes/database.php"; then
  echo "ERROR: live portal cannot restore its HTML response after DB config load." >&2
  exit 1
fi
echo "Portal HTML/DB response boundary verified."

# Detect response-header logic in the private backend config without printing
# the file path, contents, credentials or other secrets. This is diagnostic only;
# the page response guard above neutralizes any such side effect for HTML pages.
backend_config_path="$(php -r "require '$LIVE/timesheet_portal/includes/config.php'; if (defined('BACKEND_CONFIG_PATH')) echo BACKEND_CONFIG_PATH;" 2>/dev/null || true)"
if [[ -n "$backend_config_path" && -r "$backend_config_path" ]]; then
  if grep -Eqi 'Content-Type|header[[:space:]]*\(' "$backend_config_path"; then
    echo "Shared backend config contains HTTP response-header logic; HTML restoration guard is active."
  else
    echo "Shared backend config has no detectable HTTP response-header directive."
  fi
else
  echo "WARNING: private backend config could not be inspected for response-header side effects." >&2
fi

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
