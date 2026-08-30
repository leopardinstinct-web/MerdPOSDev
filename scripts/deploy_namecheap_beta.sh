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

if [[ "${MERDPOS_BETA_DEPLOY_REEXEC:-0}" == "1" ]]; then
  if ! { true >&9; } 2>/dev/null; then
    echo "ERROR: refreshed deploy script lost the inherited deployment lock." >&2
    exit 1
  fi
  echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] resumed refreshed deploy script under inherited lock"
else
  exec 9>"$LOCK"
  if ! flock -n 9; then
    echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] deploy skipped: another run holds $LOCK"
    exit 0
  fi
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
DEPLOY_SCRIPT_BLOB_BEFORE="$(git rev-parse HEAD:scripts/deploy_namecheap_beta.sh 2>/dev/null || true)"
echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] fetching $BRANCH from GitHub"
git fetch origin "$BRANCH"
git switch "$BRANCH" >/dev/null 2>&1 || git checkout "$BRANCH"
git pull --ff-only origin "$BRANCH"
DEPLOY_SCRIPT_BLOB_AFTER="$(git rev-parse HEAD:scripts/deploy_namecheap_beta.sh 2>/dev/null || true)"
if [[ "${MERDPOS_BETA_DEPLOY_REEXEC:-0}" != "1" && -n "$DEPLOY_SCRIPT_BLOB_BEFORE" && "$DEPLOY_SCRIPT_BLOB_BEFORE" != "$DEPLOY_SCRIPT_BLOB_AFTER" ]]; then
  echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] deploy script changed during pull; restarting from refreshed source"
  exec env MERDPOS_BETA_DEPLOY_REEXEC=1 bash "$REPO/scripts/deploy_namecheap_beta.sh"
fi

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

echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] validating beta runtime/README/design-system contract"
php "$REPO/namecheap_beta_live/backend/cli/validate_beta_runtime_contract.php"

echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] validating portal LOA permission coverage"
php "$REPO/namecheap_beta_live/backend/cli/validate_portal_permission_policy.php"

echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] validating deterministic portal loader order"
php "$REPO/namecheap_beta_live/backend/cli/validate_portal_loader_order.php"

echo "[$(date -u '+%Y-%m-%dT%H:%M:%SZ')] validating shared beta-state permission scope"
php "$REPO/namecheap_beta_live/backend/cli/validate_beta_state_scope.php"

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
php "$LIVE/backend/cli/apply_035_ui_studio_global_history.php"

php -r '
require $argv[1];
$tables=["client_legacy_sources","client_migration_state","legacy_migration_batches","legacy_migration_stage_rows","legacy_migration_records","legacy_migration_conflicts"];
$q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
foreach($tables as $table){$q->execute([$table]);if((int)$q->fetchColumn()!==1){fwrite(STDERR,"Missing legacy migration table: {$table}\n");exit(1);}}
echo "Legacy migration schema verified (6 tables).\n";
' "$LIVE/backend/api/config.php"

php -r '
require $argv[1];
$tables=["ui_studio_state","ui_studio_history"];
$q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
foreach($tables as $table){$q->execute([$table]);if((int)$q->fetchColumn()!==1){fwrite(STDERR,"Missing UI Studio table: {$table}\n");exit(1);}}
echo "UI Studio global history schema verified (2 tables).\n";
' "$LIVE/backend/api/config.php"

rsync -az \
  --exclude='config.php' \
  --exclude='.env' \
  --exclude='.env.*' \
  --exclude='.deployed_version' \
  --exclude='*.log' \
  "$REPO/namecheap_beta_live/timesheet_portal/" \
  "$LIVE/timesheet_portal/"

