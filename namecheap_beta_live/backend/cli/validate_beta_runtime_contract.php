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
$htaccess = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/.htaccess', $errors);
$orchestrator = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/includes/legacy_migration_orchestrator.php', $errors);
$knownFetch = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/includes/legacy_known_fetch.php', $errors);
$minimalJs = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/assets/minimal-controls.js', $errors);
$minimalCss = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/assets/minimal-controls.css', $errors);
$mobileJs = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/assets/mobile-runtime.js', $errors);
$mobileCss = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/assets/mobile-hardening.css', $errors);

// Project scope is beta-only by default. A future context refresh must not
// silently redirect vague project prompts toward main/legacy/Flutter targets.
foreach ([
    'root beta README' => $rootReadme,
    'project context' => $projectContext,
    'new-chat starter' => $newChat,
] as $label => $content) {
    beta_contract_require_contains($content, 'Every chat, prompt', $label . ' beta-only scope', $errors);
    beta_contract_require_contains($content, 'namecheap-beta-live', $label . ' beta branch scope', $errors);
    beta_contract_require_contains($content, 'explicitly', $label . ' non-beta opt-out rule', $errors);
}

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
beta_contract_require_contains($guiStandard, 'right-aligned action cluster', 'GUI standard Search+Add placement', $errors);
beta_contract_require_contains($guiStandard, 'Visual-equivalence rule', 'GUI standard visual equivalence', $errors);
beta_contract_require_contains($management, 'assets/minimal-controls.css?v=20260826b', 'management runtime minimal-control CSS', $errors);
beta_contract_require_contains($management, 'assets/minimal-controls.js?v=20260826b', 'management runtime minimal-control JS', $errors);
beta_contract_require_contains($management, 'assets/ui-standard.css', 'management runtime UI standard', $errors);

foreach (['addEmployeeBtn','addStoreBtn','addClientBtn','addRoleBtn'] as $id) {
    beta_contract_require_contains($minimalJs, $id, 'minimal Add control implementation', $errors);
}
beta_contract_require_contains($minimalJs, '.dashboard-add-button', 'Dashboard Add primitive normalization', $errors);
beta_contract_require_contains($minimalJs, "makeAddButton(button,'Add widget')", 'Dashboard Add primitive normalization', $errors);
beta_contract_require_contains($minimalJs, 'clusterSearchAndAdd', 'Search+Add runtime clustering', $errors);
beta_contract_require_contains($minimalJs, "parent.classList.add('merd-action-cluster')", 'Search+Add runtime clustering', $errors);

beta_contract_require_contains($minimalCss, '--merd-action-diameter:46px', 'canonical action diameter', $errors);
beta_contract_require_contains($minimalCss, '.merd-shell button.merd-icon-action', 'high-specificity Add geometry', $errors);
beta_contract_require_contains($minimalCss, 'border-radius:50%!important', 'canonical true-circle geometry', $errors);
beta_contract_require_contains($minimalCss, '.merd-shell .dashboard-add-button.merd-icon-action', 'Dashboard Add shared CSS primitive', $errors);
beta_contract_require_contains($minimalCss, '.merd-shell .merd-collapsible-search', 'minimal Search control CSS', $errors);
beta_contract_require_contains($minimalCss, '.merd-shell .merd-action-cluster', 'Search+Add placement CSS', $errors);
beta_contract_require_contains($minimalCss, 'justify-content:flex-end!important', 'right-aligned action cluster', $errors);

// Mobile readiness is a runtime capability, not a responsive CSS claim. The
// cross-feature mobile layer must be present and wired, and must cover the
// specific failure classes found in live beta use.
beta_contract_require_contains($management, 'assets/mobile-hardening.css?v=20260826a', 'management mobile hardening CSS', $errors);
beta_contract_require_contains($management, 'assets/mobile-runtime.js?v=20260826a', 'management mobile runtime JS', $errors);
beta_contract_require_contains($mobileCss, '--merd-mobile-topbar-h', 'mobile stable topbar token', $errors);
beta_contract_require_contains($mobileCss, 'body.merd-keyboard-open .app-rail', 'mobile keyboard/nav protection', $errors);
beta_contract_require_contains($mobileCss, 'dialog.portal-dialog > form', 'mobile dialog form scrolling', $errors);
beta_contract_require_contains($mobileCss, '.client-row-actions', 'mobile client row action placement', $errors);
beta_contract_require_contains($mobileCss, '.dashboard-widget-drawer', 'mobile dashboard drawer layer', $errors);
beta_contract_require_contains($mobileJs, 'visualViewport', 'mobile visual viewport handling', $errors);
beta_contract_require_contains($mobileJs, 'installDialogFallback', 'mobile dialog fallback', $errors);
beta_contract_require_contains($mobileJs, 'moveDashboardWidget', 'mobile dashboard reorder parity', $errors);
beta_contract_require_contains($mobileJs, 'MERDPOSMobileRuntime', 'mobile runtime public audit hook', $errors);
beta_contract_require_contains($mobileJs, 'small-touch-target', 'mobile runtime touch-target audit', $errors);
beta_contract_require_contains($mobileJs, 'page-horizontal-overflow', 'mobile runtime overflow audit', $errors);

// Shared cross-portal UI contract assets must revalidate rather than remain stale.
foreach (['minimal-controls\\.js','minimal-controls\\.css','ui-standard\\.css','management\\.js','mobile-runtime\\.js','mobile-hardening\\.css'] as $assetNeedle) {
    beta_contract_require_contains($htaccess, $assetNeedle, 'shared UI cache revalidation', $errors);
}
beta_contract_require_contains($htaccess, 'Cache-Control "no-cache, must-revalidate"', 'shared UI cache revalidation', $errors);

beta_contract_require_contains($dashboard, 'assets/management.js?v=20260826minimal1', 'dashboard management loader cache key', $errors);

// Known legacy Google workbooks must use deterministic contracts, not generic
// score-based header guessing in the migration execution path.
beta_contract_require_contains($orchestrator, "require_once __DIR__ . '/legacy_known_fetch.php';", 'legacy migration deterministic reader', $errors);
beta_contract_require_contains($orchestrator, 'legacy_fetch_sources_known($sources)', 'legacy migration deterministic fetch call', $errors);
foreach (['timesheet','payrate','start_time','employee_setup','general_ledger','zreport_ledger'] as $schema) {
    beta_contract_require_contains($knownFetch, "'{$schema}' =>", 'legacy known header contract', $errors);
}
beta_contract_require_contains($knownFetch, 'Preview stopped without importing anything.', 'legacy fail-closed header handling', $errors);

if (preg_match('/^\s*[-*]?\s*Never inspect or modify `?timesheet_portal\/?`?\.?\s*$/mi', $projectContext)
    || preg_match('/^\s*\d+\.\s*Never inspect or modify timesheet_portal\/?\.?\s*$/mi', $newChat)) {
    $errors[] = 'Obsolete Timesheet Portal prohibition has reappeared in active beta context.';
}

if ($errors) {
    fwrite(STDERR, "MERDPOS beta runtime-contract validation FAILED:\n");
    foreach ($errors as $error) fwrite(STDERR, ' - ' . $error . "\n");
    exit(1);
}

echo "MERDPOS beta runtime contract validated: beta-only scope, implementation-state discipline, canonical Add/Search controls, mobile viewport/dialog/navigation/dashboard hardening, runtime mobile self-audit, shared UI cache revalidation, README contract, and deterministic legacy Sheet reader are present.\n";
