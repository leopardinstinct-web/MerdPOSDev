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

function beta_contract_require_absent(string $content, string $needle, string $label, array &$errors): void
{
    if (str_contains($content, $needle)) {
        $errors[] = $label . ' still contains retired/forbidden runtime ownership: `' . $needle . '`.';
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
$login = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/index.php', $errors);
$scan = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/scan.php', $errors);
$htaccess = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/.htaccess', $errors);

$tokens = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/assets/design-tokens.css', $errors);
$designSystem = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/assets/design-system.css', $errors);
$designAudit = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/assets/design-audit.js', $errors);
$shellCss = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/assets/shell.css', $errors);
$appUiCss = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/assets/app-ui.css', $errors);
$dashboardCss = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/assets/dashboard-builder.css', $errors);
$dashboardBuilderJs = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/assets/dashboard-builder.js', $errors);
$navigationJs = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/assets/navigation.js', $errors);
$minimalJs = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/assets/minimal-controls.js', $errors);
$mobileJs = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/assets/mobile-runtime.js', $errors);
$clientJs = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/assets/client.js', $errors);
$rolesJs = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/assets/roles.js', $errors);
$brandAssetsJs = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/assets/brand/brand-assets.js', $errors);
$omnichannelJs = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/assets/omnichannel-identity.js', $errors);
$brandCss = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/assets/brand/brand.css', $errors);
$accountMenuCss = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/assets/account-menu.css', $errors);
$uiStudioJs = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/assets/ui-studio.js', $errors);
$uiStudioCss = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/assets/ui-studio.css', $errors);
$brandStandard = beta_contract_read($repo . '/docs/pos_latest/BRAND_IDENTITY_STANDARD.md', $errors);

$orchestrator = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/includes/legacy_migration_orchestrator.php', $errors);
$knownFetch = beta_contract_read($repo . '/namecheap_beta_live/timesheet_portal/includes/legacy_known_fetch.php', $errors);

// Beta-only project scope cannot silently regress.
foreach ([
    'root beta README' => $rootReadme,
    'project context' => $projectContext,
    'new-chat starter' => $newChat,
] as $label => $content) {
    beta_contract_require_contains($content, 'Every chat, prompt', $label . ' beta-only scope', $errors);
    beta_contract_require_contains($content, 'namecheap-beta-live', $label . ' beta branch scope', $errors);
    beta_contract_require_contains($content, 'explicitly', $label . ' non-beta opt-out rule', $errors);
}

// Implementation-state discipline and README maintenance remain release rules.
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
beta_contract_require_contains($rootReadme, 'README maintenance', 'root beta README', $errors);
beta_contract_require_contains($backendReadme, 'README maintenance', 'backend README', $errors);
beta_contract_require_contains($portalReadme, 'README maintenance', 'portal README', $errors);

// Five-color brand master palette is binding. Derived UI shades may use color-mix,
// while operational success/warning/danger/info colors remain separate semantics.
foreach ([
    '--color-brand-white: #FFFFFF' => 'master White',
    '--color-brand-background: #F5F7FC' => 'master App Background',
    '--color-brand-navy: #031B4B' => 'master Brand Navy',
    '--color-brand-cyan: #12BDF3' => 'master Brand Cyan',
    '--color-brand-violet: #8B2EFF' => 'master Violet',
] as $needle => $label) {
    beta_contract_require_contains($tokens, $needle, $label, $errors);
}
foreach ([
    '--color-brand-sky:',
    '--color-brand-indigo:',
    '--color-brand-purple:',
    '--color-brand-light-violet:',
    '--color-brand-slate:',
    '--color-brand-descriptor:',
] as $retiredBrandToken) {
    beta_contract_require_absent($tokens, $retiredBrandToken, 'five-color master palette', $errors);
}
foreach (['#1D6CFF', '#2B90FF', '#586CFF', '#9638FF', '#B184FF', '#6A748B'] as $retiredBrandLiteral) {
    beta_contract_require_absent($brandCss . $accountMenuCss, $retiredBrandLiteral, 'brand-facing CSS master-palette compliance', $errors);
}
beta_contract_require_contains($brandCss, '--merd-brand-gradient:var(--gradient-brand', 'brand CSS master gradient', $errors);
beta_contract_require_contains($accountMenuCss, 'var(--color-brand-cyan)', 'About splash master palette wiring', $errors);

// Canonical design-system ownership.
foreach ([
    '--color-brand-primary' => 'semantic brand token',
    '--color-bg-main' => 'semantic background token',
    '--color-text-primary' => 'semantic text token',
    '--space-4' => 'spacing scale',
    '--type-lg' => 'modular typography scale',
    '--size-touch: 3rem' => '48px touch token',
    '--size-icon-action: 2.875rem' => '46px desktop action token',
    ':root[data-theme="dark"]' => 'dark semantic pair',
] as $needle => $label) {
    beta_contract_require_contains($tokens, $needle, $label, $errors);
}

foreach ([
    '.merd-collapsible-search' => 'canonical Search primitive',
    '.merd-action-cluster' => 'Search/Add placement',
    'button.merd-icon-action' => 'canonical Add primitive',
    'table-scroll' => 'table overflow contract',
    'dialog.portal-dialog' => 'dialog contract',
    ':focus-visible' => 'focus visibility contract',
    '@media (max-width: 51.25rem)' => 'mobile component contract',
] as $needle => $label) {
    beta_contract_require_contains($designSystem, $needle, $label, $errors);
}

// Shared structural files consume tokens and must not recreate their own root palettes.
beta_contract_require_contains($shellCss, 'var(--color-nav-bg)', 'shell token consumption', $errors);
beta_contract_require_absent($shellCss, '--shell-rail:#', 'shell duplicate palette', $errors);
beta_contract_require_contains($appUiCss, 'var(--color-border-subtle)', 'feature layout token consumption', $errors);
beta_contract_require_absent($appUiCss, '--ui-bg:', 'feature duplicate palette', $errors);
beta_contract_require_contains($dashboardCss, 'var(--color-bg-main)', 'dashboard token consumption', $errors);

// Client/Role feature modules are behavior-only. Their visual composition belongs
// to app-ui.css so dynamically mounted UI cannot override the canonical layer.
foreach ([
    'client.js' => $clientJs,
    'roles.js' => $rolesJs,
] as $label => $content) {
    beta_contract_require_absent($content, "document.createElement('style')", $label . ' feature CSS ownership', $errors);
    beta_contract_require_absent($content, 'style.textContent', $label . ' feature CSS ownership', $errors);
    beta_contract_require_absent($content, 'ensureStyles()', $label . ' feature CSS ownership', $errors);
}
beta_contract_require_contains($appUiCss, '.clients-admin-toolbar', 'Clients feature composition ownership', $errors);
beta_contract_require_contains($appUiCss, '.migration-status-grid', 'Legacy migration feature composition ownership', $errors);
beta_contract_require_contains($appUiCss, '.roles-shell', 'Roles feature composition ownership', $errors);
beta_contract_require_contains($appUiCss, '.permission-row', 'Permission feature composition ownership', $errors);

// Runtime loads one canonical visual layer; old corrective CSS layers are retired.
foreach ([
    'assets/design-tokens.css?v=20260828palette1',
    'assets/design-system.css?v=20260827visual1',
    'assets/design-audit.js?v=20260826ds1',
    'assets/minimal-controls.js?v=20260826ds1',
    'assets/mobile-runtime.js?v=20260828mobile1',
    'assets/account-menu.css?v=20260828palette1',
] as $asset) {
    beta_contract_require_contains($management, $asset, 'management design-system wiring', $errors);
}
foreach ([
    'assets/apple-principles.css',
    'assets/ui-standard.css',
    'assets/minimal-controls.css',
    'assets/mobile-hardening.css',
    'assets/omnichannel-identity.css',
] as $retiredAsset) {
    beta_contract_require_absent($management, $retiredAsset, 'retired competing CSS layer', $errors);
}

// DEV UI Studio is local preview tooling only. It must never become a browser-side source/data writer.
beta_contract_require_contains($dashboard, "'is_dev'=>\$isDev", 'UI Studio actual DEV identity flag', $errors);
beta_contract_require_contains($dashboard, '<?php if ($isDev): ?>', 'UI Studio PHP DEV gate', $errors);
beta_contract_require_contains($dashboard, 'id="openUiStudioBtn"', 'UI Studio launch control', $errors);
beta_contract_require_contains($management, 'const isDev=window.MERDPOS_AUTH?.is_dev===true', 'UI Studio runtime DEV gate', $errors);
beta_contract_require_contains($management, 'assets/ui-studio.css?v=20260829studio6', 'UI Studio stylesheet wiring', $errors);
beta_contract_require_contains($management, 'assets/ui-studio.js?v=20260829studio6', 'UI Studio runtime wiring', $errors);
beta_contract_require_absent($management, 'assets/vendor/circular-menu/', 'retired circular-menu dependency', $errors);
beta_contract_require_absent($management, 'react-circular-menu', 'retired React circular-menu dependency', $errors);
beta_contract_require_contains($uiStudioJs, 'if(window.MERDPOS_AUTH?.is_dev!==true)return;', 'UI Studio self DEV guard', $errors);
beta_contract_require_contains($uiStudioJs, 'DEV - PREVIEW ONLY', 'UI Studio preview-only label', $errors);
beta_contract_require_contains($uiStudioJs, 'getChangeSet', 'UI Studio structured handoff', $errors);
beta_contract_require_contains($uiStudioJs, 'copyForChat', 'UI Studio chat handoff', $errors);
beta_contract_require_contains($uiStudioJs, "kind:'move'", 'UI Studio move patch support', $errors);
beta_contract_require_contains($uiStudioJs, "component:'This component type'", 'UI Studio component scope', $errors);
beta_contract_require_contains($uiStudioJs, "matching:'All matching elements'", 'UI Studio matching scope', $errors);
beta_contract_require_contains($uiStudioJs, "pages:'All pages'", 'UI Studio all-pages scope', $errors);
beta_contract_require_contains($uiStudioJs, 'scopeSelectorFor', 'UI Studio scope selector engine', $errors);
beta_contract_require_contains($uiStudioJs, "if(scope==='matching')return target;", 'UI Studio matching scope crosses portal panels', $errors);
beta_contract_require_contains($uiStudioJs, "kind:'text'", 'UI Studio inline text patch support', $errors);
beta_contract_require_contains($uiStudioJs, 'toggleRevealHidden', 'UI Studio temporary reveal support', $errors);
beta_contract_require_contains($uiStudioJs, 'assets/vendor/google-material-symbols/', 'UI Studio local Google Material Symbols source', $errors);
beta_contract_require_absent($uiStudioJs, 'const ICONS={', 'retired hand-drawn inline icon registry', $errors);
beta_contract_require_contains($uiStudioJs, "setAttribute('popover','manual')", 'UI Studio manual top-layer Popover host', $errors);
beta_contract_require_contains($uiStudioJs, 'showPopover()', 'UI Studio native Popover open behavior', $errors);
beta_contract_require_contains($uiStudioJs, 'setPointerCapture', 'UI Studio draggable hub behavior', $errors);
beta_contract_require_contains($uiStudioCss, '.merd-ui-hub', 'UI Studio circular hub', $errors);
beta_contract_require_contains($uiStudioCss, '.merd-ui-studio-host[popover]', 'UI Studio manual Popover host CSS', $errors);
beta_contract_require_absent($uiStudioJs, '<aside', 'retired UI Studio inspector panel', $errors);
beta_contract_require_absent($uiStudioJs, 'data-studio-output', 'retired UI Studio change-set textarea', $errors);
beta_contract_require_absent($uiStudioCss, '.merd-ui-studio {', 'retired UI Studio panel CSS', $errors);
beta_contract_require_absent($uiStudioJs, 'fetch(', 'UI Studio mutation/network isolation', $errors);
beta_contract_require_absent($uiStudioJs, 'XMLHttpRequest', 'UI Studio mutation/network isolation', $errors);
beta_contract_require_absent($uiStudioJs, '/api/', 'UI Studio mutation/network isolation', $errors);
beta_contract_require_contains($uiStudioCss, 'var(--color-brand-violet)', 'UI Studio master palette use', $errors);
foreach (['LICENSE-Apache-2.0.txt','NOTICE.md','ads_click_48px.svg','palette_48px.svg','visibility_48px.svg','edit_48px.svg'] as $materialIconFile) {
    if (!is_file($repo . '/namecheap_beta_live/timesheet_portal/assets/vendor/google-material-symbols/' . $materialIconFile)) {
        $errors[] = 'UI Studio Google Material Symbols asset is missing: ' . $materialIconFile;
    }
}
// Product identity uses exact supplied artwork with one runtime asset registry.
beta_contract_require_contains($management, 'assets/brand/brand-assets.js?v=20260827brand4', 'brand asset registry wiring', $errors);
beta_contract_require_contains($management, 'assets/omnichannel-identity.js?v=20260828palette1', 'brand identity runtime cache version', $errors);
beta_contract_require_contains($omnichannelJs, 'assets/brand/brand.css?v=20260828palette1', 'brand stylesheet cache version', $errors);
beta_contract_require_contains($dashboard, 'assets/brand/brand.css?v=20260828palette1', 'authenticated brand stylesheet cache version', $errors);
foreach (['merdpos-logo-approved.png','merdpos-mark.png','merdpos-wordmark.png','merdpos-tagline.png'] as $assetName) {
    beta_contract_require_contains($brandAssetsJs, $assetName, 'canonical brand registry', $errors);
    if (!is_file($repo . '/namecheap_beta_live/timesheet_portal/assets/brand/' . $assetName)) {
        $errors[] = 'Canonical brand asset is missing: ' . $assetName;
    }
    beta_contract_require_contains($brandStandard, $assetName, 'brand identity standard', $errors);
}
beta_contract_require_contains($dashboard, 'merdpos-logo-approved.png?v=20260827brand4', 'authenticated full brand lockup', $errors);
beta_contract_require_contains($login, 'merdpos-logo-approved.png?v=20260827brand4', 'login full brand lockup', $errors);
beta_contract_require_contains($scan, 'merdpos-mark.png?v=20260827brand4', 'attendance mark-only identity', $errors);
beta_contract_require_contains($brandCss, '.merd-brand__wordmark-image', 'exact wordmark image utility', $errors);
beta_contract_require_contains($brandCss, '.merd-brand__tagline-image', 'exact tagline image utility', $errors);
beta_contract_require_absent($brandCss, '.merd-brand__wordmark{', 'CSS-reconstructed product wordmark', $errors);
beta_contract_require_absent($brandCss, '.merd-brand__pos{', 'CSS-reconstructed POS wordmark segment', $errors);

// Add/Search behavior is shared rather than feature-local.
foreach (['addEmployeeBtn','addStoreBtn','addClientBtn','addRoleBtn'] as $id) {
    beta_contract_require_contains($minimalJs, $id, 'minimal Add behavior', $errors);
}
beta_contract_require_contains($minimalJs, '.dashboard-add-button', 'Dashboard Add normalization', $errors);
beta_contract_require_contains($minimalJs, 'clusterSearchAndAdd', 'Search/Add runtime clustering', $errors);

// Mobile shell/navigation state is authoritative. Contextual subnav space exists
// only while a contextual group is open, and fixed nav yields to the keyboard.
beta_contract_require_contains($navigationJs, 'merd-mobile-subnav-open', 'mobile contextual subnav state', $errors);
beta_contract_require_contains($navigationJs, 'syncMobileSubnavState', 'mobile contextual subnav synchronization', $errors);
beta_contract_require_contains($shellCss, 'body.merd-mobile-subnav-open .app-workspace.merd-page-shell', 'mobile contextual subnav offset', $errors);
beta_contract_require_contains($shellCss, 'body.merd-keyboard-open .app-rail', 'software-keyboard navigation protection', $errors);
beta_contract_require_contains($shellCss, 'body.merd-shell.merd-keyboard-open', 'software-keyboard bottom offset reset', $errors);
beta_contract_require_contains($tokens, ':root[data-theme="dark"]', 'semantic dark theme tokens', $errors);
beta_contract_require_contains($management, 'MERDPOSTheme', 'theme persistence runtime', $errors);
beta_contract_require_contains($navigationJs, 'rail-theme-toggle', 'immediate theme toggle wiring', $errors);
beta_contract_require_contains($shellCss, '.rail-theme-toggle', 'theme toggle shell styling', $errors);
beta_contract_require_contains($dashboardBuilderJs, 'dashboardEditToggle', 'DEV dashboard explicit edit mode', $errors);

// Mobile functionality remains runtime-tested, not CSS-only.
foreach ([
    'visualViewport' => 'mobile visual viewport handling',
    'installDialogFallback' => 'mobile dialog fallback',
    'moveDashboardWidget' => 'mobile dashboard reorder parity',
    'MERDPOSMobileRuntime' => 'mobile runtime hook',
    'small-touch-target' => 'mobile touch-target audit',
    'page-horizontal-overflow' => 'mobile overflow audit',
] as $needle => $label) {
    beta_contract_require_contains($mobileJs, $needle, $label, $errors);
}

// Runtime visual/semantic audit catches contextual failures after composition.
foreach ([
    'normalizeHeadingSemantics' => 'heading normalizer',
    'heading:h1-count' => 'single-H1 audit',
    'heading:skipped' => 'heading nesting audit',
    'placement:search-add-height' => 'Search/Add geometry audit',
    'touch:under-44' => 'touch target audit',
    'contrastRatio' => 'WCAG contrast audit',
    'layout:page-horizontal-overflow' => 'page overflow audit',
    'a11y:missing-name' => 'accessible-name audit',
] as $needle => $label) {
    beta_contract_require_contains($designAudit, $needle, $label, $errors);
}

// Entry pages must use the same canonical design system and contain one H1.
foreach (['login' => $login, 'attendance scan' => $scan] as $label => $content) {
    beta_contract_require_contains($content, 'assets/design-system.css?v=20260827brand2', $label . ' design-system load', $errors);
    if (preg_match_all('/<h1\b/i', $content) !== 1) {
        $errors[] = $label . ' must contain exactly one H1 in source.';
    }
}

// Portal heading model is one application H1 + panel H2 + nested H3.
beta_contract_require_contains($designAudit, "appTitle.id = 'merdApplicationTitle'", 'portal application H1', $errors);
beta_contract_require_contains($designAudit, "replaceTag(heading, 'h2')", 'portal hero H2 normalization', $errors);
beta_contract_require_contains($designAudit, "replaceTag(heading, 'h3')", 'portal card/widget H3 normalization', $errors);

// Shared contract assets must revalidate after deploy.
foreach ([
    'management\\.js',
    'design-tokens\\.css',
    'design-system\\.css',
    'design-audit\\.js',
    'minimal-controls\\.js',
    'mobile-runtime\\.js',
    'shell\\.css',
] as $assetNeedle) {
    beta_contract_require_contains($htaccess, $assetNeedle, 'shared UI cache revalidation', $errors);
}
beta_contract_require_contains($htaccess, 'Cache-Control "no-cache, must-revalidate"', 'shared UI cache revalidation', $errors);

// Known Google migration contracts remain fail-closed and deterministic.
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

echo "MERDPOS beta runtime contract validated: beta-only scope, implementation-state discipline, canonical tokens/component ownership, feature CSS isolation, authoritative mobile navigation state, modular headings, shared Add/Search placement, mobile runtime parity, contextual visual/a11y audit, cache revalidation, README contract, and deterministic legacy Sheet reader are wired.\n";
