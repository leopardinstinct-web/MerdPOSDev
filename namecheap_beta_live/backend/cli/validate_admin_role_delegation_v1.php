<?php
declare(strict_types=1);

$packageRoot = dirname(__DIR__, 2);
$repoRoot = dirname($packageRoot);
$permission = file_get_contents($packageRoot . '/backend/api/includes/portal_permissions.php') ?: '';
$role = file_get_contents($packageRoot . '/timesheet_portal/api/role_authority.php') ?: '';
$directory = file_get_contents($packageRoot . '/timesheet_portal/api/admin_directory.php') ?: '';
$rolesJs = file_get_contents($packageRoot . '/timesheet_portal/assets/roles.js') ?: '';
$deployPath = $repoRoot . '/scripts/deploy_namecheap_beta.sh';
$deploy = is_file($deployPath) ? (file_get_contents($deployPath) ?: '') : null;
$errors = [];

$mustContain = [
    [$permission, "'roles.manage' => ['label'=>'Manage SUPER / USER application usability'", 'roles.manage must mean ADMIN usability'],
    [$permission, "'allowed_role_keys'=>['ADMIN']", 'roles.manage must be restricted to ADMIN'],
    [$permission, "'roles.define' => ['label'=>'Define ADMIN / SUPER / USER roles and authority ceilings'", 'roles.define capability'],
    [$permission, "'permissions.manage' => ['label'=>'Configure permission LOA thresholds'", 'DEV-only permission ceiling capability'],
    [$role, 'if ($action === \'save_usability\')', 'SUPER/USER usability action'],
    [$role, "beta_require_permission(\$actor, 'roles.define'", 'role mutation requires roles.define'],
    [$role, 'outside the DEV-defined ceiling', 'usability ceiling guard'],
    [$directory, "UPPER(role_key)<>'DEV'", 'DEV excluded from workforce role choices'],
    [$directory, "UPPER(TRIM(COALESCE(e.employee_type,'')))<>'DEV'", 'DEV excluded from workforce listing'],
    [$rolesJs, 'ADMIN controls SUPER / USER usability only', 'ADMIN usability UI'],
];
foreach ($mustContain as [$source,$needle,$label]) {
    if (!str_contains($source,$needle)) $errors[] = $label;
}
if (str_contains($permission, 'Create, edit and delete roles within own authority')) {
    $errors[] = 'obsolete ADMIN custom-role delegation remains';
}
if ($deploy !== null && !str_contains($deploy, 'apply_037_admin_role_delegation.php')) {
    $errors[] = '037 deploy migration wiring';
}
if ($errors) {
    fwrite(STDERR, "MERDPOS Admin usability validation FAILED:\n");
    foreach ($errors as $error) fwrite(STDERR, "- {$error}\n");
    exit(1);
}
echo "MERDPOS Admin usability validated: DEV defines role/permission ceilings; ADMIN only configures SUPER / USER usability within that ceiling.\n";
