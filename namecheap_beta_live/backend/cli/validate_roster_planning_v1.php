<?php
declare(strict_types=1);

/**
 * Roster planning contract (MERD-20261004-roster-planning-v1).
 *
 * Fail-closed structural checks for the plan side of attendance. Rostering adds
 * schema, a protected capability and three permissions, so the properties that
 * must never silently regress are:
 *
 *   - the migration and its apply script exist and are idempotent;
 *   - the schema keeps its foreign keys, unique keys and the ends_next_day flag
 *     that makes a cross-midnight shift representable;
 *   - the permission catalogue exposes the roster keys with the agreed ceiling;
 *   - the endpoint enforces its permission, CSRF and store scoping, and derives
 *     the client from the session rather than from request input;
 *   - the Drupal gateway may reach the capability, and the deploy runs both the
 *     migration and this validator.
 *
 * Run: php namecheap_beta_live/backend/cli/validate_roster_planning_v1.php
 */

// Paths are derived from the layout this script actually meets, because it runs
// in two of them:
//   repository checkout : <repo>/namecheap_beta_live/backend/... and <repo>/scripts/...
//   deployed live tree  : <live>/backend/... and <live>/timesheet_portal/...
// The live tree carries no namecheap_beta_live/ prefix and no top-level scripts/,
// so assuming the repository shape here aborts every canonical deploy - which is
// exactly what an earlier revision of this file did.
$backend = dirname(__DIR__);      // .../backend
$treeRoot = dirname($backend);    // repository: .../namecheap_beta_live | server: .../app/beta
$failures = [];

$migration = $backend . '/sql/040_roster_planning.sql';
$apply = $backend . '/cli/apply_040_roster_planning.php';
$catalogue = $backend . '/api/includes/portal_permissions.php';
$gateway = $backend . '/api/integrations/portal_gateway.php';
$endpoint = $treeRoot . '/timesheet_portal/api/roster.php';
$betaApi = $treeRoot . '/timesheet_portal/includes/beta_api.php';

// The deploy script is not part of the deployed tree. Look for it where a
// repository checkout keeps it; when only the live tree is present the wiring
// assertions are skipped with a printed note, and CI still enforces them.
$deployCandidates = array_values(array_filter([
    getenv('MERDPOS_DEPLOY_SCRIPT') ?: null,
    dirname($treeRoot) . '/scripts/deploy_namecheap_beta.sh',
    dirname(dirname($treeRoot)) . '/scripts/deploy_namecheap_beta.sh',
]));
$deploy = '';
foreach ($deployCandidates as $candidate) {
    if (is_file($candidate)) { $deploy = $candidate; break; }
}

$read = static function (string $path, array &$failures): string {
    if (!is_file($path)) {
        $failures[] = "missing required file: {$path}";
        return '';
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        $failures[] = "unreadable file: {$path}";
        return '';
    }
    return $contents;
};

// ---- migration ------------------------------------------------------------
$sql = $read($migration, $failures);
if ($sql !== '') {
    foreach (['roster_weeks', 'roster_shifts', 'roster_assignments'] as $table) {
        if (preg_match('/CREATE TABLE IF NOT EXISTS\s+' . preg_quote($table, '/') . '\s*\(/i', $sql) !== 1) {
            $failures[] = "040 migration does not create {$table} idempotently";
        }
    }
    if (preg_match('/\bDROP\s+TABLE\b/i', $sql) === 1) {
        $failures[] = '040 migration contains DROP TABLE; migrations must not be destructive';
    }
    foreach (['clients(id)', 'stores(id)', 'employees(id)'] as $reference) {
        if (strpos($sql, "REFERENCES {$reference}") === false) {
            $failures[] = "040 migration is missing a foreign key to {$reference}";
        }
    }
    if (strpos($sql, 'ends_next_day') === false) {
        $failures[] = '040 migration is missing roster_shifts.ends_next_day (cross-midnight shifts)';
    }
    foreach (['uq_roster_weeks_store_week', 'uq_roster_shifts_slot', 'uq_roster_assignments_shift_employee'] as $key) {
        if (strpos($sql, $key) === false) {
            $failures[] = "040 migration is missing unique key {$key}";
        }
    }
    if (strpos($sql, 'ON DELETE CASCADE') === false) {
        $failures[] = '040 migration should cascade a shift/week removal to its children';
    }
}