# Live-copy gate. The marker is not written unless the canonical design/runtime
# contract survived rsync to Namecheap.
for live_file in \
  "$LIVE/timesheet_portal/.htaccess" \
  "$LIVE/timesheet_portal/assets/management.js" \
  "$LIVE/timesheet_portal/assets/design-tokens.css" \
  "$LIVE/timesheet_portal/assets/design-system.css" \
  "$LIVE/timesheet_portal/assets/design-audit.js" \
  "$LIVE/timesheet_portal/assets/minimal-controls.js" \
  "$LIVE/timesheet_portal/assets/mobile-runtime.js" \
  "$LIVE/timesheet_portal/assets/shell.css" \
  "$LIVE/timesheet_portal/assets/app-ui.css" \
  "$LIVE/timesheet_portal/assets/dashboard-builder.css" \
  "$LIVE/timesheet_portal/assets/brand/brand.css" \
  "$LIVE/timesheet_portal/assets/brand/brand-assets.js" \
  "$LIVE/timesheet_portal/assets/brand/merdpos-logo-approved.png" \
  "$LIVE/timesheet_portal/assets/brand/merdpos-mark.png" \
  "$LIVE/timesheet_portal/assets/brand/merdpos-wordmark.png" \
  "$LIVE/timesheet_portal/assets/brand/merdpos-tagline.png" \
  "$LIVE/timesheet_portal/assets/account-menu.css" \
  "$LIVE/timesheet_portal/assets/modal-lock.js" \
  "$LIVE/timesheet_portal/includes/legacy_known_fetch.php" \
  "$LIVE/timesheet_portal/README.md" \
  "$LIVE/backend/README.md"; do
  if [[ ! -r "$live_file" ]]; then
    echo "ERROR: required live beta runtime/README file missing after deploy: $(basename "$live_file")" >&2
    exit 1
  fi
done

for required_asset in \
  'assets/design-tokens.css?v=20260828palette1' \
  'assets/design-system.css?v=20260830pills1' \
  'assets/design-audit.js?v=20260826ds1' \
  'assets/omnichannel-identity.js?v=20260830pills1' \
  'assets/minimal-controls.js?v=20260826ds1' \
  'assets/mobile-runtime.js?v=20260828mobile1' \
  'assets/shell.css?v=20260830bottom1' \
  'assets/navigation.js?v=20260830bottom1' \
  'assets/dashboard-builder.css?v=20260830dashboardstudio3' \
  'assets/dashboard-builder.js?v=20260830dashboardstudio3' \
  'assets/account-menu.css?v=20260830about2' \
  'assets/account-menu.js?v=20260830roleview4' \
  'assets/ui-studio.css?v=20260830studio25' \
  'assets/ui-studio.js?v=20260830studio25'; do
  if ! grep -q "$required_asset" "$LIVE/timesheet_portal/assets/management.js"; then
    echo "ERROR: live management runtime is missing canonical asset: $required_asset" >&2
    exit 1
  fi
done

if ! grep -q 'assets/management.js?v=20260830studio25' "$LIVE/timesheet_portal/dashboard.php"; then
  echo "ERROR: live dashboard is missing the status-pill management runtime." >&2
  exit 1
fi

for material_symbol in LICENSE-Apache-2.0.txt NOTICE.md ads_click_48px.svg arrow_back_48px.svg arrow_forward_48px.svg chat_48px.svg close_48px.svg content_copy_48px.svg delete_48px.svg edit_48px.svg edit_note_48px.svg filter_center_focus_48px.svg format_color_fill_48px.svg format_color_text_48px.svg input_48px.svg open_with_48px.svg palette_48px.svg restart_alt_48px.svg tune_48px.svg undo_48px.svg visibility_48px.svg visibility_off_48px.svg gesture_select_48px.svg text_fields_48px.svg dashboard_48px.svg padding_48px.svg border_outer_48px.svg space_bar_48px.svg rounded_corner_48px.svg swap_horiz_48px.svg format_size_48px.svg my_location_48px.svg comment_48px.svg add_circle_48px.svg history_48px.svg arrow_upward_48px.svg arrow_downward_48px.svg; do
  if [[ ! -r "$LIVE/timesheet_portal/assets/vendor/google-material-symbols/$material_symbol" ]]; then
    echo "ERROR: live UI Studio Material Symbol asset missing: $material_symbol" >&2
    exit 1
  fi
