<?php
declare(strict_types=1);

/**
 * MERDPOS Drupal beta — public release identity contract.
 *
 * The canonical Drupal deployment (drupal/tools/namecheap_deploy.sh) writes a
 * minimal, non-secret release marker into the web root after the deployment and
 * its runtime probes succeed. This validator protects that contract:
 *
 *   - schema: exactly environment, component, commit, deployed_at
 *   - commit: full immutable 40-character git SHA
 *   - deployed_at: UTC ISO-8601 (Z)
 *   - no filesystem paths, credentials or configuration values
 *   - the deployment script writes the marker atomically and validates it
 *   - the web server still denies dotfiles and grants no broad exception
 *
 * Usage:
 *   php drupal/tools/validate_release_marker_v1.php                     # validate the deployed marker file
 *   php drupal/tools/validate_release_marker_v1.php --file <path>       # validate a specific marker
 *   php drupal/tools/validate_release_marker_v1.php --expect-commit <sha>
 *   php drupal/tools/validate_release_marker_v1.php --self-test         # deterministic contract self-test (no deploy needed)
 */

$root = dirname(__DIR__);
$web = $root . '/web';
$markerPath = $web . '/merdpos-release.json';
$deployScript = $root . '/tools/namecheap_deploy.sh';
$htaccess = $web . '/.htaccess';

const RELEASE_MARKER_NAME = 'merdpos-release.json';
const RELEASE_ALLOWED_KEYS = ['component', 'commit', 'deployed_at', 'environment'];

// Required values (single colon): the deploy script passes them space-separated.
$options = getopt('', ['file:', 'expect-commit:', 'self-test']);
$failures = [];

/**
 * Validate one marker document.
 *
 * @param array<int,string> $failures
 */
function validate_marker(string $path, ?string $expectCommit, array &$failures, bool $requireFile = true): void
{
    if (!is_file($path)) {
        if ($requireFile) {
            $failures[] = "release marker missing: {$path}";
        }
        return;
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        $failures[] = "release marker unreadable or empty: {$path}";
        return;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $failures[] = "release marker is not a JSON object: {$path}";
        return;
    }
    $keys = array_keys($data);
    sort($keys);
    $allowed = RELEASE_ALLOWED_KEYS;
    sort($allowed);
    if ($keys !== $allowed) {
        $failures[] = "release marker must contain exactly [" . implode(', ', $allowed) . '], found [' . implode(', ', $keys) . ']';
    }
    if (!isset($data['environment']) || $data['environment'] !== 'beta') {
        $failures[] = "release marker environment must be 'beta'";
    }
    if (!isset($data['component']) || $data['component'] !== 'drupal') {
        $failures[] = "release marker component must be 'drupal' (the portal identity is separate)";
    }
    if (!isset($data['commit']) || !is_string($data['commit']) || preg_match('/^[0-9a-f]{40}$/', $data['commit']) !== 1) {
        $failures[] = 'release marker commit must be a full 40-character lowercase git SHA';
    } elseif ($expectCommit !== null && $expectCommit !== '' && $data['commit'] !== $expectCommit) {
        $failures[] = "release marker commit {$data['commit']} does not match the deployed checkout {$expectCommit}";
    }
    if (!isset($data['deployed_at']) || !is_string($data['deployed_at'])
        || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $data['deployed_at']) !== 1) {
        $failures[] = 'release marker deployed_at must be UTC ISO-8601 (YYYY-MM-DDTHH:MM:SSZ)';
    }

    // Public exposure: no paths, credentials, configuration or command lines.
    $forbidden = [
        '/(?:^|[^a-z])\/(?:home|var|etc|opt|usr)\//i' => 'filesystem path',
        '/[A-Za-z]:\\\\/' => 'windows path',
        '/password|passwd|secret|token|api[_-]?key|private[_-]?key|authorization/i' => 'credential-shaped value',
        '/-----BEGIN/' => 'private key block',
        '/ssh|ftp|mysql|database|DB_HOST|DB_USER|DB_PASSWORD/i' => 'infrastructure detail',
        '/php84|composer|drush|rsync|flock/i' => 'deployment command detail',
    ];
    foreach ($forbidden as $pattern => $label) {
        if (preg_match($pattern, $raw) === 1) {
            $failures[] = "release marker exposes {$label}";
        }
    }
}

/** Assert the deployment script generates and validates the marker the canonical way. */
function validate_deploy_contract(string $script, array &$failures): void
{
    if (!is_file($script)) {
        $failures[] = "deployment script missing: {$script}";
        return;
    }
    $source = (string)file_get_contents($script);
    if (!str_contains($source, RELEASE_MARKER_NAME)) {
        $failures[] = 'deployment script does not write ' . RELEASE_MARKER_NAME;
    }
    if (!str_contains($source, 'mktemp') || !str_contains($source, 'RELEASE_TMP')
        || !str_contains($source, 'mv -f "$RELEASE_TMP" "$RELEASE_MARKER"')) {
        $failures[] = 'deployment script must write the release marker atomically (mktemp, then mv into place)';
    }
    if (!str_contains($source, 'validate_release_marker_v1.php')) {
        $failures[] = 'deployment script must validate the release marker before reporting success';
    }
    // The marker may only be published after the deployment work it attests to.
    $markerPos = strrpos($source, RELEASE_MARKER_NAME);
    $installPos = strpos($source, 'site:install');
    if ($markerPos !== false && $installPos !== false && $markerPos < $installPos) {
        $failures[] = 'release marker is written before the Drupal install step; it must attest to a finished deployment';
    }
}

