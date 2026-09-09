<?php
declare(strict_types=1);

$packageRoot = dirname(__DIR__, 2);
$repoRoot = dirname($packageRoot);
$files = [
    'login' => $packageRoot . '/timesheet_portal/api/login.php',
    'password' => $packageRoot . '/timesheet_portal/api/change_password.php',
    'context' => $packageRoot . '/timesheet_portal/api/client_context.php',
    'service' => $packageRoot . '/backend/api/includes/service_actor.php',
    'platform' => $packageRoot . '/backend/api/includes/platform_identity.php',
    'directory' => $packageRoot . '/timesheet_portal/api/admin_directory.php',
    'roles' => $packageRoot . '/timesheet_portal/api/role_authority.php',
    'migration' => $packageRoot . '/backend/sql/038_platform_dev_identity.sql',
    'apply' => $packageRoot . '/backend/cli/apply_038_platform_dev_identity.php',
    'finalize' => $packageRoot . '/backend/cli/finalize_038_platform_dev_identity.php',
];
$src = [];
$errors = [];
foreach ($files as $key=>$path) {
    if (!is_file($path)) { $errors[] = "missing {$key} source"; $src[$key]=''; continue; }
    $src[$key] = file_get_contents($path) ?: '';
}
$checks = [
    ['login','merd_platform_identity_by_user_id($pdo, $userId)','platform namespace login priority'],
    ['login',"UPPER(TRIM(employee_type))<>'DEV'",'client employee login excludes DEV'],
    ['login',"'identity_scope'=>'platform'",'platform-scoped session'],
    ['login',"'auth_client_id'=>0",'platform DEV has no auth client'],
    ['password','UPDATE platform_identities SET login_password=?,pin_code=?','platform password storage'],
    ['context','merd_platform_identity_set_selected_client','platform Working Client preference'],
    ['service',"'identity_scope'=>'platform'",'signed service platform actor'],
    ['service',"UPPER(TRIM(e.employee_type))<>'DEV'",'service employee fallback excludes DEV'],
    ['platform','platform_identity_preferences','separate platform preference table'],
    ['directory',"UPPER(role_key)<>'DEV'",'DEV non-assignable in Workforce'],
    ['roles',"platform_dev_ceiling_admin_usability_v1",'authority model marker'],
    ['roles','if ($action === \'save_usability\')','ADMIN usability endpoint'],
    ['migration','CREATE TABLE IF NOT EXISTS platform_identities','platform identity table'],
    ['migration','CREATE TABLE IF NOT EXISTS client_role_usability','role usability table'],
    ['apply','Not every active DEV employee was staged as a platform identity.','safe staging guard'],
    ['finalize','DEV cutover blocked: a legacy DEV row has no usable platform identity and Working Client.','safe finalization guard'],
    ['finalize',"UPDATE employees SET status='inactive' WHERE status='active' AND UPPER(TRIM(employee_type))='DEV'",'legacy DEV retirement'],
];
foreach ($checks as [$file,$needle,$label]) {
    if (!str_contains($src[$file] ?? '',$needle)) $errors[] = $label;
}
$deployPath = $repoRoot . '/scripts/deploy_namecheap_beta.sh';
if (is_file($deployPath)) {
    $deploy = file_get_contents($deployPath) ?: '';
    if (!str_contains($deploy, 'apply_038_platform_dev_identity.php')) $errors[] = '038 deploy staging wiring';
    if (!str_contains($deploy, 'validate_platform_dev_identity_v1.php')) $errors[] = 'platform identity validator deploy gate';
    if (str_contains($deploy, 'finalize_038_platform_dev_identity.php')) $errors[] = 'dangerous automatic DEV finalization in deploy';
}
if (!str_contains($src['migration'] ?? '', 'actor_platform_identity_id')) $errors[] = 'UI Studio platform actor column';
if (!str_contains($src['migration'] ?? '', 'updated_by_platform_identity_id')) $errors[] = 'platform actor update columns';

if ($errors) {
    fwrite(STDERR, "MERDPOS Platform DEV Identity validation FAILED:\n");
    foreach ($errors as $error) fwrite(STDERR, "- {$error}\n");
    exit(1);
}
echo "MERDPOS Platform DEV Identity v1 validated: DEV is platform-scoped; Working Client is context; ADMIN usability is bounded by DEV authority.\n";
