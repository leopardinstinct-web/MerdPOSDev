<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$catalogPath = $root . '/backend/api/includes/portal_permissions.php';
$portalApiDir = $root . '/timesheet_portal/api';
$betaApiPath = $root . '/timesheet_portal/includes/beta_api.php';
$portalAppPath = $root . '/timesheet_portal/assets/app.js';
$timesheetAppPath = $root . '/timesheet_portal/assets/timesheet-app.js';
$betaRuntimePath = $root . '/timesheet_portal/assets/beta.js';
$dashboardPath = $root . '/timesheet_portal/dashboard.php';
$htaccessPath = $root . '/timesheet_portal/.htaccess';

$requiredPaths = [
    $catalogPath,
    $portalApiDir,
    $betaApiPath,
    $portalAppPath,
    $timesheetAppPath,
    $betaRuntimePath,
    $dashboardPath,
    $htaccessPath,
];
foreach ($requiredPaths as $path) {
    if (!file_exists($path)) {
        fwrite(STDERR, "Permission policy validation failed: required beta source path is missing: {$path}\n");
        exit(1);
    }
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

// Permission-aware dashboard rendering intentionally omits controls that the
// current role cannot use. beta.js still contains legacy direct ID lookups, so
// app.js must install inert compatibility nodes before beta.js executes. This
// contract prevents a future LOA/permission cleanup from reintroducing the
// whole-page runtime crashes fixed on 2026-08-26.
$portalAppSource = (string)file_get_contents($portalAppPath);
$timesheetAppSource = (string)file_get_contents($timesheetAppPath);
$betaRuntimeSource = (string)file_get_contents($betaRuntimePath);
$dashboardSource = (string)file_get_contents($dashboardPath);
$htaccessSource = (string)file_get_contents($htaccessPath);

$compatIds = [
    'workingNow',
    'recentShifts',
    'disputeList',
    'financialDate',
    'financialStore',
    'refreshFinancial',
    'cashAccount',
    'cashAvailable',
    'financialSummary',
    'financialEntries',
    'cashMovementForm',
    'closingForm',
    'financialQueue',
    'financialStatus',
    'refreshBetaBtn',
    'passwordBtn',
    'passwordClose',
    'passwordForm',
    'passwordStatus',
    'passwordDialog',
];
foreach ($compatIds as $id) {
    $legacyLookup = '$' . "('{$id}')";
    if (str_contains($betaRuntimeSource, $legacyLookup) && !str_contains($portalAppSource, "'{$id}'")) {
        $errors[] = "Legacy beta.js directly looks up permission-gated #{$id}, but app.js does not provide its compatibility shim.";
    }
}

if (!str_contains($portalAppSource, 'const betaCompatIds = [')) {
    $errors[] = 'Portal app.js no longer declares the permission-runtime compatibility shim set.';
}
if (!str_contains($portalAppSource, 'hasTimesheetDom')) {
    $errors[] = 'Portal app.js no longer gates the Timesheet runtime on the Timesheet DOM contract.';
}
if (!str_contains($portalAppSource, 'assets/timesheet-app.js')) {
    $errors[] = 'Portal app.js no longer wires the isolated Timesheet runtime.';
}
if (!str_contains($timesheetAppSource, 'window.__timesheetPortalLoaded')) {
    $errors[] = 'Timesheet runtime is missing its double-load guard.';
}
if (!str_contains($timesheetAppSource, "api/weeks.php") || !str_contains($timesheetAppSource, "api/timesheet.php")) {
    $errors[] = 'Timesheet runtime no longer contains the expected weeks/report API wiring.';
}

$appScriptPosition = strpos($dashboardSource, 'assets/app.js');
$betaScriptPosition = strpos($dashboardSource, 'assets/beta.js');
if ($appScriptPosition === false || $betaScriptPosition === false || $appScriptPosition >= $betaScriptPosition) {
    $errors[] = 'dashboard.php must load app.js before beta.js so permission compatibility shims exist before legacy bindings run.';
}
if (!str_contains($htaccessSource, 'app\\.js') || !str_contains($htaccessSource, 'timesheet-app\\.js')) {
    $errors[] = 'Portal cache revalidation must include app.js and timesheet-app.js after the permission-runtime split.';
}

if ($errors) {
    fwrite(STDERR, "MERDPOS beta permission policy validation FAILED:\n");
    foreach ($errors as $error) fwrite(STDERR, " - {$error}\n");
    exit(1);
}

$devOnly = count(array_filter($catalog, static fn(array $rule): bool => !empty($rule['dev_only'])));
echo 'MERDPOS beta permission policy validated; '
    . count($catalog) . " permissions, {$devOnly} DEV-only, "
    . count($widgetMap) . " dashboard widgets, {$protectedCount} protected APIs, {$routeCount} routes registered; "
    . "permission-gated DOM compatibility and Timesheet runtime split verified.\n";
