#!/usr/bin/env bash
set -euo pipefail

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

# Beta contract check deliberately distinguishes documentation from runtime
# implementation. It verifies the authoritative READMEs/context exist AND that
# binding global behavior such as minimal Add/Search and the deterministic
# legacy Sheet reader is actually wired into the runtime entry path.
echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] validating beta runtime/README contract"
php "$REPO/namecheap_beta_live/backend/cli/validate_beta_runtime_contract.php"

# Authorization coverage is a release invariant.
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
php "$LIVE/backend/cli/apply_034_legacy_migration_sync.php"

php -r '
require $argv[1];
$tables=["client_legacy_sources","client_migration_state","legacy_migration_batches","legacy_migration_stage_rows","legacy_migration_records","legacy_migration_conflicts"];
$q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
foreach($tables as $table){$q->execute([$table]);if((int)$q->fetchColumn()!==1){fwrite(STDERR,"Missing legacy migration table: {$table}\n");exit(1);}}
echo "Legacy migration schema verified (6 tables).\n";
' "$LIVE/backend/api/config.php"

rsync -az \
  --exclude='config.php' \
  --exclude='.env' \
  --exclude='.env.*' \
  --exclude='.deployed_version' \
  --exclude='*.log' \
  "$REPO/namecheap_beta_live/timesheet_portal/" \
  "$LIVE/timesheet_portal/"

# Verify that the exact global runtime layers survived rsync. This is the live
# counterpart to the source validator above and prevents a successful deploy
# marker when the page cannot actually load a documented global behavior.
for live_file in \
  "$LIVE/timesheet_portal/.htaccess" \
  "$LIVE/timesheet_portal/assets/minimal-controls.js" \
  "$LIVE/timesheet_portal/assets/minimal-controls.css" \
  "$LIVE/timesheet_portal/assets/ui-standard.css" \
  "$LIVE/timesheet_portal/includes/legacy_known_fetch.php" \
  "$LIVE/timesheet_portal/README.md" \
  "$LIVE/backend/README.md"; do
  if [[ ! -r "$live_file" ]]; then
    echo "ERROR: required live beta runtime/README file missing after deploy." >&2
    exit 1
  fi
done

if ! grep -q 'assets/minimal-controls.css?v=20260826b' "$LIVE/timesheet_portal/assets/management.js"; then
  echo "ERROR: live management runtime is not loading the current minimal-control CSS." >&2
  exit 1
fi
if ! grep -q 'assets/minimal-controls.js?v=20260826b' "$LIVE/timesheet_portal/assets/management.js"; then
  echo "ERROR: live management runtime is not loading the current minimal-control behavior." >&2
  exit 1
fi
if ! grep -q -- '--merd-action-diameter:46px' "$LIVE/timesheet_portal/assets/minimal-controls.css"; then
  echo "ERROR: live minimal-control CSS is missing the canonical 46px desktop action diameter." >&2
  exit 1
fi
if ! grep -q 'border-radius:50%!important' "$LIVE/timesheet_portal/assets/minimal-controls.css"; then
  echo "ERROR: live minimal-control CSS is missing the canonical true-circle geometry." >&2
  exit 1
fi
if ! grep -q 'clusterSearchAndAdd' "$LIVE/timesheet_portal/assets/minimal-controls.js"; then
  echo "ERROR: live minimal-control JS is not clustering Search beside Add." >&2
  exit 1
fi
if ! grep -q '.dashboard-add-button' "$LIVE/timesheet_portal/assets/minimal-controls.js"; then
  echo "ERROR: live Dashboard Add is not normalized through the shared action primitive." >&2
  exit 1
fi
if ! grep -q 'Cache-Control "no-cache, must-revalidate"' "$LIVE/timesheet_portal/.htaccess"; then
  echo "ERROR: live portal is missing shared UI cache revalidation." >&2
  exit 1
fi
if ! grep -q 'minimal-controls\\.js' "$LIVE/timesheet_portal/.htaccess"; then
  echo "ERROR: live portal is not revalidating the minimal-control behavior asset." >&2
  exit 1
fi
if ! grep -q 'legacy_fetch_sources_known' "$LIVE/timesheet_portal/includes/legacy_migration_orchestrator.php"; then
  echo "ERROR: live migration runtime is not using deterministic known Sheet contracts." >&2
  exit 1
fi
echo "Live beta runtime wiring verified (canonical Add/Search primitive, action clustering, shared UI cache revalidation, mobile UI standard, deterministic legacy reader, READMEs)."

if ! grep -q 'restoreHtmlResponse' "$LIVE/timesheet_portal/includes/database.php"; then
  echo "ERROR: live portal is missing the HTML/DB response-boundary guard." >&2
  exit 1
fi
if ! grep -q 'portal_html_response_headers' "$LIVE/timesheet_portal/includes/database.php"; then
  echo "ERROR: live portal cannot restore its HTML response after DB config load." >&2
  exit 1
fi
echo "Portal HTML/DB response boundary verified."

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
