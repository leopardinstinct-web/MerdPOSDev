<?php
declare(strict_types=1);

function store_write_check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

$root = dirname(__DIR__, 2);
$path = $root . '/timesheet_portal/api/admin_directory.php';
$src = is_file($path) ? (string) file_get_contents($path) : '';
store_write_check($src !== '', 'admin_directory.php missing.');
store_write_check(str_contains($src, 'updated_by_employee_id,updated_by_platform_identity_id'), 'Store schedule actor columns are incomplete.');
store_write_check(str_contains($src, 'VALUES (?,?,?,?,?,?,?,?)'), 'Store schedule placeholder count is not eight.');
store_write_check(str_contains($src, 'beta_actor_employee_id($actor),beta_actor_platform_identity_id($actor)'), 'Store schedule actor values are incomplete.');
store_write_check(str_contains($src, "beta_has_permission(\$actor, 'stores.profile.manage', \$pdo)"), 'DEV-only Store profile boundary missing.');
store_write_check(str_contains($src, 'directory_store_maps_url('), 'Google Maps validation missing from authoritative Store write.');
store_write_check(str_contains($src, "duplicate_store_code"), 'Store Code uniqueness guard missing.');

$start = strpos($src, "if (\$action === 'save_store') {");
$end = $start === false ? false : strpos($src, "json_response(['success'=>false", $start);
store_write_check($start !== false && $end !== false, 'Store save block not found.');
$block = substr($src, $start, $end - $start);
$begin = strpos($block, '$pdo->beginTransaction();');
$schedule = strpos($block, 'directory_save_store_schedule(');
$audit = strpos($block, 'beta_admin_audit(');
$commit = strpos($block, '$pdo->commit();');
store_write_check($begin !== false && $schedule !== false && $audit !== false && $commit !== false, 'Atomic Store transaction markers missing.');
store_write_check($begin < $schedule && $schedule < $audit && $audit < $commit, 'Store schedule, audit and commit ordering is not atomic.');
store_write_check(!str_contains($block, 'directory_audit($pdo,$actor,$auditAction'), 'Store audit must not be best-effort after commit.');
store_write_check(str_contains($block, '$profileStmt = $pdo->prepare'), 'Non-profile actors do not preserve existing DEV-only Store profile values.');
store_write_check(str_contains($block, "directory_store_profile_input(['store_name'=>\$name]"), 'Non-profile Store creation does not use governed generated profile defaults.');

echo "MERDPOS atomic Store write validated: profile boundary, 8-value platform-aware schedule, audit-before-commit.\n";