$applySource = $read($apply, $failures);
if ($applySource !== '' && strpos($applySource, '040_roster_planning.sql') === false) {
    $failures[] = 'apply_040 does not read the 040 migration SQL';
}

// ---- permissions ----------------------------------------------------------
$catalogueSource = $read($catalogue, $failures);
$expectedPermissions = [
    'roster.view_own' => ['min_loa' => 1],
    'roster.view' => ['min_loa' => 50],
    'roster.manage' => ['min_loa' => 50],
];
if ($catalogueSource !== '') {
    foreach ($expectedPermissions as $key => $expectation) {
        $pattern = "/'" . preg_quote($key, '/') . "'\s*=>\s*\[([^\]]*)\]/";
        if (preg_match($pattern, $catalogueSource, $match) !== 1) {
            $failures[] = "permission {$key} is not declared in the portal catalogue";
            continue;
        }
        $declaration = $match[1];
        if (preg_match("/'min_loa'\s*=>\s*(\d+)/", $declaration, $loa) !== 1) {
            $failures[] = "permission {$key} does not declare min_loa";
        } elseif ((int)$loa[1] !== $expectation['min_loa']) {
            $failures[] = "permission {$key} min_loa is {$loa[1]}, expected {$expectation['min_loa']}";
        }
        if (strpos($declaration, "'category'=>'Roster'") === false) {
            $failures[] = "permission {$key} is not grouped under the Roster category";
        }
        if (preg_match("/'dev_only'\s*=>\s*true/", $declaration) === 1) {
            $failures[] = "permission {$key} must not be DEV-only; rostering is delegable by client LOA";
        }
    }
}

// ---- endpoint -------------------------------------------------------------
$endpointSource = $read($endpoint, $failures);
if ($endpointSource !== '') {
    if (strpos($endpointSource, "beta_require_permission(\$sessionUser, 'roster.manage', \$pdo)") === false) {
        $failures[] = 'roster endpoint does not require roster.manage for writes';
    }
    if (strpos($endpointSource, "'roster.view'") === false || strpos($endpointSource, "'roster.view_own'") === false) {
        $failures[] = 'roster endpoint does not require the roster read permissions';
    }
    if (strpos($endpointSource, 'require_csrf(') === false) {
        $failures[] = 'roster endpoint does not require CSRF on writes';
    }
    if (strpos($endpointSource, 'beta_require_active_user()') === false) {
        $failures[] = 'roster endpoint does not require an authenticated user';
    }
    if (strpos($endpointSource, 'roster_employee_may_use_store') === false) {
        $failures[] = 'roster endpoint does not enforce employee store access';
    }
    if (strpos($endpointSource, 'roster_actor_scope') === false) {
        $failures[] = 'roster endpoint does not enforce actor store scope';
    }
    if (strpos($endpointSource, 'beta_admin_audit(') === false) {
        $failures[] = 'roster writes are not audited';
    }
    // The client must come from the session, never from request input.
    if (preg_match('/client_id\s*=\s*\(int\)\s*\(?\s*\$(input|_GET|_POST|_REQUEST)\b/', $endpointSource) === 1) {
        $failures[] = 'roster endpoint derives client_id from request input; it must come from the session';
    }
    if (strpos($endpointSource, 'beta_actor_employee_id') === false) {
        $failures[] = 'roster endpoint does not resolve the acting employee for personal shifts';
    }
    foreach (['roster_week_start', 'roster_time'] as $validator) {
        if (strpos($endpointSource, $validator) === false) {
            $failures[] = "roster endpoint is missing the {$validator} input guard";
        }
    }

    // Guards added in response to independent review, kept from regressing.
    if (strpos($endpointSource, 'FILTER_VALIDATE_BOOLEAN') === false) {
        $failures[] = 'roster endpoint parses ends_next_day loosely; a truthy string would mark a shift as crossing midnight';
    }
    if (strpos($endpointSource, '$endsNextDay === 1 && $end > $start') === false) {
        $failures[] = 'roster endpoint accepts a next-day flag on a shift that ends later than it starts';
    }
    if (strpos($endpointSource, 'DELETE a FROM roster_assignments') === false) {
        $failures[] = 'roster endpoint relies on the foreign-key cascade to clear assignments';
    }
    if (strpos($endpointSource, "'store_inactive'") === false) {
        $failures[] = 'roster endpoint does not refuse roster planning for an inactive store';
    }
    // Authorization must be evaluated before business state, so an actor without
    // store access cannot learn whether the store is active.
    $forbiddenAt = strpos($endpointSource, "'store_forbidden'");
    $inactiveAt = strpos($endpointSource, "'store_inactive'");
    if ($forbiddenAt !== false && $inactiveAt !== false && $forbiddenAt > $inactiveAt) {
        $failures[] = 'roster endpoint reports store status before checking store access';
    }
    if (strpos($endpointSource, 'MERDPOS_AUDIT_FAILURE') === false
        || strpos($endpointSource, "'audit' => \$audit") === false) {
        $failures[] = 'roster endpoint swallows audit failures instead of reporting them operationally';
    }
}

