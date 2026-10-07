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

// ---- assignable employees (the roster planner's picker) -------------------
// The planner needs the list of employees who may actually work at a store, and
// that list must obey the same eligibility rule as the write path. These checks
// fail closed: a renamed scope, a loosened permission, or a re-implemented rule
// is a failure, not a silent regression.
$endpointBody = $endpointSource;
if ($endpointBody !== '') {
    if (strpos($endpointBody, "if (\$scope === 'employees')") === false) {
        $failures[] = 'the roster endpoint does not expose the employees scope the planner needs';
    }
    if (strpos($endpointBody, 'roster_assignable_employees($pdo, $clientId, $storeId)') === false) {
        $failures[] = 'the roster endpoint does not build the assignable employee list for the store';
    }
    // The employees branch must require roster.manage AND re-check store scope
    // before any name is returned. The week anchor is the GET handler's own line:
    // roster_save_week also resolves a week, and anchoring on the bare assignment
    // matched that earlier occurrence instead.
    $employeesStart = strpos($endpointBody, "if (\$scope === 'employees')");
    $weekStartAt = strpos($endpointBody, "\$weekStart = roster_week_start(\$_GET['week_start']");
    if ($employeesStart !== false && $weekStartAt !== false && $weekStartAt > $employeesStart) {
        $employeesBranch = substr($endpointBody, $employeesStart, $weekStartAt - $employeesStart);
        if (strpos($employeesBranch, "beta_require_permission(\$sessionUser, 'roster.manage', \$pdo)") === false) {
            $failures[] = 'the roster employees scope must require roster.manage';
        }
        if (strpos($employeesBranch, 'roster_actor_scope($pdo, $sessionUser, $clientId, $storeId)') === false) {
            $failures[] = 'the roster employees scope must re-check store access before returning names';
        }
    }
    else {
        $failures[] = 'cannot locate the roster employees scope and the week resolution it must precede';
    }
    // The assignable list must reuse the shared eligibility rule, not restate it,
    // and must exclude inactive employees.
    $assignableStart = strpos($endpointBody, 'function roster_assignable_employees(');
    if ($assignableStart === false) {
        $failures[] = 'roster_assignable_employees is missing from the roster endpoint';
    }
    else {
        // Read to the NEXT function declaration (or the end of the file) rather
        // than a fixed character window: a magic length would start failing for a
        // longer body, and a false failure on a correct file is its own defect.
        // This boundary is newline- and indentation-sensitive on purpose: it is a
        // pinned structural check on one known file, not a general PHP parser.
        $assignableEnd = strpos($endpointBody, "\nfunction ", $assignableStart + 1);
        $assignableBranch = $assignableEnd === false
            ? substr($endpointBody, $assignableStart)
            : substr($endpointBody, $assignableStart, $assignableEnd - $assignableStart);
        if (strpos($assignableBranch, 'roster_store_access_allows(') === false) {
            $failures[] = 'the assignable employee list must decide store access through roster_store_access_allows';
        }
        // Presence of the call is not enough - it can be vacuous. The decision must
        // consume a REAL assignment fact, not a constant. A review found exactly this
        // defect in a shipped revision: roster_store_access_allows($mode, true) is
        // true for every mode, so the picker silently stopped filtering restricted
        // employees. Both checks below fail on that shape.
        if (strpos($assignableBranch, 'isset($allowed[$employeeId])') === false) {
            $failures[] = 'the assignable employee list must decide access from the employee mode AND the store assignment, not a constant';
        }
        if (preg_match('/roster_store_access_allows\([^;]*,\s*true\s*\)/', $assignableBranch) === 1) {
            $failures[] = 'the assignable employee list must not pass a constant true to the shared rule: it makes the rule vacuous for every employee';
        }
        if (strpos($assignableBranch, "!== 'active'") === false) {
            $failures[] = 'the assignable employee list must exclude non-active employees';
        }
    }
    // The shared rule itself, and the single-employee helper that must also use it,
    // so the picker and the writer cannot drift apart.
    if (strpos($endpointBody, 'function roster_store_access_allows(') === false) {
        $failures[] = 'the shared store-access rule roster_store_access_allows is missing';
    }
    $singleStart = strpos($endpointBody, 'function roster_employee_may_use_store(');
    if ($singleStart === false) {
        $failures[] = 'roster_employee_may_use_store is missing from the roster endpoint';
    }
    else {
        $singleEnd = strpos($endpointBody, "\nfunction ", $singleStart + 1);
        $singleBranch = $singleEnd === false
            ? substr($endpointBody, $singleStart)
            : substr($endpointBody, $singleStart, $singleEnd - $singleStart);
        if (strpos($singleBranch, 'roster_store_access_allows(') === false) {
            $failures[] = 'roster_employee_may_use_store must decide through the shared roster_store_access_allows rule';
        }
        // Presence of the function name is not enough: the restricted path must feed
        // the assignment result into the rule. Without this, replacing that call with
        // `return true` still satisfied the check above via the short-circuit call -
        // proven by a tamper control, and the reason the header states plainly that
        // these are structural checks, not proof.
        if (strpos($singleBranch, 'roster_store_access_allows($mode, (bool)') === false) {
            $failures[] = 'roster_employee_may_use_store must apply the shared rule to the fetched store assignment';
        }
    }
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
        //
        // Both positions are resolved from COMMAND lines, and a position that
        // cannot be found is ITSELF a failure. An earlier revision compared
        // first-byte offsets and skipped the whole check whenever either needle
        // was absent, so any cosmetic edit to the rsync line - requoting, a
        // variable rename, a dropped trailing slash - silently stopped enforcing
        // this invariant while the validator still reported success. A deploy gate
        // that fails open is not a gate.
        // Model the deploy script the way BASH read it, instead of approximating
        // it line by line. Bash removes backslash-newline pairs before parsing, so:
        //   - a continued command is ONE logical line, which puts the rsync verb and
        //     its portal source argument on the same line (no window state needed);
        //   - a COMMENT that ends in a backslash swallows the line after it, and a
        //     commented-out command is therefore not a command at all.
        // A logical line that starts with '#' is a comment and can satisfy neither
        // match, which is what makes the earlier false-pass classes impossible
        // rather than merely unlikely. Continuation requires an ODD number of
        // trailing backslashes: an escaped backslash (\\) does not continue, and a
        // backslash followed by a trailing space does not either.
        $logicalLines = [];
        $pending = '';
        foreach (preg_split('/\R/', $deploySource) as $physicalLine) {
            // Bash removes a backslash-newline pair WITHOUT inserting anything, so
            // the join adds no separator: the following line keeps its own leading
            // whitespace, which is what separates its tokens. Inserting a space here
            // would silently rewrite a token that a legal deploy script splits
            // across a continuation.
            $joined = $pending . $physicalLine;
            $trailingBackslashes = 0;
            for ($scan = strlen($physicalLine) - 1; $scan >= 0 && $physicalLine[$scan] === '\\'; $scan--) {
                $trailingBackslashes++;
            }
            if ($trailingBackslashes % 2 === 1) {
                $pending = substr($joined, 0, -1);
                continue;
            }
            $logicalLines[] = $joined;
            $pending = '';
        }
        if (trim($pending) !== '') {
            $logicalLines[] = $pending;
        }

        $portalRsyncLine = null;
        $gateLine = null;
        // Index is a LOGICAL line (continuations joined), not a physical line number
        // in the deploy script, and is used only to compare the two positions.
        foreach ($logicalLines as $logicalIndex => $logicalLine) {
            $command = trim($logicalLine);
            if ($command === '' || strpos($command, '#') === 0) {
                continue;
            }
            // The rsync SOURCE argument; the destination names only $LIVE. If the
            // portal tree is synced more than once, the LAST occurrence is the
            // refresh that matters, so keep overwriting.
            if (preg_match('/^rsync\b/', $command) === 1
                && strpos($command, '"$REPO/namecheap_beta_live/timesheet_portal/"') !== false) {
                $portalRsyncLine = $logicalIndex;
            }
            // The invocation itself, never a mention inside a comment.
            if ($gateLine === null && preg_match('/^php\s+\S*validate_roster_planning_v1\.php/', $command) === 1) {
                $gateLine = $logicalIndex;
            }
        }
        if ($portalRsyncLine === null) {
            $failures[] = 'cannot locate the timesheet_portal rsync in the canonical deploy script, so the roster gate ordering cannot be verified';
        }
        if ($gateLine === null) {
            $failures[] = 'cannot locate the roster gate invocation (php ... validate_roster_planning_v1.php) in the canonical deploy script';
        }
        if ($portalRsyncLine !== null && $gateLine !== null && $gateLine < $portalRsyncLine) {
            $failures[] = 'the deploy script runs the roster gate before the timesheet_portal rsync, so it would read a stale portal tree';
        }
    }
}

