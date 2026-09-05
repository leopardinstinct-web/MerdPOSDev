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
php84 "$DRUPAL/tools/validate_merdpos_authenticator.php"
php84 "$DRUPAL/tools/validate_login_rich_ui.php"
php84 "$DRUPAL/tools/validate_parity_provider.php"
php84 "$DRUPAL/tools/validate_operations_hr_v2.php"
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
php84 "$DRUSH_PHP" --root="$WEB" en merdpos_core dashboard charts charts_google ui_patterns ui_icons better_exposed_filters gin_toolbar -y
php84 "$DRUSH_PHP" --root="$WEB" theme:enable gin -y
php84 "$DRUSH_PHP" --root="$WEB" config:set system.theme admin gin -y
php84 "$DRUSH_PHP" --root="$WEB" config:set system.site page.front /merdpos -y
php84 "$DRUSH_PHP" --root="$WEB" updb -y
php84 "$DRUSH_PHP" --root="$WEB" php:eval '$r=\Drupal\user\Entity\Role::load("merdpos_user"); if(!$r){fwrite(STDERR,"MERDPOS USER role missing.\n");exit(1);} if(!$r->hasPermission("view merdpos management dashboard")){$r->grantPermission("view merdpos management dashboard");$r->save();}'
php84 "$DRUSH_PHP" --root="$WEB" cr

LOGIN_PROBE="$(php84 "$DRUSH_PHP" --root="$WEB" php:eval \
  '$r=\Drupal::service("merdpos_core.authenticator")->health(); echo json_encode(["status"=>$r["status"]??null,"http_status"=>$r["http_status"]??null],JSON_UNESCAPED_SLASHES);')"
php84 -r '$p=json_decode($argv[1],true); if(!is_array($p)||($p["status"]??"")!=="ok"||($p["http_status"]??0)!==200){fwrite(STDERR,"MERDPOS login health self-test failed.\n");exit(1);}' "$LOGIN_PROBE"

UI_PROBE="$(php84 "$DRUSH_PHP" --root="$WEB" php:eval \
  '$mods=["dashboard","charts","charts_google","ui_patterns","ui_icons","better_exposed_filters","gin_toolbar"]; $r=[]; foreach($mods as $m){$r[$m]=\Drupal::moduleHandler()->moduleExists($m)?"ok":"missing";} $r["admin_theme"]=\Drupal::config("system.theme")->get("admin"); echo json_encode($r,JSON_UNESCAPED_SLASHES);')"
php84 -r '$p=json_decode($argv[1],true); if(!is_array($p)||($p["admin_theme"]??"")!=="gin"){fwrite(STDERR,"Free Drupal UI stack self-test failed.\n");exit(1);} foreach(["dashboard","charts","charts_google","ui_patterns","ui_icons","better_exposed_filters","gin_toolbar"] as $k){if(($p[$k]??"")!=="ok"){fwrite(STDERR,"Free Drupal UI stack missing {$k}.\n");exit(1);}}' "$UI_PROBE"

if [[ ! -s "$WEB/libraries/google_charts/loader.js" ]]; then
  echo "Google Charts loader asset is missing." >&2
  exit 1
fi

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

DASHBOARD_V2_PROBE="$(php84 "$DRUSH_PHP" --root="$WEB" php:eval \
  '$r=\Drupal::service("merdpos_core.parity_provider")->home(["period"=>"7"]); $a=$r["allowed_widgets"]??[]; $c=$r["chart_specs"]??[]; $role=$r["role"]??[]; echo json_encode(["status"=>$r["status"]??null,"role"=>$role["key"]??null,"loa"=>$role["loa"]??null,"allowed"=>is_array($a)?count($a):-1,"visible"=>$r["visible_widget_count"]??-1,"charts"=>is_array($c)?count($c):-1],JSON_UNESCAPED_SLASHES);')"
php84 -r '$p=json_decode($argv[1],true); if(!is_array($p)||($p["status"]??"")!=="ok"||($p["role"]??"")!=="DEV"||($p["allowed"]??0)<1||($p["visible"]??-1)!==($p["allowed"]??-2)||($p["charts"]??0)<1){fwrite(STDERR,"Rich dashboard v2 self-test failed.\n");exit(1);}' "$DASHBOARD_V2_PROBE"

OPERATIONS_V2_PROBE="$(php84 "$DRUSH_PHP" --root="$WEB" php:eval \
  '$r=\Drupal::service("merdpos_core.parity_provider")->section("operations",["period"=>"7"]); $role=$r["role"]??[]; echo json_encode(["status"=>$r["status"]??null,"role"=>$role["key"]??null,"loa"=>$role["loa"]??null,"metrics"=>count($r["metrics"]??[]),"charts"=>count($r["chart_specs"]??[]),"directory"=>!empty($r["directory_available"]),"store_admin"=>!empty($r["store_admin_available"])],JSON_UNESCAPED_SLASHES);')"
php84 -r '$p=json_decode($argv[1],true); if(!is_array($p)||($p["status"]??"")!=="ok"||($p["role"]??"")!=="DEV"||($p["loa"]??0)!==1000||($p["metrics"]??0)<4||($p["charts"]??0)<1||empty($p["directory"])||empty($p["store_admin"])){fwrite(STDERR,"Operations HR v2 self-test failed.\n");exit(1);}' "$OPERATIONS_V2_PROBE"

HEAD="$(git -C "$REPO" rev-parse HEAD)"
BRANCH="$(git -C "$REPO" branch --show-current)"
STAMP="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
php84 -r '$p=json_decode($argv[4],true); $g=json_decode($argv[5],true); $d=json_decode($argv[6],true); $a=json_decode($argv[7],true); $l=json_decode($argv[8],true); $u=json_decode($argv[9],true); $v=json_decode($argv[10],true); $o=json_decode($argv[11],true); echo json_encode([
  "commit"=>$argv[1],"branch"=>$argv[2],"deployed_at"=>$argv[3],
  "working_now_status"=>$p["status"]??null,"working_now_count"=>$p["count"]??null,
  "gateway_status"=>$g["status"]??null,"gateway_role"=>$g["role"]??null,"gateway_is_dev"=>$g["is_dev"]??null,
  "gateway_dev_status"=>$d["status"]??null,
  "login_status"=>$l["status"]??null,
  "free_ui_stack"=>$u,
  "dashboard_v2"=>$v,
  "operations_v2"=>$o,
  "parity_status"=>(is_array($a)&&count(array_filter($a,static fn($v)=>$v!=="ok"))===0)?"ok":"failed",
  "parity_surfaces"=>$a
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";' \
  "$HEAD" "$BRANCH" "$STAMP" "$PROBE" "$GATEWAY_PROBE" "$DEV_PROBE" "$PARITY_PROBE" "$LOGIN_PROBE" "$UI_PROBE" "$DASHBOARD_V2_PROBE" "$OPERATIONS_V2_PROBE" > "$WEB/.merdpos_drupal_release.json"
chmod 644 "$WEB/.merdpos_drupal_release.json"

echo "MERDPOS Drupal deploy verified at ${HEAD:0:12}."