// The route layer must treat every non-GET verb as a write.
$apiSource = $read($betaApi, $failures);
if ($apiSource !== ''
    && preg_match('/case \'roster\.php\':(.{0,400}?)\$method === \'GET\'/s', $apiSource) !== 1) {
    $failures[] = 'the route layer for roster.php must require roster.manage for every non-GET verb';
}

// ---- gateway + deploy wiring ---------------------------------------------
$gatewaySource = $read($gateway, $failures);
if ($gatewaySource !== '' && preg_match("#'roster'\s*=>\s*\[\s*'GET'\s*,\s*'POST'\s*\]#", $gatewaySource) !== 1) {
    $failures[] = "the Drupal gateway does not expose 'roster' as GET/POST";
}

$deployWiringChecked = false;
if ($deploy === '') {
    echo "note: canonical deploy script is not part of this layout; deploy wiring is asserted by CI.\n";
} else {
    $deploySource = $read($deploy, $failures);
    if ($deploySource !== '') {
        $deployWiringChecked = true;
        if (strpos($deploySource, 'apply_040_roster_planning.php') === false) {
            $failures[] = 'the canonical deploy script does not apply migration 040';
        }
        if (strpos($deploySource, 'validate_roster_planning_v1.php') === false) {
            $failures[] = 'the canonical deploy script does not gate on validate_roster_planning_v1.php';
        }
        // This contract spans both trees, so the gate must be invoked after the
        // timesheet_portal rsync. Invoked earlier it reads a stale portal tree,
        // fails, and under set -e blocks every deploy.
        $portalRsyncAt = strpos($deploySource, '"$REPO/namecheap_beta_live/timesheet_portal/"');
        $gateAt = strpos($deploySource, 'validate_roster_planning_v1.php');
        if ($portalRsyncAt !== false && $gateAt !== false && $gateAt < $portalRsyncAt) {
            $failures[] = 'the deploy script runs the roster gate before the timesheet_portal rsync, so it would read a stale portal tree';
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Roster planning contract failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}

echo 'Roster planning contract validated: migration 040, roster permissions, store-scoped endpoint and gateway capability'
    . ($deployWiringChecked ? ', deploy wiring' : ' (deploy wiring asserted in CI)') . ".\n";
exit(0);
