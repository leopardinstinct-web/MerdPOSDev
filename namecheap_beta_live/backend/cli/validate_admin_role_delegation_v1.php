<?php
declare(strict_types=1);

$packageRoot = dirname(__DIR__, 2);
$repoRoot = dirname($packageRoot);
$permissionPath = $packageRoot . '/backend/api/includes/portal_permissions.php';
$rolePath = $packageRoot . '/timesheet_portal/api/role_authority.php';
$deployPath = $repoRoot . '/scripts/deploy_namecheap_beta.sh';
$permission = file_get_contents($permissionPath) ?: '';
$role = file_get_contents($rolePath) ?: '';
$deploy = is_file($deployPath) ? (file_get_contents($deployPath) ?: '') : null;
$errors = [];

$mustContain = [
    [$permission, "'roles.manage' => ['label'=>'Create, edit and delete roles within own authority','category'=>'System','min_loa'=>50,'dev_only'=>false", 'roles.manage must be delegable at LOA 50'],
    [$permission, "'permissions.manage' => ['label'=>'Configure permission LOA thresholds','category'=>'System','min_loa'=>1000,'dev_only'=>true", 'permission threshold editing must remain DEV-only'],
    [$role, "Only DEV can change system role authority.", 'non-DEV system-role guard'],
    [$role, "You cannot create a role above your own authority level.", 'custom-role create escalation guard'],
    [$role, "You cannot raise a role above your own authority level.", 'custom-role edit escalation guard'],
    [$role, "'can_manage_permissions' => beta_has_permission", 'role-state permission management capability'],
];
foreach ($mustContain as [$source,$needle,$label]) if (!str_contains($source,$needle)) $errors[] = $label;
if ($deploy !== null && !str_contains($deploy, 'apply_037_admin_role_delegation.php')) $errors[] = 'deploy migration wiring';

if ($errors) {
    fwrite(STDERR, "MERDPOS Admin role delegation validation FAILED:\n");
    foreach ($errors as $error) fwrite(STDERR, "- {$error}\n");
    exit(1);
}
echo "MERDPOS Admin role delegation validated: ADMIN may manage custom roles only within own authority; system roles and permission thresholds remain DEV-governed.\n";
