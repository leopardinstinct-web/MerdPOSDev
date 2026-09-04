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
PHP_EXTENSIONS=(phar dom fileinfo gd mbstring xmlreader xmlwriter zip)

if [[ ! -x "$PHP_BIN" || ! -f "$COMPOSER" ]]; then
  echo "PHP 8.4 or private Composer tool is missing." >&2
  exit 1
fi
php84() {
  local args=("$PHP_BIN")
  local ext
  for ext in "${PHP_EXTENSIONS[@]}"; do
    args+=( -d "extension=${ext}.so" )
  done
  "${args[@]}" "$@"
}
php84 -r 'if(PHP_VERSION_ID<80400){fwrite(STDERR,"PHP 8.4 required\n");exit(1);}'

if [[ ! -f /opt/alt/default_php_ini/php84.ini ]]; then
  echo "Namecheap PHP 8.4 default ini is missing." >&2
  exit 1
fi
cp /opt/alt/default_php_ini/php84.ini "$WEB/php.ini"
chmod 644 "$WEB/php.ini"
touch /home/dridsheikh/.lsphp_restart.txt

cd "$DRUPAL"
php84 "$COMPOSER" install --no-interaction --prefer-dist --optimize-autoloader
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

HEAD="$(git -C "$REPO" rev-parse HEAD)"
BRANCH="$(git -C "$REPO" branch --show-current)"
STAMP="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
php84 -r '$p=json_decode($argv[4],true); echo json_encode([
  "commit"=>$argv[1],"branch"=>$argv[2],"deployed_at"=>$argv[3],
  "working_now_status"=>$p["status"]??null,"working_now_count"=>$p["count"]??null
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";' \
  "$HEAD" "$BRANCH" "$STAMP" "$PROBE" > "$WEB/.merdpos_drupal_release.json"
chmod 644 "$WEB/.merdpos_drupal_release.json"

echo "MERDPOS Drupal deploy verified at ${HEAD:0:12}."
