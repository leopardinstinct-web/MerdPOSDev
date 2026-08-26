<?php
declare(strict_types=1);

$repo = dirname(__DIR__, 3);
$errors = [];

function beta_contract_read(string $path, array &$errors): string
{
    if (!is_file($path)) {
        $errors[] = 'Required beta contract file is missing: ' . $path;
        return '';
    }
    $content = file_get_contents($path);
    if ($content === false) {
        $errors[] = 'Required beta contract file could not be read: ' . $path;
        return '';
    }
    return $content;
}

function beta_contract_require_contains(string $content, string $needle, string $label, array &$errors): void
{
    if (!str_contains($content, $needle)) {
        $errors[] = $label . ' is not wired/documented as required: missing `' . $needle . '`.';
    }
}

$rootReadme = beta_contract_read($repo . '/namecheap_beta_live/README.md', $errors);
$backendReadme = beta_contract_read($repo . '/namecheap_beta_live/backend/README.md', $errors);
$portalReadme = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/README.md', $errors);
$projectContext = beta_contract_read($repo . '/docs/pos_latest/PROJECT_CONTEXT.md', $errors);
$guiStandard = beta_contract_read($repo . '/docs/pos_latest/GUI_STANDARD.md', $errors);
$newChat = beta_contract_read($repo . '/docs/pos_latest/NEW_CHAT_STARTER_PROMPT.md', $errors);
$management = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/assets/management.js', $errors);
$dashboard = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/dashboard.php', $errors);
$orchestrator = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/includes/legacy_migration_orchestrator.php', $errors);
$knownFetch = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/includes/legacy_known_fetch.php', $errors);
$minimalJs = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/assets/minimal-controls.js', $errors);
$minimalCss = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/assets/minimal-controls.css', $errors);

// Implementation-state discipline must exist in the root/context/readme entry points.
foreach ([
    'root beta README' => $rootReadme,
    'project context' => $projectContext,
    'portal README' => $portalReadme,
    'new-chat starter' => $newChat,
] as $label => $content) {
    beta_contract_require_contains($content, 'DOCUMENTED', $label, $errors);
    beta_contract_require_contains($content, 'WIRED', $label, $errors);
    beta_contract_require_contains($content, 'VERIFIED', $label, $errors);
}

// README files are mandatory beta artifacts, not optional cleanup.
beta_contract_require_contains($rootReadme, 'README maintenance', 'root beta README', $errors);
beta_contract_require_contains($backendReadme, 'README maintenance', 'backend README', $errors);
beta_contract_require_contains($portalReadme, 'README maintenance', 'portal README', $errors);

// Global minimal-control standard must be BOTH documented and actually loaded.
beta_contract_require_contains($guiStandard, 'circular `+`', 'GUI standard Add rule', $errors);
beta_contract_require_contains($guiStandard, 'circular magnifier', 'GUI standard Search rule', $errors);
beta_contract_require_contains($management, 'assets/minimal-controls.css', 'management runtime minimal-control CSS', $errors);
beta_contract_require_contains($management, 'assets/minimal-controls.js', 'management runtime minimal-control JS', $errors);
beta_contract_require_contains($management, 'assets/ui-standard.css', 'management runtime UI standard', $errors);
beta_contract_require_contains($minimalJs, 'addEmployeeBtn', 'minimal Add control implementation', $errors);
beta_contract_require_contains($minimalJs, 'addStoreBtn', 'minimal Add control implementation', $errors);
beta_contract_require_contains($minimalJs, 'addClientBtn', 'minimal Add control implementation', $errors);
beta_contract_require_contains($minimalJs, 'addRoleBtn', 'minimal Add control implementation', $errors);
beta_contract_require_contains($minimalCss, '.merd-collapsible-search', 'minimal Search control CSS', $errors);

// Core loader cache key must move with this standard so a cached old management.js
// cannot make a documented/wired feature appear absent after deployment.
beta_contract_require_contains($dashboard, 'assets/management.js?v=20260826minimal1', 'dashboard management loader cache key', $errors);

// Known legacy Google workbooks must use deterministic contracts, not generic
// score-based header guessing in the migration execution path.
beta_contract_require_contains($orchestrator, "require_once __DIR__ . '/legacy_known_fetch.php';", 'legacy migration deterministic reader', $errors);
beta_contract_require_contains($orchestrator, 'legacy_fetch_sources_known($sources)', 'legacy migration deterministic fetch call', $errors);
foreach (['timesheet','payrate','start_time','employee_setup','general_ledger','zreport_ledger'] as $schema) {
    beta_contract_require_contains($knownFetch, "'{$schema}' =>", 'legacy known header contract', $errors);
}
beta_contract_require_contains($knownFetch, 'Preview stopped without importing anything.', 'legacy fail-closed header handling', $errors);

// Guard against the obsolete project instruction returning unnoticed.
if (preg_match('/^\s*[-*]?\s*Never inspect or modify `?timesheet_portal\/?`?\.?\s*$/mi', $projectContext)
    || preg_match('/^\s*\d+\.\s*Never inspect or modify timesheet_portal\/?\.?\s*$/mi', $newChat)) {
    $errors[] = 'Obsolete Timesheet Portal prohibition has reappeared in active beta context.';
}

if ($errors) {
    fwrite(STDERR, "MERDPOS beta runtime-contract validation FAILED:\n");
    foreach ($errors as $error) fwrite(STDERR, ' - ' . $error . "\n");
    exit(1);
}

echo "MERDPOS beta runtime contract validated: README/context state discipline, minimal Add/Search wiring, mobile UI layer, and deterministic legacy Sheet reader are present.\n";
