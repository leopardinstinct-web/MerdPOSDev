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
 *   - the write validates the Drupal CSRF header and requires roster.manage, the
 *     page requires roster.view for the store grid, and a roster.view_own-only
 *     actor is served the self-service scope instead of the whole-store grid;
 *   - the controller owns no operational SQL: MERDPOS stays authoritative;
 *   - ends_next_day is DERIVED from the shift times on both sides, because a
 *     client-held checkbox made the default late 16:00-00:00 shift unsaveable;
 *   - a cell that held a stored shift is still submitted when its last employee is
 *     removed, while a never-planned cell is not: the portal replaces a week
 *     wholesale, so "no employees" must not mean "no shift";
 *   - the offline contract is present (versioned queue key, UUID v4 idempotency id
 *     that is never constant, online flush, retryable handshake, bounded retry with
 *     backoff, a queue that is settled by re-reading storage);
 *   - the honest offline guarantee only: the server-rendered week stays readable
 *     while the tab is open and queued changes survive reloads. There is no
 *     service worker and no persisted copy of the week, so no cache key exists;
 *   - the template escapes everything and carries the markers the script binds to;
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

/** A property that must hold, asserted as an absent marker. */
$forbid = static function (string $haystack, string $needle, string $message) use (&$failures): void {
    if (strpos($haystack, $needle) !== false) $failures[] = $message;
};

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
    // The page must require roster.view / roster.view_own and the write roster.manage.
    if (strpos($controller, "'roster.view'") === false || strpos($controller, "'roster.view_own'") === false) {
        $failures[] = 'the roster page does not require roster.view / roster.view_own';
    }
    if (strpos($controller, "'roster.manage'") === false) {
        $failures[] = 'the roster write does not require roster.manage';
    }
    // roster.view_own sees its own shifts read-only; the store grid needs roster.view.
    if (strpos($controller, "'scope' => 'own'") === false || strpos($controller, "'scope' => 'store'") === false) {
        $failures[] = 'the roster page does not separate scope=store from scope=own';
    }
    if (strpos($controller, "\$canManage = \$canViewStore && in_array('roster.manage', \$permissions, true);") === false) {
        $failures[] = 'editing must require roster.view, so a view_own-only actor cannot reach the store grid';
    }
    // ends_next_day is derived server-side from the times and not taken from the client.
    if (strpos($controller, '$endsNextDay = $this->endsNextDay($start, $end);') === false
        || strpos($controller, 'return $end <= $start;') === false) {
        $failures[] = 'ends_next_day must be derived from the shift times, not accepted as an independent control';
    }
    // The client can no longer make a 16:00-00:00 shift unsaveable by omitting a flag.
    $forbid($controller, 'must be marked as ending the next day', 'the old client-ticked ends_next_day rule is still enforced by the controller');
    $forbid($controller, 'cannot also end the next day', 'the old client-ticked ends_next_day rule is still enforced by the controller');
    // Honest offline guarantee: no persisted week cache is written or advertised.
    $forbid($controller, 'CACHE_KEY', 'the controller must not advertise a persisted local week cache');
    $forbid($controller, 'cache_key', 'the controller must not advertise a persisted local week cache');
    // A corrected week is surfaced, never silently substituted.
    if (strpos($controller, "'week_notice' => \$weekNotice") === false) {
        $failures[] = 'a corrected roster week must be surfaced to the planner';
    }
    // No operational SQL: Drupal is an HTTP consumer only.
    foreach (['PDO', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE '] as $forbidden) {
        if (strpos($controller, $forbidden) !== false) {
            $failures[] = "the roster controller must not own operational SQL: {$forbidden}";
        }
    }
}

// ---- milestone contract in the template and the script ---------------------
// Twig comments document the contract, so they must not be allowed to satisfy it:
// strip them before deciding what the live markup actually carries.
$templateCode = preg_replace('/\{#.*?#\}/s', '', $template) ?? $template;
foreach ([
    'data-roster-offline-root',
    'data-roster-queue-badge',
    'data-roster-submit-url',
    'data-roster-queue-key',
    'data-roster-token',
    'data-roster-scope',
    // The origin of a cell and the slot defaults it started from: without these
    // the script cannot tell "no employees left" from "no shift ever planned".
    'data-roster-original="{{ has_shift ? \'shift\' : \'none\' }}"',
    'data-roster-default-start="{{ slot.start }}"',
    'data-roster-default-end="{{ slot.end }}"',
    // A read-only derivation of the times, never a checkbox.
    'data-roster-next-day',
] as $marker) {
    if (strpos($templateCode, $marker) === false) {
        $failures[] = "roster template marker missing: {$marker}";
    }
}
if (strpos($templateCode, 'role="rowgroup"') === false) {
    $failures[] = 'the roster grid must group its rows for assistive technology';
}
$forbid($templateCode, 'data-roster-cache-key', 'the template must not advertise a persisted local week cache');
$forbid($templateCode, 'data-roster-ends-next-day', 'ends_next_day must not be an interactive template control');
$forbid($templateCode, 'type="checkbox"', 'the roster grid must not ask a planner to tick ends_next_day');
if (strpos($templateCode, '|raw') !== false) {
    $failures[] = 'the roster template must not bypass Twig escaping';
}

