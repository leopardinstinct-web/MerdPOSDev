#!/usr/bin/env bash
set -euo pipefail

REPO=/home/dridsheikh/merdpos-drupal
DRUPAL="$REPO/drupal"
WEB="$DRUPAL/web"
PRIVATE=/home/dridsheikh/.merdpos_drupal_private
SYNC=/home/dridsheikh/.merdpos_drupal_config_sync
ADMIN_SECRET=/home/dridsheikh/.merdpos_drupal_admin_password

PHP_BIN=/opt/alt/php84/usr/bin/php
COMPOSER=/home/dridsheikh/.merdpos-tools/composer.phar

if [[ ! -x "$PHP_BIN" || ! -f "$COMPOSER" ]]; then
  echo "PHP 8.4 or private Composer tool is missing." >&2
  exit 1
fi
php84() {
  "$PHP_BIN" "$@"
}
php84 -r 'if(PHP_VERSION_ID<80400){fwrite(STDERR,"PHP 8.4 required\n");exit(1);}'

if [[ ! -f "$DRUPAL/deploy/php84.ini" ]]; then
  echo "Git-owned PHP 8.4 site ini is missing." >&2
  exit 1
fi
cp "$DRUPAL/deploy/php84.ini" "$WEB/php.ini"
chmod 644 "$WEB/php.ini"
touch /home/dridsheikh/.lsphp_restart.txt
export PHPRC="$WEB"
export PATH="/opt/alt/php84/usr/bin:$PATH"
php84 -r '$required=["curl","dom","fileinfo","gd","mbstring","pdo_mysql","phar","xmlreader","xmlwriter","zip"]; foreach($required as $ext){if(!extension_loaded($ext)){fwrite(STDERR,"Missing PHP extension: $ext\n");exit(1);}}'

cd "$DRUPAL"
php84 "$COMPOSER" install --no-interaction --prefer-dist --optimize-autoloader
php84 "$DRUPAL/tools/validate_portal_gateway_client.php"
php84 "$DRUPAL/tools/validate_parity_provider.php"
php84 "$DRUPAL/tools/sync_merdpos_resources.php" --check
# Composer scaffold rewrites Drupal's .htaccess; restore the Git-owned Namecheap PHP 8.4 handler.
git -C "$REPO" checkout -- drupal/web/.htaccess
php84 "$DRUPAL/tools/namecheap_resolve_runtime.php"
mkdir -p "$PRIVATE" "$SYNC" "$WEB/sites/default/files"
chmod 700 "$PRIVATE" "$SYNC"
chmod 755 "$WEB/sites/default/files"

if [[ -f "$WEB/sites/default/settings.php" ]]; then
  chmod u+w "$WEB/sites/default/settings.php" 2>/dev/null || true
fi
cp "$DRUPAL/deploy/settings.php" "$WEB/sites/default/settings.php"
chmod 640 "$WEB/sites/default/settings.php"

DRUSH_PHP="$DRUPAL/vendor/drush/drush/drush.php"
if [[ ! -f "$DRUSH_PHP" ]]; then
  echo "Drush PHP entry point was not installed by Composer." >&2
  exit 1
fi

if [[ ! -s "$ADMIN_SECRET" ]]; then
  php84 -r 'echo bin2hex(random_bytes(24)),"\n";' > "$ADMIN_SECRET"
  chmod 600 "$ADMIN_SECRET"
fi

BOOTSTRAP="$(php84 "$DRUSH_PHP" --root="$WEB" status --field=bootstrap 2>/dev/null || true)"
if [[ "$BOOTSTRAP" != *Successful* ]]; then
  ADMIN_PASS="$(tr -d '\r\n' < "$ADMIN_SECRET")"
  php84 "$DRUSH_PHP" --root="$WEB" site:install minimal -y \
    --site-name='MERDPOS Drupal Beta' --account-name='merdpos-dev' --account-pass="$ADMIN_PASS"
  unset ADMIN_PASS
fi
php84 "$DRUSH_PHP" --root="$WEB" en merdpos_core -y
php84 "$DRUSH_PHP" --root="$WEB" updb -y
php84 "$DRUSH_PHP" --root="$WEB" cr

