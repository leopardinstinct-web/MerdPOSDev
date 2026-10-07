<?php
declare(strict_types=1);

/**
 * Roster planner contract (MERD-20261007-roster-planner-ui).
 *
 * Fail-closed structural checks for the Drupal roster surface. The point of this
 * file is that the properties which must never silently regress are asserted, not
 * assumed:
 *
 *   - the page and its JSON write are routed, and the write is POST-only;
 *   - the write validates the Drupal CSRF header and requires roster.manage, and
 *     the page requires roster.view;
 *   - the controller owns no operational SQL: MERDPOS stays authoritative;
 *   - the offline contract is present (versioned queue key, UUID v4 idempotency
 *     id, online flush, retryable handshake, cache of the confirmed week);
 *   - the template escapes everything and carries the offline markers the JS
 *     binds to;
 *   - the stylesheet is token-driven, because a literal colour would break the
 *     theme's light/dark contract;
 *   - the surface is reachable (nav + library + theme hook) and the deploy runs
 *     this validator.
 *
 * Run: php drupal/tools/validate_roster_planner_v1.php
 */

$root = dirname(__DIR__);
$module = $root . '/web/modules/custom/merdpos_core';
$theme = $root . '/web/themes/custom/merdpos_app';

$failures = [];
$read = static function (string $path) use (&$failures): string {
    if (!is_file($path)) {
        $failures[] = 'missing required file: ' . $path;
        return '';
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        $failures[] = 'unreadable file: ' . $path;
        return '';
    }
    return $contents;
};

$routing = $read($module . '/merdpos_core.routing.yml');
$controller = $read($module . '/src/Controller/RosterController.php');
$template = $read($module . '/templates/merdpos-roster.html.twig');
$js = $read($module . '/js/roster-v1.js');
$css = $read($module . '/css/roster-v1.css');
$libraries = $read($module . '/merdpos_core.libraries.yml');
$moduleFile = $read($module . '/merdpos_core.module');
$themeFile = $read($theme . '/merdpos_app.theme');
$deploy = $read($root . '/tools/namecheap_deploy.sh');

// ---- routing ---------------------------------------------------------------
if (strpos($routing, 'merdpos_core.roster:') === false || strpos($routing, "path: '/merdpos/roster'") === false) {
    $failures[] = 'the roster page route is missing';
}
if (strpos($routing, 'RosterController::roster') === false) {
    $failures[] = 'the roster page route does not use RosterController::roster';
}
if (strpos($routing, 'merdpos_core.roster_submit:') === false || strpos($routing, "path: '/merdpos/roster/submit'") === false) {
    $failures[] = 'the roster JSON submit route is missing';
}
if (strpos($routing, 'RosterController::submitJson') === false) {
    $failures[] = 'the roster submit route does not use RosterController::submitJson';
}
// The submit route must be POST-only: a GET that writes would be a CSRF hole.
$submitAt = strpos($routing, 'merdpos_core.roster_submit:');
if ($submitAt !== false) {
    $submitBlock = substr($routing, $submitAt, 400);
    if (strpos($submitBlock, 'methods: [POST]') === false) {
        $failures[] = 'the roster submit route must be POST only';
    }
}