done

if ! grep -q 'assets/brand/brand-assets.js?v=20260827brand4' "$LIVE/timesheet_portal/assets/management.js"; then
  echo "ERROR: live management runtime is missing the canonical brand asset registry." >&2
  exit 1
fi
for canonical_brand_asset in 'merdpos-logo-approved.png' 'merdpos-mark.png' 'merdpos-wordmark.png' 'merdpos-tagline.png'; do
  if ! grep -q "$canonical_brand_asset" "$LIVE/timesheet_portal/assets/brand/brand-assets.js"; then
    echo "ERROR: live brand registry is missing canonical asset: $canonical_brand_asset" >&2
    exit 1
  fi
done

for retired_asset in \
  'assets/apple-principles.css' \
  'assets/ui-standard.css' \
  'assets/minimal-controls.css' \
  'assets/mobile-hardening.css' \
  'assets/omnichannel-identity.css'; do
  if grep -q "$retired_asset" "$LIVE/timesheet_portal/assets/management.js"; then
    echo "ERROR: live management runtime still loads competing CSS layer: $retired_asset" >&2
    exit 1
  fi
done

if ! grep -q -- '--color-brand-primary' "$LIVE/timesheet_portal/assets/design-tokens.css"; then
  echo "ERROR: live design tokens are missing semantic brand ownership." >&2
  exit 1
fi
if ! grep -q -- '--size-icon-action: 2.875rem' "$LIVE/timesheet_portal/assets/design-tokens.css"; then
  echo "ERROR: live design tokens are missing canonical desktop action geometry." >&2
  exit 1
fi
if ! grep -q -- '--size-touch: 3rem' "$LIVE/timesheet_portal/assets/design-tokens.css"; then
  echo "ERROR: live design tokens are missing the 48px touch target." >&2
  exit 1
fi
if ! grep -q 'button.merd-icon-action' "$LIVE/timesheet_portal/assets/design-system.css"; then
  echo "ERROR: live design system is missing the canonical Add action primitive." >&2
  exit 1
fi
if ! grep -q '.merd-collapsible-search' "$LIVE/timesheet_portal/assets/design-system.css"; then
  echo "ERROR: live design system is missing the canonical Search primitive." >&2
  exit 1
fi
if ! grep -q '.merd-action-cluster' "$LIVE/timesheet_portal/assets/design-system.css"; then
  echo "ERROR: live design system is missing Search+Add placement." >&2
  exit 1
fi
if ! grep -q 'contrastRatio' "$LIVE/timesheet_portal/assets/design-audit.js"; then
  echo "ERROR: live design audit is missing WCAG contrast checking." >&2
  exit 1
fi
if ! grep -q 'heading:h1-count' "$LIVE/timesheet_portal/assets/design-audit.js"; then
  echo "ERROR: live design audit is missing heading hierarchy checks." >&2
  exit 1
fi
if ! grep -q 'placement:search-add-height' "$LIVE/timesheet_portal/assets/design-audit.js"; then
  echo "ERROR: live design audit is missing Search/Add geometry verification." >&2
  exit 1
fi
if ! grep -q 'touch:under-44' "$LIVE/timesheet_portal/assets/design-audit.js"; then
  echo "ERROR: live design audit is missing touch-target verification." >&2
  exit 1
fi
if ! grep -q 'clusterSearchAndAdd' "$LIVE/timesheet_portal/assets/minimal-controls.js"; then
  echo "ERROR: live minimal-control behavior is not clustering Search beside Add." >&2
  exit 1
fi
if ! grep -q '.dashboard-add-button' "$LIVE/timesheet_portal/assets/minimal-controls.js"; then
  echo "ERROR: live Dashboard Add is not normalized through the shared action behavior." >&2
  exit 1
fi
if ! grep -q 'MERDPOSMobileRuntime' "$LIVE/timesheet_portal/assets/mobile-runtime.js"; then
  echo "ERROR: live mobile runtime is missing its enhancement/audit hook." >&2
  exit 1