// ---- behavioural: the picker and the writer must agree ---------------------
// Structural checks cannot see a vacuous call. A review found exactly that class
// of defect in a shipped revision of this scope (a constant passed as the
// assignment flag made the eligibility rule true for every employee, so the
// picker stopped filtering), and the string checks above passed it. So the
// eligibility functions are now EXERCISED, against an in-memory SQLite fixture.
//
// The function bodies are extracted from roster.php rather than copied here: a
// copy would be a second implementation that could drift from the shipped source,
// which is the very failure mode under test. Extraction is asserted faithful.
if (!extension_loaded('pdo_sqlite')) {
    // Skipping is not silently equivalent to passing; say so on the record.
    fwrite(STDERR, "note: pdo_sqlite is unavailable; the behavioural eligibility check was skipped.\n");
}
elseif ($endpointSource !== '') {
    /**
     * Extract a top-level function's exact source text, using PHP's own tokenizer.
     *
     * Text-based boundaries were tried first and were wrong twice over: braces and
     * the word "function" occur inside strings and comments, so counting them either
     * truncated a body or - worse - rejected a valid function and blocked a deploy.
     * token_get_all() knows the difference, so brace depth here is tracked over real
     * punctuation tokens only and the declaration count ignores comments entirely.
     *
     * Returns null unless the function is declared exactly once with a complete body.
     */
    $extract = static function (string $source, string $name): ?string {
        $offset = 0;
        $occurrences = 0;
        $start = null;
        $end = null;
        $depth = 0;
        $functionAt = null;
        $expectName = false;
        foreach (token_get_all($source) as $token) {
            $id = is_array($token) ? $token[0] : null;
            $text = is_array($token) ? $token[1] : $token;
            if ($id === T_FUNCTION) {
                $functionAt = $offset;
                $expectName = true;
            }
            elseif ($expectName) {
                if ($id === T_STRING) {
                    $expectName = false;
                    if ($text === $name) {
                        $occurrences++;
                        if ($start === null) $start = $functionAt;
                    }
                }
                elseif ($id !== T_WHITESPACE) {
                    $expectName = false;
                }
            }
            if ($start !== null) {
                if ($text === '{') $depth++;
                elseif ($text === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $end = $offset + 1;
                        break;
                    }
                }
            }
            $offset += strlen($text);
        }
        if ($occurrences !== 1 || $start === null || $end === null) return null;
        return substr($source, $start, $end - $start);
    };
    $needed = ['roster_store_access_allows', 'roster_employee_may_use_store', 'roster_assignable_employees'];
    $extracted = [];
    foreach ($needed as $name) {
        $body = $extract($endpointSource, $name);
        if ($body === null) {
            // Comments are not counted as declarations, so this can only mean the
            // function is genuinely absent or declared more than once. Say that,
            // rather than sending the deployer looking for a missing function that
            // is present.
            $failures[] = "cannot extract {$name} from the roster endpoint for behavioural testing: expected exactly one declaration with a complete body";
        }
        else {
            $extracted[] = $body;
        }
    }
    if (count($extracted) === count($needed)) {
        // tempnam(), not a predictable name: this file is written and then REQUIRED
        // as the deploying user. tempnam() creates it with an unpredictable name and
        // 0600, and the write goes through the handle it created. An is_link() gate
        // before a separate write would be check-then-use theatre, not protection.
        $harness = tempnam(sys_get_temp_dir(), 'merd_roster_eligibility_');
        $handle = $harness === false ? false : @fopen($harness, 'wb');
        if ($harness === false || $handle === false) {
            $failures[] = 'cannot create a temporary harness file for the behavioural eligibility check';
            if (is_string($harness)) @unlink($harness);
        }
        elseif (fwrite($handle, "<?php\n" . implode("\n\n", $extracted) . "\n") === false) {
            fclose($handle);
            $failures[] = 'cannot write the behavioural eligibility harness';
            @unlink($harness);
        }
        else {
            fclose($handle);
            try {
                    // Inside the try: an unparseable extraction must be REPORTED as a
                    // contract failure, not kill the validator with a fatal error
                    // before it prints what went wrong.
                    require $harness;
                    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('CREATE TABLE employees (client_id INTEGER, id INTEGER, full_name TEXT, user_id INTEGER, status TEXT)');
            $pdo->exec('CREATE TABLE employee_store_access (client_id INTEGER, employee_id INTEGER, access_mode TEXT)');
            $pdo->exec('CREATE TABLE employee_store_assignments (client_id INTEGER, employee_id INTEGER, store_id INTEGER)');
            // 1: no access row at all (all stores)   2: explicit "all"
            // 3: selected WITH an assignment to 7    4: selected WITHOUT one
            // 5: inactive                            6: selected, assigned to another store
            $pdo->exec("INSERT INTO employees VALUES
                (1,1,'No Access Row',NULL,'active'),(1,2,'Mode All',NULL,'active'),
                (1,3,'Selected With',NULL,'active'),(1,4,'Selected Without',NULL,'active'),
                (1,5,'Inactive',NULL,'inactive'),(1,6,'Selected Other Store',NULL,'active')");
            $pdo->exec("INSERT INTO employee_store_access VALUES
                (1,2,'all'),(1,3,'selected'),(1,4,'selected'),(1,5,'all'),(1,6,'selected')");
            $pdo->exec('INSERT INTO employee_store_assignments VALUES (1,3,7),(1,6,9)');

            $assignable = roster_assignable_employees($pdo, 1, 7);
            $assignableIds = array_map(static fn(array $row): int => (int)$row['id'], $assignable);
            sort($assignableIds);
            if ($assignableIds !== [1, 2, 3]) {
                $failures[] = 'the assignable list for store 7 must be exactly the all-store employees plus the one assigned to it; got [' . implode(',', $assignableIds) . ']';
            }
            // The property that matters: for every ACTIVE employee, the picker's
            // answer and the write path's store-access answer must be identical.
            // Inactive employees are excluded from the picker by a separate rule
            // (you cannot roster someone who has left), so they are checked
            // separately below rather than folded into this comparison.
            foreach ([1, 2, 3, 4, 6] as $employeeId) {
                $inPicker = in_array($employeeId, $assignableIds, true);
                $writePath = roster_employee_may_use_store($pdo, 1, $employeeId, 7);
                if ($inPicker !== $writePath) {
                    $failures[] = "picker and write path disagree about employee {$employeeId} for store 7: picker="
                        . ($inPicker ? 'yes' : 'no') . ' write=' . ($writePath ? 'yes' : 'no');
                }
            }
            // Direct checks, so a symmetric bug in both paths is still caught.
            if (roster_employee_may_use_store($pdo, 1, 4, 7)) {
                $failures[] = 'a selected-mode employee with no assignment must not be allowed at that store';
            }
            if (roster_employee_may_use_store($pdo, 1, 6, 7)) {
                $failures[] = 'a selected-mode employee assigned to another store must not be allowed at store 7';
            }
            if (in_array(5, $assignableIds, true)) {
                $failures[] = 'an inactive employee must never be assignable';
            }
        }
                catch (Throwable $error) {
                    $failures[] = 'behavioural eligibility check failed to run: ' . $error->getMessage();
                }
                @unlink($harness);
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
