<?php

declare(strict_types=1);

$repo = dirname(__DIR__, 3);
$errors = [];

function continuity_read(string $path, array &$errors): string {
    if (!is_file($path)) {
        $errors[] = "Missing continuity file: {$path}";
        return '';
    }
    $content = file_get_contents($path);
    if ($content === false) {
        $errors[] = "Could not read continuity file: {$path}";
        return '';
    }
    return $content;
}

function continuity_require(string $content, string $needle, string $label, array &$errors): void {
    if (strpos($content, $needle) === false) {
        $errors[] = "Missing {$label}: {$needle}";
    }
}

function continuity_forbid(string $content, string $needle, string $label, array &$errors): void {
    if (strpos($content, $needle) !== false) {
        $errors[] = "Stale/forbidden {$label}: {$needle}";
    }
}

$agents = continuity_read($repo . '/AGENTS.md', $errors);
$bootstrap = continuity_read($repo . '/.ai/README.md', $errors);
$invariants = continuity_read($repo . '/.ai/invariants.md', $errors);
$memory = continuity_read($repo . '/.ai/memory.md', $errors);
$activeIndex = continuity_read($repo . '/.ai/work/ACTIVE.yaml', $errors);
$decisions = continuity_read($repo . '/.ai/decisions.md', $errors);
$regressions = continuity_read($repo . '/.ai/regression-inventory.md', $errors);
$studioDoc = continuity_read($repo . '/namecheap_beta_live/timesheet_portal/UI_STUDIO.md', $errors);
$portalReadme = continuity_read($repo . '/namecheap_beta_live/timesheet_portal/README.md', $errors);
$backendReadme = continuity_read($repo . '/namecheap_beta_live/backend/README.md', $errors);

continuity_require($agents, 'namecheap-beta-live', 'canonical branch bootstrap', $errors);
continuity_require($bootstrap, '.ai/work/ACTIVE.yaml', 'work-packet bootstrap', $errors);
continuity_require($memory, '**Updated:** 2026-09-01', 'current memory date', $errors);
continuity_require($memory, 'Current DevStudio checkpoint', 'current DevStudio memory', $errors);
continuity_require($memory, 'Studio29', 'Studio29 memory marker', $errors);
continuity_require($memory, 'migration 035', 'migration 035 memory marker', $errors);
continuity_require($memory, 'migration 036:', 'migration 036 memory marker', $errors);
continuity_require($memory, 'Current analytics/dashboard checkpoint', 'analytics memory marker', $errors);

continuity_require($invariants, 'dedicated client-scoped Studio state/audit subsystem', 'current Studio persistence invariant', $errors);
continuity_require($invariants, 'Palette-standard escalation', 'palette escalation invariant', $errors);
continuity_forbid($invariants, 'Draft UI changes may persist only as local browser preview state', 'local-only Studio invariant', $errors);
continuity_forbid($invariants, 'It must not call mutation APIs, write database state', 'obsolete no-Studio-API invariant', $errors);

continuity_require($studioDoc, '# MERDPOS DevStudio — Current Contract', 'current-state-first Studio doc', $errors);
continuity_require($studioDoc, 'Global unresolved inbox', 'Studio unresolved inbox contract', $errors);
continuity_require($studioDoc, 'Settings → **MERDPOS Palette**', 'Studio29 palette contract', $errors);
continuity_require($studioDoc, 'Full radial dismissal clears the previously selected', 'Studio deselection contract', $errors);
continuity_require($studioDoc, 'Palette-standard escalation', 'Studio palette escalation contract', $errors);
continuity_forbid($studioDoc, 'getHistory()', 'retired Studio history getter', $errors);
continuity_forbid($studioDoc, 'trash-can delete control', 'retired Studio audit delete UI', $errors);
continuity_forbid($studioDoc, 'keeps the current selection', 'retired radial-dismiss selection behavior', $errors);
continuity_forbid($backendReadme, 'History deletion soft-deletes', 'retired Studio history deletion backend guidance', $errors);

continuity_require($decisions, 'Studio28 unresolved patch inbox and LLM receipt', 'Studio28 durable decision', $errors);
continuity_require($decisions, 'Studio29 palette patches and radial dismissal', 'Studio29 durable decision', $errors);
continuity_require($regressions, 'Studio28 unresolved inbox + receipt regressions', 'Studio28 regression inventory', $errors);
continuity_require($regressions, 'Studio29 palette / utility-section regressions', 'Studio29 regression inventory', $errors);
continuity_require($portalReadme, 'Current authoritative DevStudio specification', 'portal current Studio pointer', $errors);
continuity_require($backendReadme, 'AI continuity release guard', 'backend continuity guard documentation', $errors);

$durableDocs = [
    '.ai/decisions.md' => $decisions,
    '.ai/invariants.md' => $invariants,
    '.ai/memory.md' => $memory,
    '.ai/playbook.md' => continuity_read($repo . '/.ai/playbook.md', $errors),
    '.ai/regression-inventory.md' => $regressions,
    'timesheet_portal/UI_STUDIO.md' => $studioDoc,
    'timesheet_portal/README.md' => $portalReadme,
    'backend/README.md' => $backendReadme,
];
foreach ($durableDocs as $label => $content) {
    foreach (['â', 'Â', "\u{FFFD}"] as $bad) {
        if (strpos($content, $bad) !== false) {
            $errors[] = "Encoding/mojibake marker found in {$label}: {$bad}";
        }
    }
}

continuity_require($activeIndex, 'authoritative_branch: namecheap-beta-live', 'ACTIVE canonical branch', $errors);
continuity_require($activeIndex, 'updated_at:', 'ACTIVE update timestamp', $errors);

preg_match_all('/^\s+path:\s+(.ai\/work\/active\/[^\s]+\.yaml)\s*$/m', $activeIndex, $matches);
$indexedPaths = $matches[1] ?? [];
$indexedSet = array_fill_keys($indexedPaths, true);
foreach ($indexedPaths as $relative) {
    $packetPath = $repo . '/' . $relative;
    $packet = continuity_read($packetPath, $errors);
    continuity_require($packet, 'id:', "packet id {$relative}", $errors);
    continuity_require($packet, 'packet_state:', "packet state {$relative}", $errors);
    continuity_require($packet, 'lifecycle:', "packet lifecycle {$relative}", $errors);
    continuity_require($packet, 'base_head:', "packet base HEAD {$relative}", $errors);
    continuity_require($packet, 'last_seen_head:', "packet last-seen HEAD {$relative}", $errors);
    continuity_require($packet, 'next_action:', "packet next action {$relative}", $errors);
    continuity_require($packet, 'updated_at:', "packet update timestamp {$relative}", $errors);
    continuity_forbid($packet, 'packet_state: CLOSED', "closed packet left active {$relative}", $errors);
}

$activeFiles = glob($repo . '/.ai/work/active/*.yaml') ?: [];
foreach ($activeFiles as $packetPath) {
    $relative = str_replace('\\', '/', substr($packetPath, strlen($repo) + 1));
    if (!isset($indexedSet[$relative])) {
        $errors[] = "Orphan active work packet not indexed by ACTIVE.yaml: {$relative}";
    }
}

if ($errors) {
    fwrite(STDERR, "MERDPOS AI continuity validation FAILED:\n");
    foreach ($errors as $error) fwrite(STDERR, " - {$error}\n");
    exit(1);
}

echo "MERDPOS AI continuity validated: current-state knowledge, Studio safety/inbox/palette contract, work-packet index and encoding are self-consistent.\n";