foreach ([
    'merdpos_roster_queue_v1',
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
    // The browser derives the same midnight rule the controller enforces.
    'ends_next_day: end <= start',
    // Inclusion is decided by origin + employees + changed times, not by a class.
    "planned: assignments.length > 0 || cell.dataset.rosterOriginal === 'shift' || timesChanged,",
    'if (read && read.planned) shifts.push(read.shift);',
    'data-roster-next-day',
    'is-vacant',
    // A settled entry is removed by re-reading storage and matching on its id, so a
    // save during a flush cannot resurrect a dropped week or lose a new one.
    'dropFromQueue',
    'filter((item) => !settled.has(item.submission_id))',
    // Overlapping flushes would race, and a 5xx must be retried with backoff.
    'if (flushInFlight) return;',
    'scheduleRetry',
    'if (deferred > 0) scheduleRetry();',
    'retryTimer = window.setTimeout(() => {',
    'retryDelay = Math.min(retryDelay * 2, RETRY_MAX_MS);',
    // The last-resort id must never be constant, or every week after the first
    // would be deduped away as a repeat.
    'Math.random()',
    // The note is truncated in UTF-8 BYTES client-side, which is stricter than the
    // portal's 255-character limit, so an accepted payload always fits.
    'truncateUtf8Bytes',
] as $marker) {
    if (strpos($js, $marker) === false) {
        $failures[] = "roster JS marker missing: {$marker}";
    }
}
if (strpos($controller, 'mb_substr') === false) {
    $failures[] = 'the controller must truncate an over-long note, not reject the week for it';
}
// A rejected note dropped the WHOLE queued week, and the portal truncates rather
// than rejects, so a byte-count guard here would be both divergent and
// disproportionate for an optional field.
$forbid($controller, 'strlen($note)', 'an over-long note must not reject the whole week');
// The web-storage queue persists only while the tab lives, so it must be storage,
// not memory; and an authoritative rejection must not be retried forever.
if (strpos($js, 'credentials:') === false) {
    $failures[] = 'the roster flush must send same-origin credentials';
}
$forbid($js, 'merdpos_roster_cache_v1', 'the script must not write a persisted local week cache');
$forbid($js, 'cacheConfirmedWeek', 'the script must not write a persisted local week cache');
$forbid($js, 'slice(0, 255)', 'the note must be truncated in UTF-8 bytes, not UTF-16 units');
$forbid($js, 'classList.add(\'is-empty\')', 'removing the last employee must not mark the cell as having no shift');

// ---- styling: light/dark safety -------------------------------------------
foreach ([
    '.merdpos-roster-queue-pill' => 'roster queue styling is missing',
    '.merdpos-roster-cell' => 'roster grid cell styling is missing',
    '.merdpos-roster-cell.is-vacant' => 'the vacant-cell state must be styled from the origin predicate, not from employee count',
    '.merdpos-roster-nextday[hidden]' => 'the read-only next-day indicator must hide when the times do not cross midnight',
    '.merdpos-roster-grid-body' => 'the grid row grouping must be laid out without a new box',
    '.merdpos-roster-own-list' => 'the read-only own-shift list must be styled',
] as $needle => $message) {
    if (strpos($css, $needle) === false) $failures[] = $message;
}
$forbid($css, '.merdpos-roster-cell.is-empty', 'the ambiguous is-empty cell state must not come back');
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
// The roster page needs the shared base and once/drupal only: pulling the
// dashboard library would load dashboard JS/CSS onto a planner for no reason.
$libraryAt = strpos($libraries, "\nroster:\n");
$libraryBlock = $libraryAt === false ? '' : substr($libraries, $libraryAt);
$libraryEnd = strpos($libraryBlock, "\n\n");
if ($libraryEnd !== false) $libraryBlock = substr($libraryBlock, 0, $libraryEnd);
if (strpos($libraryBlock, 'merdpos_core/base') === false || strpos($libraryBlock, 'core/drupal') === false) {
    $failures[] = 'the roster library must keep merdpos_core/base and core/drupal';
}
$forbid($libraryBlock, 'merdpos_core/dashboard', 'the roster library must not depend on the dashboard library');
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

echo "Roster planner contract validated: routes, CSRF + roster.manage write, roster.view_own served own scope read-only, no operational SQL, derived ends_next_day, emptied shifts preserved, bounded retry queue with non-constant idempotency IDs, escaped template, token-driven light/dark styling, navigation and deploy wiring.\n";
exit(0);