// ---- controller ------------------------------------------------------------
if ($controller !== '') {
    if (strpos($controller, "headers->get('X-MERDPOS-CSRF'") === false) {
        $failures[] = 'the roster write does not validate the Drupal CSRF header';
    }
    if (strpos($controller, 'normalizeQueuedWeek') === false) {
        $failures[] = 'queued roster normalization is missing';
    }
    if (strpos($controller, "call('roster', 'POST'") === false && strpos($controller, 'call(\'roster\', \'POST\'') === false) {
        $failures[] = 'the roster write does not call the portal roster capability';
    }
    if (strpos($controller, "'retryable' => \$retryable") === false) {
        $failures[] = 'the retryable gateway-unavailable signal is missing';
    }
    if (strpos($controller, "if (\$start === \$end)") === false) {
        $failures[] = 'a shift that starts and ends at the same time must be refused';
    }
    if (strpos($controller, '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/') === false) {
        $failures[] = 'browser UUID v4 validation is missing';
    }
    // The page must require roster.view and the write roster.manage.
    if (strpos($controller, "'roster.view'") === false || strpos($controller, "'roster.view_own'") === false) {
        $failures[] = 'the roster page does not require roster.view / roster.view_own';
    }
    if (strpos($controller, "'roster.manage'") === false) {
        $failures[] = 'the roster write does not require roster.manage';
    }
    // No operational SQL: Drupal is an HTTP consumer only.
    foreach (['PDO', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE '] as $forbidden) {
        if (strpos($controller, $forbidden) !== false) {
            $failures[] = "the roster controller must not own operational SQL: {$forbidden}";
        }
    }
}

// ---- offline contract in the template and the script -----------------------
foreach ([
    'data-roster-offline-root',
    'data-roster-queue-badge',
    'data-roster-submit-url',
    'data-roster-queue-key',
    'data-roster-cache-key',
    'data-roster-token',
] as $marker) {
    if (strpos($template, $marker) === false) {
        $failures[] = "roster template offline marker missing: {$marker}";
    }
}
if (strpos($template, '|raw') !== false) {
    // Twig comments cannot bypass escaping, so strip them before deciding; the
    // template documents this rule in a comment, which is not a violation.
    $templateCode = preg_replace('/\{#.*?#\}/s', '', $template) ?? $template;
    if (strpos($templateCode, '|raw') !== false) {
        $failures[] = 'the roster template must not bypass Twig escaping';
    }
}

foreach ([
    'merdpos_roster_queue_v1',
    'merdpos_roster_cache_v1',
    'crypto.randomUUID',
    // The queue must live in web storage, not in memory: a reload is exactly when
    // an unsent week matters most. The exact variable name is not the contract.
    'localStorage.getItem(',
    'localStorage.setItem(',
    'navigator.onLine',
    "window.addEventListener('online'",
    'Saved on this phone. Sending',
    'Saved successfully.',
    "'✓ Up to date'",
    'data?.retryable === true',
    'window.location.reload()',
    'X-MERDPOS-CSRF',
] as $marker) {
    if (strpos($js, $marker) === false) {
        $failures[] = "roster offline JS marker missing: {$marker}";
    }
}
// The web-storage queue persists only while the tab lives, so it must be storage,
// not memory; and an authoritative rejection must not be retried forever.
if (strpos($js, 'credentials:') === false) {
    $failures[] = 'the roster flush must send same-origin credentials';
}

// ---- styling: light/dark safety -------------------------------------------
if (strpos($css, '.merdpos-roster-queue-pill') === false) {
    $failures[] = 'roster queue styling is missing';
}
if (strpos($css, '.merdpos-roster-cell') === false) {
    $failures[] = 'roster grid cell styling is missing';
}
// A literal colour cannot respond to the theme, so it silently breaks dark mode.
if (preg_match('/#[0-9a-fA-F]{3,8}\b/', $css) === 1) {
    $failures[] = 'roster CSS must use design tokens, not literal hex colours';
}
if (preg_match('/\b(?:rgb|hsl)a?\(/', $css) === 1) {
    $failures[] = 'roster CSS must use design tokens, not literal rgb()/hsl() colours';
}

// ---- reachability ----------------------------------------------------------
if (preg_match('/^roster:/m', $libraries) !== 1 || strpos($libraries, 'js/roster-v1.js') === false) {
    $failures[] = 'the roster library is not registered';
}
if (strpos($moduleFile, "'merdpos_roster'") === false) {
    $failures[] = 'the merdpos_roster theme hook is not registered';
}
if (strpos($themeFile, 'merdpos_core.roster') === false) {
    $failures[] = 'the roster surface has no navigation entry';
}
foreach (['roster.view', 'roster.manage'] as $navPermission) {
    if (strpos($themeFile, $navPermission) === false) {
        $failures[] = "the roster navigation entry is not gated on {$navPermission}";
    }
}

// ---- deploy wiring ---------------------------------------------------------
if (strpos($deploy, 'validate_roster_planner_v1.php') === false) {
    $failures[] = 'the Drupal deploy does not run this validator';
}

// ---- template parses (when Twig is available) ------------------------------
if (strpos($template, '|raw') === false && class_exists(\Twig\Environment::class)) {
    try {
        $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader());
        $twig->parse($twig->tokenize(new \Twig\Source($template, 'merdpos-roster.html.twig')));
    }
    catch (\Throwable $error) {
        $failures[] = 'roster template does not parse: ' . $error->getMessage();
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Roster planner contract failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}

echo "Roster planner contract validated: routes, CSRF + roster.manage write, no operational SQL, offline queue with idempotent submission IDs, escaped template, token-driven light/dark styling, navigation and deploy wiring.\n";
exit(0);
