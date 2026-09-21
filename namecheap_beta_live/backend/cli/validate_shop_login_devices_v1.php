<?php
declare(strict_types=1);

function shop_login_check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

$root = dirname(__DIR__, 2);
$backend = $root . '/backend';
$portal = $root . '/timesheet_portal';
$migration = file_get_contents($backend . '/sql/039_shop_login_devices.sql');
$apply = file_get_contents($backend . '/cli/apply_039_shop_login_devices.php');
$permissions = file_get_contents($backend . '/api/includes/portal_permissions.php');
$workforce = file_get_contents($backend . '/api/includes/workforce_beta.php');
$gateway = file_get_contents($backend . '/api/integrations/portal_gateway.php');
$api = file_get_contents($portal . '/includes/beta_api.php');
$scan = file_get_contents($portal . '/api/attendance_scan.php');
$devices = file_get_contents($portal . '/api/store_devices.php');
$state = file_get_contents($portal . '/api/beta_state.php');
$finance = file_get_contents($portal . '/api/financials.php');
$directory = file_get_contents($portal . '/api/admin_directory.php');
$deployPath = dirname($root) . '/scripts/deploy_namecheap_beta.sh';
$deploy = is_file($deployPath) ? file_get_contents($deployPath) : null;
foreach ([$migration,$apply,$permissions,$workforce,$gateway,$api,$scan,$devices,$state,$finance,$directory] as $source) {
    shop_login_check(is_string($source), 'Shop login release source is unreadable.');
}
shop_login_check(str_contains($migration, 'ADD COLUMN IF NOT EXISTS device_code CHAR(4)'), 'Migration 039 device_code missing.');
shop_login_check(str_contains($migration, 'uq_devices_client_device_code'), 'Migration 039 per-client POS code uniqueness missing.');
shop_login_check(str_contains($migration, 'CREATE TABLE IF NOT EXISTS shop_qr_uses'), 'Shop QR replay ledger missing.');
shop_login_check(str_contains($apply, 'shop_qr_uses') && str_contains($apply, 'device_code'), 'Migration 039 apply verification missing.');

shop_login_check(str_contains($permissions, "'stores.devices.manage'"), 'Store-device permission missing.');
shop_login_check(str_contains($permissions, "'allowed_role_keys'=>['ADMIN']"), 'Store-device authority must be ADMIN-only.');
shop_login_check(str_contains($directory, "'stores.devices.manage'"), 'Administration directory must expose store-device authority.');
shop_login_check(str_contains($gateway, "'store_devices'=>['GET','POST']"), 'Drupal gateway store_devices route missing.');
shop_login_check(str_contains($gateway, 'merd_drupal_gateway_shop_context') && str_contains($gateway, "'context_shop'"), 'Signed Drupal Shop-context bridge missing.');
shop_login_check(str_contains($gateway, "d.status='active' AND s.status='active'"), 'Gateway must revalidate active POS/store state for Shop context.');
shop_login_check(str_contains($api, "case 'store_devices.php': beta_require_permission(\$user, 'stores.devices.manage'"), 'Store-device API permission enforcement missing.');

shop_login_check(str_contains($devices, 'merd_next_device_code'), 'Four-digit POS allocator missing.');
shop_login_check(str_contains($devices, "str_pad((string)\$i, 4, '0'"), 'POS IDs must be four digits.');
shop_login_check(str_contains($devices, 'SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES'), 'POS Ed25519 public-key validation missing.');
shop_login_check(str_contains($devices, 'attendance_device_keys'), 'POS key registration binding missing.');
shop_login_check(str_contains($devices, 'beta_admin_audit'), 'POS administration audit missing.');
shop_login_check(str_contains($devices, "SET status='revoked',revoked_at=UTC_TIMESTAMP()"), 'Inactivating a POS must revoke its registered key.');
shop_login_check(!str_contains($devices, "SET status='active',revoked_at=NULL"), 'Reactivating a POS must not silently restore a revoked key.');

shop_login_check(str_contains($workforce, 'Compact offline POS QR v2'), 'Compact Shop QR v2 verifier missing.');
shop_login_check(str_contains($workforce, "d.device_code=?"), 'Shop QR verifier must resolve four-digit POS ID.');
shop_login_check(str_contains($workforce, 'sodium_crypto_sign_verify_detached'), 'Shop QR signature verification missing.');
shop_login_check(str_contains($workforce, 'if ($age > 90)'), 'Shop QR freshness limit missing.');
shop_login_check(str_contains($scan, "\$attendanceRole = \$roleKey === 'USER'"), 'USER attendance versus management shop mode split missing.');
shop_login_check(str_contains($scan, "'shop_qr_uses'") || str_contains($scan, 'shop_qr_uses'), 'Management Shop QR replay protection missing.');
shop_login_check(str_contains($scan, "'attendance'=>false") && str_contains($scan, "'attendance'] = true"), 'Role-specific Shop scan response missing.');
shop_login_check(str_contains($scan, "'shop_logout_required'"), 'Management must explicitly log out before switching stores.');
shop_login_check(str_contains($api, 'function beta_shop_context'), 'Authoritative shop context resolver missing.');
shop_login_check(str_contains($state, "'finance_available' => \$canFinanceView && \$shopActive"), 'Shared state finance availability binding missing.');
shop_login_check(str_contains($finance, "throw new MerdWorkforceException('shop_login_required'"), 'Finance must require Shop login.');
shop_login_check(str_contains($finance, "'shop_store_required'"), 'Finance store must be constrained to Shop context when cross-store access is absent.');

if (is_string($deploy)) {
    shop_login_check(str_contains($deploy, 'validate_shop_login_devices_v1.php'), 'Shop-login validator is not wired into backend deployment.');
    shop_login_check(str_contains($deploy, 'apply_039_shop_login_devices.php'), 'Migration 039 is not wired into backend deployment.');
}

echo "MERDPOS Shop Login + POS devices v1 contract validated.\n";
