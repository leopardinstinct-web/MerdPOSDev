<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$apiPolicyPath = $root . '/timesheet_portal/includes/beta_api.php';
$statePath = $root . '/timesheet_portal/api/beta_state.php';
$managementPath = $root . '/timesheet_portal/assets/management.js';
$errors = [];

foreach ([$apiPolicyPath, $statePath, $managementPath] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Shared beta-state validation failed: required file missing: {$path}\n");
        exit(1);
    }
}

$apiPolicy = (string)file_get_contents($apiPolicyPath);
$state = (string)file_get_contents($statePath);
$management = (string)file_get_contents($managementPath);

foreach ([
    "'dashboard.view'",
    "'disputes.view_own'",
    "'disputes.review'",
    "'finance.view'",
    "'password.change_own'",
] as $permission) {
    $caseStart = strpos($apiPolicy, "case 'beta_state.php':");
    $caseEnd = $caseStart === false ? false : strpos($apiPolicy, "case 'dashboard_data.php':", $caseStart);
    $caseSource = ($caseStart !== false && $caseEnd !== false) ? substr($apiPolicy, $caseStart, $caseEnd - $caseStart) : '';
    if ($caseSource === '' || !str_contains($caseSource, 'beta_require_any_permission') || !str_contains($caseSource, $permission)) {
        $errors[] = "beta_state.php route must admit consuming feature permission {$permission}.";
    }
}

foreach ([
    '$canViewOwnTimesheets' => 'own-timesheet data gate',
    '$canViewOwnDisputes' => 'own-dispute data gate',
    '$canFinanceView' => 'finance data gate',
    '$canReadRecentShifts' => 'recent-shift data gate',
    '$canEnumerateStores' => 'store enumeration gate',
    '$disputes = []' => 'default-empty dispute payload',
    '$attendanceFlags = []' => 'default-empty attendance flag payload',
] as $needle => $label) {
    if (!str_contains($state, $needle)) {
        $errors[] = "beta_state.php is missing {$label}.";
    }
}

if (!str_contains($state, 'if ($canViewOwnDisputes || $canReviewDisputes)')) {
    $errors[] = 'Dispute records must not be loaded without an explicit dispute-view permission.';
}
if (!str_contains($state, 'if ($canViewWorkforce || $canViewOwnTimesheets || $canFinanceView)')) {
    $errors[] = 'Working-now data must be scoped to Workforce, own Timesheets or Finance consumers.';
}
if (str_contains($state, "'disputes' => merd_list_disputes(")) {
    $errors[] = 'Shared beta state must not unconditionally materialize dispute records.';
}
if (str_contains($state, "'recent_shifts' => \$shifts->fetchAll")) {
    $errors[] = 'Shared beta state must not unconditionally materialize recent shifts.';
}

foreach ([
    'applyManagementPermissionVisibility' => 'management card permission visibility',
    "can('workforce.view')" => 'Workforce dashboard gate',
    "can('finance.management_summary')" => 'Finance dashboard gate',
    "can('disputes.review')" => 'Dispute KPI gate',
    "can('system.sync_status')" => 'Sync KPI gate',
    "can('timesheets.view_own')||can('timesheets.view_all')" => 'Recent-attendance gate',
] as $needle => $label) {
    if (!str_contains($management, $needle)) {
        $errors[] = "management.js is missing {$label}.";
    }
}
if (!str_contains($management, "setCardVisible('storeFinanceChart',canFinanceData)")) {
    $errors[] = 'Finance management cards must be hidden when finance.management_summary is absent.';
}
if (!str_contains($management, "setCardVisible('workingNow',canWorkforceData)")) {
    $errors[] = 'Workforce management cards must be hidden when workforce.view is absent.';
}

if ($errors) {
    fwrite(STDERR, "MERDPOS shared beta-state permission validation FAILED:\n");
    foreach ($errors as $error) fwrite(STDERR, ' - ' . $error . "\n");
    exit(1);
}

echo "MERDPOS shared beta state validated: route access follows consuming features, returned data remains permission-scoped, and management cards mirror backend data permissions.\n";
