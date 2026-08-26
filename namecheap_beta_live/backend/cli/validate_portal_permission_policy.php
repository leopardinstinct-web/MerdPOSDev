<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$catalogPath = $root . '/backend/api/includes/portal_permissions.php';
$portalApiDir = $root . '/timesheet_portal/api';
$betaApiPath = $root . '/timesheet_portal/includes/beta_api.php';

if (!is_file($catalogPath) || !is_dir($portalApiDir) || !is_file($betaApiPath)) {
    fwrite(STDERR, "Permission policy validation failed: required beta source paths are missing.\n");
    exit(1);
}

require_once $catalogPath;

$errors = [];
$catalog = merd_portal_permission_catalog();
if (!$catalog) $errors[] = 'Permission catalogue is empty.';

foreach ($catalog as $key => $rule) {
    if (!is_string($key) || preg_match('/^[a-z][a-z0-9_.-]{1,119}$/', $key) !== 1) {
        $errors[] = "Invalid permission key: {$key}";
        continue;
    }
    if (!is_array($rule)) {
        $errors[] = "Permission {$key} has no rule object.";
        continue;
    }
    $label = trim((string)($rule['label'] ?? ''));
    $category = trim((string)($rule['category'] ?? ''));
    $level = (int)($rule['min_loa'] ?? 0);
    if ($label === '') $errors[] = "Permission {$key} has no label.";
    if ($category === '') $errors[] = "Permission {$key} has no category.";
    if ($level < 1 || $level > 1000) $errors[] = "Permission {$key} has invalid min_loa {$level}.";
    if (!empty($rule['dev_only']) && $level !== 1000) $errors[] = "DEV-only permission {$key} must default to LOA 1000.";
}

$widgetMap = merd_portal_dashboard_widget_permissions();
foreach ($widgetMap as $widget => $permissionPair) {
    if (!is_array($permissionPair) || count($permissionPair) !== 2) {
        $errors[] = "Dashboard widget {$widget} must declare visibility and data permissions.";
        continue;
    }
    foreach ($permissionPair as $permission) {
        if (!isset($catalog[(string)$permission])) $errors[] = "Dashboard widget {$widget} references unknown permission {$permission}.";
    }
}

$betaApiSource = (string)file_get_contents($betaApiPath);
$publicEndpoints = ['login.php', 'logout.php'];
$protectedCount = 0;
$routeCount = 0;

$files = glob($portalApiDir . '/*.php') ?: [];
sort($files, SORT_STRING);
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $publicEndpoints, true)) continue;
    $protectedCount++;
    $source = (string)file_get_contents($file);
    if (!str_contains($source, "beta_api.php")) {
        $errors[] = "Protected API {$name} does not include beta_api.php.";
    }
    if (!str_contains($source, 'beta_require_active_user(')) {
        $errors[] = "Protected API {$name} does not refresh live authorization with beta_require_active_user().";
    }
    if (!str_contains($betaApiSource, "case '{$name}'")) {
        $errors[] = "Protected API {$name} is not registered in beta_enforce_route_permission().";
    } else {
        $routeCount++;
    }

    // Any literal permission key referenced by an endpoint must exist in the
    // central catalogue. Dynamic variables are checked by runtime fail-closed logic.
    if (preg_match_all("/(?:beta_has_permission|beta_require_permission)\\([^\\n]*?'([a-z][a-z0-9_.-]+)'/", $source, $matches)) {
        foreach (array_unique($matches[1]) as $permission) {
            if (!isset($catalog[$permission])) $errors[] = "API {$name} references unknown permission {$permission}.";
        }
    }
}

// The route guard itself must fail closed rather than default-allow.
if (!str_contains($betaApiSource, 'permission_policy_missing')) {
    $errors[] = 'Global beta API guard does not contain the fail-closed permission_policy_missing path.';
}

if ($errors) {
    fwrite(STDERR, "MERDPOS beta permission policy validation FAILED:\n");
    foreach ($errors as $error) fwrite(STDERR, " - {$error}\n");
    exit(1);
}

$devOnly = count(array_filter($catalog, static fn(array $rule): bool => !empty($rule['dev_only'])));
echo 'MERDPOS beta permission policy validated; '
    . count($catalog) . " permissions, {$devOnly} DEV-only, "
    . count($widgetMap) . " dashboard widgets, {$protectedCount} protected APIs, {$routeCount} routes registered.\n";