fi
if ! grep -q 'moveDashboardWidget' "$LIVE/timesheet_portal/assets/mobile-runtime.js"; then
  echo "ERROR: live mobile runtime is missing Dashboard mobile reorder parity." >&2
  exit 1
fi
if ! grep -q "lockMode = mobileSafeLock() ? 'mobile-overflow'" "$LIVE/timesheet_portal/assets/modal-lock.js"; then
  echo "ERROR: live modal lock is not using mobile-keyboard-safe overflow locking." >&2
  exit 1
fi
if ! grep -q 'Cache-Control "no-cache, must-revalidate"' "$LIVE/timesheet_portal/.htaccess"; then
  echo "ERROR: live portal is missing design-system cache revalidation." >&2
  exit 1
fi
for cache_asset in 'design-tokens\\.css' 'design-system\\.css' 'brand\\/brand\\.css' 'brand\\/brand-assets\\.js' 'brand\\/merdpos-logo-approved\\.png' 'brand\\/merdpos-mark\\.png' 'brand\\/merdpos-wordmark\\.png' 'brand\\/merdpos-tagline\\.png' 'design-audit\\.js' 'minimal-controls\\.js' 'mobile-runtime\\.js'; do
  if ! grep -q "$cache_asset" "$LIVE/timesheet_portal/.htaccess"; then
    echo "ERROR: live portal is not revalidating shared design asset pattern: $cache_asset" >&2
    exit 1
  fi
done
if ! grep -q 'legacy_fetch_sources_known' "$LIVE/timesheet_portal/includes/legacy_migration_orchestrator.php"; then
  echo "ERROR: live migration runtime is not using deterministic known Sheet contracts." >&2
  exit 1
fi

echo "Live beta runtime wiring verified (canonical tokens/components, heading/contrast/touch/placement audit, Add/Search behavior, mobile parity, cache revalidation, deterministic legacy reader)."

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

release_commit_full="$(git rev-parse HEAD)"
release_commit_short="$(git rev-parse --short=7 HEAD)"
release_commit_date="$(git show -s --format=%cI HEAD)"
studio_commit_full="$(git log -1 --format=%H -- namecheap_beta_live/timesheet_portal/assets/ui-studio.js namecheap_beta_live/timesheet_portal/assets/ui-studio.css)"
studio_commit_short="$(git log -1 --format=%h -- namecheap_beta_live/timesheet_portal/assets/ui-studio.js namecheap_beta_live/timesheet_portal/assets/ui-studio.css)"
studio_commit_date="$(git log -1 --format=%cI -- namecheap_beta_live/timesheet_portal/assets/ui-studio.js namecheap_beta_live/timesheet_portal/assets/ui-studio.css)"
mapfile -t release_highlights < <(git log -3 --pretty=format:%s)
php -r '$data=["merdpos"=>["commit"=>$argv[2],"short"=>$argv[3],"date"=>$argv[4]],"devstudio"=>["commit"=>$argv[5],"short"=>$argv[6],"date"=>$argv[7]],"highlights"=>array_values(array_filter([$argv[8]??"",$argv[9]??"",$argv[10]??""]))];file_put_contents($argv[1],json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL);'   "$LIVE/.beta_release.json" "$release_commit_full" "$release_commit_short" "$release_commit_date"   "$studio_commit_full" "$studio_commit_short" "$studio_commit_date"   "${release_highlights[0]:-}" "${release_highlights[1]:-}" "${release_highlights[2]:-}"
php -r '$p=$argv[1];$d=json_decode((string)file_get_contents($p),true);if(!is_array($d)||empty($d["merdpos"]["commit"])||empty($d["devstudio"]["commit"])||count($d["highlights"]??[])<3){fwrite(STDERR,"Invalid beta release metadata.\n");exit(1);}echo "Git release metadata verified.\n";' "$LIVE/.beta_release.json"

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