PROBE="$(php84 "$DRUSH_PHP" --root="$WEB" php:eval \
  '$r=\Drupal::service("merdpos_core.working_now_provider")->load(); echo json_encode(["status"=>$r["status"]??null,"count"=>$r["count"]??null],JSON_UNESCAPED_SLASHES);')"
php84 -r '$p=json_decode($argv[1],true); if(!is_array($p)||($p["status"]??"")!=="ok"){fwrite(STDERR,"Working Now self-test failed.\n");exit(1);}' "$PROBE"

GATEWAY_PROBE="$(php84 "$DRUSH_PHP" --root="$WEB" php:eval \
  '$r=\Drupal::service("merdpos_core.portal_gateway")->call("beta_state","GET"); $p=$r["payload"]??[]; echo json_encode(["status"=>$r["status"]??null,"success"=>$p["success"]??null,"role"=>$p["role"]??null,"is_dev"=>$p["is_dev"]??null],JSON_UNESCAPED_SLASHES);')"
php84 -r '$p=json_decode($argv[1],true); if(!is_array($p)||($p["status"]??"")!=="ok"||($p["success"]??false)!==true||($p["is_dev"]??false)!==true){fwrite(STDERR,"Portal gateway Beta-state self-test failed.\n");exit(1);}' "$GATEWAY_PROBE"

DEV_PROBE="$(php84 "$DRUSH_PHP" --root="$WEB" php:eval \
  '$r=\Drupal::service("merdpos_core.portal_gateway")->call("dev_status","GET"); $p=$r["payload"]??[]; echo json_encode(["status"=>$r["status"]??null,"success"=>$p["success"]??null],JSON_UNESCAPED_SLASHES);')"
php84 -r '$p=json_decode($argv[1],true); if(!is_array($p)||($p["status"]??"")!=="ok"||($p["success"]??false)!==true){fwrite(STDERR,"Portal gateway DEV self-test failed.\n");exit(1);}' "$DEV_PROBE"

PARITY_PROBE="$(php84 "$DRUSH_PHP" --root="$WEB" php:eval \
  '$r=\Drupal::service("merdpos_core.parity_provider"); $s=["home"=>$r->home(),"operations"=>$r->section("operations"),"reports"=>$r->section("reports"),"finance"=>$r->section("finance"),"dev"=>$r->section("dev")]; echo json_encode(array_map(static fn($v)=>$v["status"]??null,$s),JSON_UNESCAPED_SLASHES);')"
php84 -r '$p=json_decode($argv[1],true); $keys=["home","operations","reports","finance","dev"]; if(!is_array($p)){fwrite(STDERR,"Five-surface parity self-test failed.\n");exit(1);} foreach($keys as $key){if(($p[$key]??"")!=="ok"){fwrite(STDERR,"Five-surface parity self-test failed at {$key}.\n");exit(1);}}' "$PARITY_PROBE"

HEAD="$(git -C "$REPO" rev-parse HEAD)"
BRANCH="$(git -C "$REPO" branch --show-current)"
STAMP="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
php84 -r '$p=json_decode($argv[4],true); $g=json_decode($argv[5],true); $d=json_decode($argv[6],true); $a=json_decode($argv[7],true); echo json_encode([
  "commit"=>$argv[1],"branch"=>$argv[2],"deployed_at"=>$argv[3],
  "working_now_status"=>$p["status"]??null,"working_now_count"=>$p["count"]??null,
  "gateway_status"=>$g["status"]??null,"gateway_role"=>$g["role"]??null,"gateway_is_dev"=>$g["is_dev"]??null,
  "gateway_dev_status"=>$d["status"]??null,
  "parity_status"=>(is_array($a)&&count(array_filter($a,static fn($v)=>$v!=="ok"))===0)?"ok":"failed",
  "parity_surfaces"=>$a
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";' \
  "$HEAD" "$BRANCH" "$STAMP" "$PROBE" "$GATEWAY_PROBE" "$DEV_PROBE" "$PARITY_PROBE" > "$WEB/.merdpos_drupal_release.json"
chmod 644 "$WEB/.merdpos_drupal_release.json"

echo "MERDPOS Drupal deploy verified at ${HEAD:0:12}."