/** Assert the web server still denies dotfiles and adds no broad exception. */
function validate_web_rules(string $htaccess, array &$failures): void
{
    if (!is_file($htaccess)) {
        $failures[] = "web root .htaccess missing: {$htaccess}";
        return;
    }
    $source = (string)file_get_contents($htaccess);
    if (preg_match('/\^\(\\\\\.\(\?!well-known\)/', $source) !== 1) {
        $failures[] = '.htaccess no longer denies dotfiles (the well-known dotfile denial rule is missing)';
    }
    if (!str_contains($source, 'Require all denied')) {
        $failures[] = '.htaccess no longer denies the protected files/directories block';
    }
    if (str_contains($source, RELEASE_MARKER_NAME)) {
        $failures[] = '.htaccess names the release marker; the public marker must not require a web-server exception';
    }
    if (preg_match('/composer/', $source) !== 1 || preg_match('/package\(-lock\)\?/', $source) !== 1) {
        $failures[] = '.htaccess no longer protects composer/package metadata files';
    }
}

/** Deterministic self-test: no deployment or network required. */
function run_self_test(string $tmpBase, array &$failures): void
{
    $dir = $tmpBase . '/merdpos-release-selftest-' . bin2hex(random_bytes(4));
    if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
        $failures[] = 'self-test could not create a temporary directory';
        return;
    }
    $good = [
        'environment' => 'beta',
        'component' => 'drupal',
        'commit' => str_repeat('a', 40),
        'deployed_at' => '2026-10-01T00:00:00Z',
    ];
    $write = static fn(array $data): string => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    $cases = [
        'valid marker accepted' => [$good, true],
        'missing commit rejected' => [array_diff_key($good, ['commit' => 1]), false],
        'extra field rejected' => [$good + ['path' => '/home/dridsheikh'], false],
        'short sha rejected' => [['commit' => 'abc1234'] + $good, false],
        'uppercase sha rejected' => [['commit' => str_repeat('A', 40)] + $good, false],
        'wrong environment rejected' => [['environment' => 'production'] + $good, false],
        'portal component rejected' => [['component' => 'portal'] + $good, false],
        'non-utc timestamp rejected' => [['deployed_at' => '2026-10-01 00:00:00'] + $good, false],
    ];
    foreach ($cases as $label => [$data, $expectClean]) {
        $path = $dir . '/' . md5($label) . '.json';
        file_put_contents($path, $write($data));
        $caseFailures = [];
        validate_marker($path, null, $caseFailures, true);
        $clean = $caseFailures === [];
        if ($clean !== $expectClean) {
            $failures[] = "self-test '{$label}': expected " . ($expectClean ? 'acceptance' : 'rejection')
                . ', got ' . ($clean ? 'acceptance' : count($caseFailures) . ' failure(s): ' . implode('; ', $caseFailures));
        }
    }

    // A leaked credential must be rejected even when the schema is otherwise valid.
    $leak = $dir . '/leak.json';
    file_put_contents($leak, $write(['environment' => 'beta', 'component' => 'drupal', 'commit' => str_repeat('b', 40), 'deployed_at' => '2026-10-01T00:00:00Z']) . "db_password=hunter2\n");
    $leakFailures = [];
    validate_marker($leak, null, $leakFailures, true);
    if ($leakFailures === []) {
        $failures[] = "self-test 'secret-shaped value rejected': an exposed credential was accepted";
    }

    // Expect-commit mismatch must fail.
    $mismatch = [];
    validate_marker($dir . '/' . md5('valid marker accepted') . '.json', str_repeat('c', 40), $mismatch, true);
    if ($mismatch === []) {
        $failures[] = "self-test 'expect-commit mismatch': a marker for another SHA was accepted";
    }

    array_map('unlink', glob($dir . '/*') ?: []);
    @rmdir($dir);
}

if (isset($options['self-test'])) {
    run_self_test(sys_get_temp_dir(), $failures);
} else {
    $target = isset($options['file']) && is_string($options['file']) && $options['file'] !== '' ? $options['file'] : $markerPath;
    $expect = isset($options['expect-commit']) && is_string($options['expect-commit']) ? $options['expect-commit'] : null;
    validate_marker($target, $expect, $failures, true);
}

// The generation contract and the web rules are always checked: they are what
// make the marker trustworthy, not merely well formed.
validate_deploy_contract($deployScript, $failures);
validate_web_rules($htaccess, $failures);

if ($failures !== []) {
    fwrite(STDERR, "Release identity contract failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}

echo "Release identity contract validated (" . RELEASE_MARKER_NAME . ").\n";
exit(0);
