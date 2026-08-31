<?php
require_once __DIR__ . '/includes/auth.php';
if (!current_user()) {
    header('Location: index.php');
    exit;
}
require_once __DIR__ . '/includes/beta_api.php';

try {
    $user = beta_require_active_user();
} catch (Throwable $e) {
    error_log('MERDPOS dashboard authorization failed: ' . get_class($e));
    header('Location: index.php');
    exit;
}

$actualPermissions = (array)($user['actual_permissions'] ?? $user['permissions'] ?? []);
$actualRole = (string)($user['actual_role_key'] ?? $user['role_key'] ?? $user['role'] ?? 'USER');
$actualRoleLabel = (string)($user['actual_role_label'] ?? $user['role_label'] ?? $user['role_name'] ?? $actualRole);
$isDev = !empty($user['is_dev']);
$permissions = (array)($user['permissions'] ?? []);
$role = (string)($user['role_key'] ?? $user['role'] ?? 'USER');
$roleLabel = (string)($user['role_label'] ?? $user['role_name'] ?? $role);
$authorityLevel = (int)($user['authority_level'] ?? 0);
$viewRoleKey = $user['view_role_key'] ?? null;
$viewRoleId = $user['view_role_id'] ?? null;
$isRolePreview = !empty($user['is_role_preview']);
$can = static fn(string $key): bool => !empty($permissions[$key]);
$isManagement = !empty($permissions['workforce.view']) || !empty($permissions['timesheets.view_all']) || !empty($permissions['disputes.review']) || !empty($permissions['finance.cross_store']);
$releaseInfo = [];
$releaseInfoPath = dirname(__DIR__) . '/.beta_release.json';
if (is_readable($releaseInfoPath)) {
    $decodedRelease = json_decode((string)file_get_contents($releaseInfoPath), true);
    if (is_array($decodedRelease)) $releaseInfo = $decodedRelease;
}
$releaseDateLabel = static function(mixed $value): string {
    try { return $value ? (new DateTimeImmutable((string)$value))->format('d M Y') : 'Pending deploy'; }
    catch (Throwable) { return 'Pending deploy'; }
};
$productVersion = (string)($releaseInfo['merdpos']['short'] ?? 'Pending deploy');
$productReleaseDate = $releaseDateLabel($releaseInfo['merdpos']['date'] ?? null);
$devStudioVersion = (string)($releaseInfo['devstudio']['short'] ?? 'Pending deploy');
$devStudioReleaseDate = $releaseDateLabel($releaseInfo['devstudio']['date'] ?? null);
$releaseHighlights = array_values(array_filter(array_map('strval', (array)($releaseInfo['highlights'] ?? []))));
$releaseHighlights = array_slice($releaseHighlights, 0, 3);
while (count($releaseHighlights) < 3) $releaseHighlights[] = 'Release metadata will populate after the next beta deployment.';

$canDashboard = $can('dashboard.view');
$canWorkforce = $can('workforce.view');
$canWorkforceManage = $can('workforce.manage');
$canPayrates = $can('workforce.payrates.manage');
$canCredentialReset = $can('workforce.credentials.reset');
$canStores = $can('stores.view');
$canStoresManage = $can('stores.manage');
$canStoreTimings = $can('stores.timings.manage');
$canTimesheets = $can('timesheets.view_own') || $can('timesheets.view_all');
$canDisputes = $can('disputes.view_own') || $can('disputes.review');
$showDisputesNav = $canDisputes && strtoupper($role) === 'DEV';
$canSubmitDisputes = $can('disputes.submit_own');
$canReviewDisputes = $can('disputes.review');
$canResolveFlags = $can('attendance_flags.resolve');
$canFinance = $can('finance.view');
$canFinanceSubmit = $can('finance.submit');
$canOpenDay = $can('finance.open_day');
$canDevStatus = $can('dev.status');
$canDirectory = $canWorkforce || $canStores;
$hasOperations = $canWorkforce || $canStores;
$hasWorkforceRaw = $canWorkforce;
$hasReports = $canTimesheets || $showDisputesNav;

$panelOrder = [
    'dashboardPanel' => $canDashboard,
    'employeesPanel' => $canWorkforce,
    'storesPanel' => $canStores,
    'timesheetPanel' => $canTimesheets,
    'disputesPanel' => $canDisputes,
    'financialPanel' => $canFinance,
    'devPanel' => $canDevStatus,
];
$initialPanel = 'dashboardPanel';
foreach ($panelOrder as $panelId => $allowed) {
    if ($allowed) { $initialPanel = $panelId; break; }
}

function ui_icon(string $name): string
{
    $material = [
        'home' => 'M600-160v-280h280v280H600ZM440-520v-280h440v280H440ZM80-160v-280h440v280H80Zm0-360v-280h280v280H80Zm440-80h280v-120H520v120ZM160-240h280v-120H160v120Zm520 0h120v-120H680v120ZM160-600h120v-120H160v120Zm360 0Zm-80 240Zm240 0ZM280-600Z',
        'key' => 'M420-360h120l-23-129q20-10 31.5-29t11.5-42q0-33-23.5-56.5T480-640q-33 0-56.5 23.5T400-560q0 23 11.5 42t31.5 29l-23 129Zm60 280q-139-35-229.5-159.5T160-516v-244l320-120 320 120v244q0 152-90.5 276.5T480-80Zm0-84q104-33 172-132t68-220v-189l-240-90-240 90v189q0 121 68 220t172 132Zm0-316Z',
        'wallet' => 'M441-120v-86q-53-12-91.5-46T293-348l74-30q15 48 44.5 73t77.5 25q41 0 69.5-18.5T587-356q0-35-22-55.5T463-458q-86-27-118-64.5T313-614q0-65 42-101t86-41v-84h80v84q50 8 82.5 36.5T651-650l-74 32q-12-32-34-48t-60-16q-44 0-67 19.5T393-614q0 33 30 52t104 40q69 20 104.5 63.5T667-358q0 71-42 108t-104 46v84h-80Z',
    ];
    if (isset($material[$name])) return '<svg class="ui-icon" viewBox="0 -960 960 960" aria-hidden="true"><path fill="currentColor" stroke="none" d="' . $material[$name] . '"/></svg>';
    $icons = [
        'home' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9 21v-6h6v6"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'chart' => '<path d="M4 20V10"/><path d="M10 20V4"/><path d="M16 20v-7"/><path d="M22 20V7"/>',
        'message' => '<path d="M21 15a4 4 0 0 1-4 4H8l-5 3v-7a8 8 0 1 1 18 0Z"/>',
        'store' => '<path d="M3 9l2-5h14l2 5"/><path d="M5 13v7h14v-7"/><path d="M9 20v-6h6v6"/><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/>',
        'wallet' => '<path d="M3 7h15a3 3 0 0 1 3 3v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/><path d="M16 12h5v4h-5a2 2 0 0 1 0-4Z"/><path d="M5 7V5a2 2 0 0 1 2-2h10"/>',
        'code' => '<path d="m8 9-4 3 4 3"/><path d="m16 9 4 3-4 3"/><path d="m14 5-4 14"/>',
        'key' => '<circle cx="8" cy="15" r="4"/><path d="m11 12 9-9"/><path d="m17 6 3 3"/><path d="m15 8 2 2"/>',
        'logout' => '<path d="M10 17l5-5-5-5"/><path d="M15 12H3"/><path d="M14 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        'database' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
    ];
    return '<svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true">' . ($icons[$name] ?? '') . '</svg>';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#031B4B">
  <script>try{const t=localStorage.getItem('merdpos-theme');if(t==='dark'||t==='light'){document.documentElement.dataset.theme=t;document.querySelector('meta[name="theme-color"]').content='#031B4B';}}catch(_){}</script>
  <title>MERDPOS</title>
  <link rel="icon" href="assets/brand/merdpos-mark.png?v=20260827brand4" type="image/png">
  <link rel="stylesheet" href="assets/styles.css?v=20260826minimal1">
  <link rel="stylesheet" href="assets/modern.css?v=20260826minimal1">
  <link rel="stylesheet" href="assets/typography.css?v=20260826minimal1">
  <link rel="stylesheet" href="assets/table-ui.css?v=20260828mobile1">
  <link rel="stylesheet" href="assets/app-ui.css?v=20260828mobile1">
  <link rel="stylesheet" href="assets/brand/brand.css?v=20260828palette1">
</head>
<body class="merd-shell">
  <div id="shellAccountSources" hidden data-user-name="<?= htmlspecialchars((string)$user['name']) ?>" data-role-label="<?= htmlspecialchars($actualRoleLabel) ?>" data-role-key="<?= htmlspecialchars($actualRole) ?>" data-view-role-key="<?= htmlspecialchars((string)($viewRoleKey ?? '')) ?>">
    <?php if (!empty($actualPermissions['password.change_own'])): ?><button id="passwordBtn" type="button"><?= ui_icon('key') ?><span>Change password</span></button><?php endif; ?>
    <button id="logoutBtn" type="button"><?= ui_icon('logout') ?><span>Log out</span></button>
  </div>

  <main class="page-shell merd-page-shell">
    <nav class="portal-tabs merd-nav" aria-label="MERDPOS sections">
      <?php if ($canDashboard): ?>
      <div class="nav-group">
        <span class="nav-group-label">Overview</span>
        <button class="portal-tab<?= $initialPanel === 'dashboardPanel' ? ' active' : '' ?>" data-panel="dashboardPanel"><?= ui_icon('home') ?><span>Dashboard</span></button>
      </div>
      <?php endif; ?>

      <?php if ($hasWorkforceRaw): ?>
      <div class="nav-group">
        <span class="nav-group-label">Workforce</span>
        <?php if ($canWorkforce): ?><button class="portal-tab<?= $initialPanel === 'employeesPanel' ? ' active' : '' ?>" data-panel="employeesPanel"><?= ui_icon('users') ?><span>Employees</span></button><?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($hasReports): ?>
      <div class="nav-group">
        <span class="nav-group-label">Reports</span>
        <?php if ($canTimesheets): ?><button class="portal-tab<?= $initialPanel === 'timesheetPanel' ? ' active' : '' ?>" data-panel="timesheetPanel"><?= ui_icon('clock') ?><span>Timesheets</span><span id="timesheetBell" class="nav-badge" data-dispute-shortcut hidden>0</span></button><?php endif; ?>
        <?php if ($showDisputesNav): ?><button class="portal-tab<?= $initialPanel === 'disputesPanel' ? ' active' : '' ?>" data-panel="disputesPanel"><?= ui_icon('message') ?><span>Disputes</span></button><?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($hasOperations): ?>
      <div class="nav-group">
        <span class="nav-group-label">Operations</span>
        <?php if ($canStores): ?><button class="portal-tab<?= $initialPanel === 'storesPanel' ? ' active' : '' ?>" data-panel="storesPanel"><?= ui_icon('store') ?><span>Stores</span></button><?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($canFinance): ?>
      <div class="nav-group">
        <span class="nav-group-label">Finance</span>
        <button class="portal-tab<?= $initialPanel === 'financialPanel' ? ' active' : '' ?>" data-panel="financialPanel"><?= ui_icon('wallet') ?><span>Financial</span></button>
      </div>
      <?php endif; ?>

      <?php if ($canDevStatus): ?>
      <div class="nav-group">
        <span class="nav-group-label">System</span>
        <button class="portal-tab dev-tab<?= $initialPanel === 'devPanel' ? ' active' : '' ?>" data-panel="devPanel"><?= ui_icon('code') ?><span>DEV</span></button>
      </div>
      <?php endif; ?>
    </nav>

    <?php if ($canDashboard): ?>
    <section id="dashboardPanel" class="portal-panel"<?= $initialPanel === 'dashboardPanel' ? '' : ' hidden' ?>>
      <?php if ($isManagement): ?>
      <section class="management-hero">
        <article class="hero-panel merd-brand-path-accent">
          <div class="hero-kicker">Connected operations</div>
          <h1 class="hero-title">One view across MERDPOS</h1>
          <p class="hero-sub">Staff, stores, attendance and financial signals stay connected in one operational view ? helping teams respond faster and serve customers more consistently.</p>
          <div class="live-time"><span class="live-time-dot"></span><span id="liveClock">Live</span></div>
        </article>
        <article class="hero-panel hero-role">
          <div><div class="hero-kicker">Access level</div><strong><?= htmlspecialchars($roleLabel) ?> Â· LOA <?= $authorityLevel ?></strong></div>
          <small>Capabilities are granted by the current client permission policy.</small>
        </article>
      </section>

      <section id="managementKpis" class="mgmt-kpis">
        <article class="mgmt-kpi"><span class="kpi-icon">â—‰</span><div class="kpi-value">â€”</div><div class="kpi-label">Working now</div></article>
        <article class="mgmt-kpi alert"><span class="kpi-icon">â—‡</span><div class="kpi-value">â€”</div><div class="kpi-label">Pending disputes</div></article>
        <article class="mgmt-kpi"><span class="kpi-icon">â—«</span><div class="kpi-value">â€”</div><div class="kpi-label">Active employees</div></article>
        <article class="mgmt-kpi"><span class="kpi-icon">â†»</span><div class="kpi-value">â€”</div><div class="kpi-label">Sync attention</div></article>
      </section>

      <section class="mgmt-grid">
        <article class="mgmt-card"><div class="mgmt-card-head"><h2>Who is working now</h2><span>Live QR attendance</span></div><div id="workingNow"><div class="status-card">Loading attendanceâ€¦</div></div></article>
        <article class="mgmt-card"><div class="mgmt-card-head"><h2>Workforce by store</h2><span>Open shifts</span></div><div id="workforceChart" class="chart-bars"></div></article>
      </section>
      <section class="mgmt-grid">
        <article class="mgmt-card"><div class="mgmt-card-head"><h2>Todayâ€™s store cash position</h2><span>Register + Petty Cash</span></div><div id="storeFinanceChart" class="chart-bars"></div></article>
        <article class="mgmt-card"><div class="mgmt-card-head"><h2>Register vs Petty Cash</h2><span id="financeChartDate">Today</span></div><div id="financeRingRoot" class="finance-ring-wrap"></div></article>
      </section>
      <section class="controls-card"><div class="mgmt-card-head"><h2>Recent attendance</h2><span>Latest verified QR shifts</span></div><div id="recentShifts" class="table-scroll"></div></section>
      <?php else: ?>
      <section id="dashboardSummary" class="card-grid"><div class="status-card">Loading dashboardâ€¦</div></section>
      <section id="workingNow" class="card-grid"><div class="status-card">Loading attendanceâ€¦</div></section>
      <section class="controls-card"><h2>Recent activity</h2><div id="recentShifts" class="table-scroll"></div></section>
      <?php endif; ?>
      <button id="refreshBetaBtn" class="secondary-btn" type="button">Refresh live data</button>
      <?php if ($isManagement): ?><section id="dashboardSummary" hidden></section><?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($canWorkforce): ?>
    <section id="employeesPanel" class="portal-panel"<?= $initialPanel === 'employeesPanel' ? '' : ' hidden' ?>>
      <section class="directory-card directory-layout">
        <div class="directory-toolbar">
          <div><h2>Employees</h2><p>Accounts, store access, roles and authority. Pay details appear only when permitted.</p></div>
          <div class="directory-actions">
            <label class="search-box" aria-label="Search employees"><?= ui_icon('search') ?><input id="employeeSearch" type="search" placeholder="Search employees"></label>
            <?php if ($canWorkforceManage): ?><button id="addEmployeeBtn" class="primary-btn compact-btn" type="button"><?= ui_icon('plus') ?> Add employee</button><?php endif; ?>
          </div>
        </div>
        <div id="employeeDirectory" class="entity-list"><div class="entity-empty">Loading employeesâ€¦</div></div>
      </section>
    </section>
    <?php endif; ?>

    <?php if ($canTimesheets): ?>
    <section id="timesheetPanel" class="portal-panel"<?= $initialPanel === 'timesheetPanel' ? '' : ' hidden' ?>>
      <header class="timesheet-page-head app-panel-head">
        <div>
          <h2>Timesheets</h2>
          <p>Review weekly timesheet activity for the selected week.</p>
        </div>
      </header>
      <section class="controls-card timesheet-toolbar-card">
        <div class="timesheet-toolbar">
          <label class="timesheet-week-field" for="weekSelect"><span>Week</span><select id="weekSelect" aria-label="Select week"></select></label>
          <button id="downloadPdfBtn" class="secondary-btn compact-btn" type="button">Download PDF</button>
        </div>
        <p id="reportSubtitle" class="timesheet-period-note">Current calendar week loads by default.</p>
        <p class="sr-only" id="reportTitle">Weekly Timesheet</p>
      </section>
      <section id="statusBox" class="status-card">Loading timesheet...</section>
      <section id="reportContainer" class="timesheet-report" aria-live="polite"></section>
    </section>
    <?php endif; ?>
    <?php if ($canDisputes): ?>
    <section id="disputesPanel" class="portal-panel"<?= $initialPanel === 'disputesPanel' ? '' : ' hidden' ?>>
      <header class="app-panel-head disputes-page-head">
        <div><h2>Disputes</h2><p>Review and correct attendance issues within your permitted scope.</p></div>
      </header>
      <?php if ($canSubmitDisputes): ?>
      <section class="controls-card">
        <form id="disputeForm" class="form-grid">
          <label id="disputeShiftField">Shift<select name="shift_id" id="disputeShift"></select></label>
          <label>Issue<select name="dispute_type" id="disputeType"><option value="missing_out">Forgot to clock out</option><option value="wrong_in">Clock-in time is wrong</option><option value="wrong_out">Clock-out time is wrong</option><option value="delete_shift">Delete this shift</option><option value="new_shift">Add missing shift</option><option value="other">Other</option></select></label>
          <label id="proposedStoreField" hidden>Proposed store<select name="proposed_store_id" id="proposedStore"></select></label>
          <label id="requestedInField" hidden>Correct clock-in<input name="requested_clock_in" type="datetime-local"></label>
          <label id="requestedOutField">Correct clock-out<input name="requested_clock_out" type="datetime-local"></label>
          <label class="full-field">Explanation<textarea name="reason" minlength="5" maxlength="1000" placeholder="What needs correcting?" required></textarea></label>
          <button class="primary-btn compact-btn" type="submit">Send for approval</button>
        </form>
      </section>
      <?php endif; ?>
      <section class="controls-card"><div id="disputeList" class="table-scroll"></div></section>
      <?php if ($canResolveFlags): ?><section class="controls-card"><div class="app-panel-head"><h2>Attendance security flags</h2></div><div id="attendanceFlags" class="table-scroll"></div></section><?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($canStores): ?>
    <section id="storesPanel" class="portal-panel"<?= $initialPanel === 'storesPanel' ? '' : ' hidden' ?>>
      <section class="directory-card directory-layout">
        <div class="directory-toolbar">
          <div><h2>Stores</h2><p>Store identity, availability and operating timings.</p></div>
          <div class="directory-actions">
            <label class="search-box" aria-label="Search stores"><?= ui_icon('search') ?><input id="storeSearch" type="search" placeholder="Search stores"></label>
            <?php if ($canStoresManage): ?><button id="addStoreBtn" class="primary-btn compact-btn" type="button"><?= ui_icon('plus') ?> Add store</button><?php endif; ?>
          </div>
        </div>
        <div id="storeDirectory" class="entity-list"><div class="entity-empty">Loading storesâ€¦</div></div>
      </section>
    </section>
    <?php endif; ?>

    <?php if ($canFinance): ?>
    <section id="financialPanel" class="portal-panel"<?= $initialPanel === 'financialPanel' ? '' : ' hidden' ?>>
      <section class="controls-card">
        <div class="form-grid financial-picker">
          <label>Store<select id="financialStore" required></select></label>
          <label>Business date<input id="financialDate" type="date" required></label>
          <button id="refreshFinancial" class="secondary-btn compact-btn" type="button">Refresh balances</button>
          <span id="financialQueue" class="queue-pill">0 pending</span>
        </div>
        <p id="financialAccessNote" class="muted"></p>
      </section>
      <nav class="financial-tabs" aria-label="Financial sections">
        <button type="button" class="financial-tab active" data-finance-panel="cashStatement">Daily Cash</button>
        <?php if ($canFinanceSubmit): ?><button type="button" class="financial-tab" data-finance-panel="cashMovement">Cash In / Out</button><button type="button" class="financial-tab" data-finance-panel="cashClosing">Closing</button><?php endif; ?>
      </nav>
      <section id="cashStatement" class="controls-card financial-section">
        <div id="financialSummary"><p class="muted">Choose a store and date.</p></div>
        <?php if ($canOpenDay): ?>
        <form id="openDayForm" class="sub-form financial-open-form" hidden>
          <label>Register opening<input name="register_opening" type="number" min="0" step="0.01" required></label>
          <label>Petty Cash opening<input name="petty_cash_opening" type="number" min="0" step="0.01" required></label>
          <button class="primary-btn compact-btn" type="submit">Open day</button>
        </form>
        <?php endif; ?>
        <div id="financialEntries" class="table-scroll" hidden></div>
      </section>
      <?php if ($canFinanceSubmit): ?>
      <section id="cashMovement" class="controls-card financial-section" hidden>
        <form id="cashMovementForm" class="form-grid">
          <label>Action<select name="submission_type"><option value="cash_in">Cash IN</option><option value="cash_out">Cash OUT</option></select></label>
          <label>Account<select name="account" id="cashAccount"><option>Register</option><option>Petty Cash</option></select></label>
          <label>Available<strong id="cashAvailable" class="available-amount">$0.00</strong></label>
          <label>Reason / category<input name="head" maxlength="120" required></label>
          <label>Amount<input name="amount" type="number" min="0.01" step="0.01" required></label>
          <button class="primary-btn compact-btn" type="submit">Save movement</button>
        </form>
      </section>
      <section id="cashClosing" class="controls-card financial-section" hidden>
        <form id="closingForm" class="form-grid">
          <label>Counted Register total<input name="register_total" type="number" min="0" step="0.01" required></label>
          <label>Transfer to Petty Cash<input name="petty_cash_addin" type="number" min="0" step="0.01" value="0" required></label>
          <label class="full-field">Denomination counts (optional)<input name="denominations" placeholder="Comma-separated counts"></label>
          <button class="primary-btn compact-btn" type="submit">Close day</button>
        </form>
        <p class="muted">Closing is accepted once only and automatically opens the next business day.</p>
      </section>
      <?php endif; ?>
      <p id="financialStatus" class="financial-message muted"></p>
    </section>
    <?php endif; ?>

    <?php if ($canDevStatus): ?>
    <section id="devPanel" class="portal-panel"<?= $initialPanel === 'devPanel' ? '' : ' hidden' ?>>
      <section class="controls-card">
        <div class="mgmt-card-head"><h2><?= ui_icon('database') ?> DEV system inspector</h2><span>Read-only diagnostics</span></div>
        <div id="devStatus" class="dev-console"><div class="status-card">Loading system statusâ€¦</div></div>
        <p class="dev-note">DEV access intentionally provides a read-only SQL/database inspector rather than arbitrary browser SQL execution. Database changes remain migration-controlled and auditable.</p>
      </section>
      </section>
    </section>
    <?php endif; ?>
  </main>

  <dialog id="merdposAboutDialog" class="merd-about-dialog" aria-labelledby="merdposAboutTitle">
    <div class="merd-about-card">
      <section class="merd-about-copy">
        <img class="merd-about-logo" src="assets/brand/merdpos-logo-approved.png?v=20260827brand4" alt="MERDPOS - Smarter Faster Together">
        <h2 id="merdposAboutTitle" class="merd-about-title">Release information</h2>
        <div class="merd-about-release-grid">
          <div class="merd-about-release-row"><span>MERDPOS</span><strong>Git <?= htmlspecialchars($productVersion) ?></strong><small>Release date · <?= htmlspecialchars($productReleaseDate) ?></small></div>
          <div class="merd-about-release-row"><span>DevStudio</span><strong>Git <?= htmlspecialchars($devStudioVersion) ?></strong><small>Release date · <?= htmlspecialchars($devStudioReleaseDate) ?></small></div>
        </div>
        <section class="merd-about-highlights" aria-label="Release highlights"><h3>Release Highlights</h3><ul><?php foreach ($releaseHighlights as $highlight): ?><li><?= htmlspecialchars($highlight) ?></li><?php endforeach; ?></ul></section>
        <footer class="merd-about-foot"><strong>SMARTER <i>•</i> FASTER <i>•</i> TOGETHER</strong><span>Copyright © <?= date('Y') ?> All rights reserved</span></footer>
      </section>
      <section class="merd-about-art" aria-hidden="true">
        <span class="merd-about-shape shape-a"></span><span class="merd-about-shape shape-b"></span><span class="merd-about-shape shape-c"></span>
        <img src="assets/brand/M_Icon.svg?v=20260828about1" alt="">
      </section>
      <button id="merdposAboutClose" class="merd-about-close" type="button" aria-label="Close About MERDPOS">&times;</button>
    </div>
  </dialog>

  <?php if ($can('password.change_own')): ?>
  <dialog id="passwordDialog" class="portal-dialog">
    <form id="passwordForm" method="dialog">
      <div class="dialog-heading"><h2>Change password</h2><button type="button" id="passwordClose" class="icon-btn" aria-label="Close">Ã—</button></div>
      <p class="muted">Use 6â€“20 digits. Your password is stored securely.</p>
      <label>Current password<input name="current_password" type="password" inputmode="numeric" pattern="[0-9]*" required></label>
      <label>New password<input name="new_password" type="password" inputmode="numeric" pattern="[0-9]{6,20}" minlength="6" maxlength="20" required></label>
      <label>Confirm new password<input name="confirm_password" type="password" inputmode="numeric" pattern="[0-9]{6,20}" minlength="6" maxlength="20" required></label>
      <p id="passwordStatus" class="error-message" hidden></p>
      <button type="submit" class="primary-btn">Change password</button>
    </form>
  </dialog>
  <?php endif; ?>

  <?php if ($canWorkforceManage): ?>
  <dialog id="employeeDialog" class="portal-dialog admin-dialog">
    <form id="employeeAdminForm">
      <div class="admin-dialog-header"><h2 id="employeeDialogTitle">Add employee</h2><button type="button" class="icon-btn" data-close-dialog aria-label="Close">Ã—</button></div>
      <div class="admin-dialog-body">
        <p id="employeeSelfGuard" class="self-guard" hidden>Your own access level and active status are protected here to prevent accidental lockout.</p>
        <div class="admin-form-grid">
          <input type="hidden" name="id">
          <label>Full name<input name="full_name" maxlength="190" required></label>
          <label>User ID<input name="user_id" inputmode="numeric" pattern="[0-9]*" maxlength="32" required></label>
          <label>Store<select name="store_id" id="employeeStore" required></select></label>
          <label>Access level<select name="employee_type" id="employeeRole" required></select></label>
          <label<?= $canPayrates ? '' : ' hidden' ?>>Hourly rate<input name="hourly_rate" type="number" min="0" max="9999" step="0.01"<?= $canPayrates ? ' required' : '' ?>></label>
          <label>Status<select name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
          <label class="full-field">New / reset numeric password<input name="new_password" inputmode="numeric" pattern="[0-9]*" minlength="4" maxlength="20" autocomplete="new-password"><p id="employeePasswordHint" class="form-hint"></p></label>
        </div>
        <div class="admin-dialog-footer"><button type="button" class="secondary-btn" data-close-dialog>Cancel</button><button type="submit" class="primary-btn compact-btn">Save employee</button></div>
      </div>
    </form>
  </dialog>
  <?php endif; ?>

  <?php if ($canStoresManage): ?>
  <dialog id="storeDialog" class="portal-dialog admin-dialog">
    <form id="storeAdminForm">
      <div class="admin-dialog-header"><h2 id="storeDialogTitle">Add store</h2><button type="button" class="icon-btn" data-close-dialog aria-label="Close">Ã—</button></div>
      <div class="admin-dialog-body">
        <div class="admin-form-grid">
          <input type="hidden" name="id">
          <label class="full-field">Store name<input name="store_name" maxlength="150" required></label>
          <label>Week start day<select name="week_start_day" id="storeWeekStartDay"><option value="1">Monday</option><option value="2">Tuesday</option><option value="3">Wednesday</option><option value="4">Thursday</option><option value="5">Friday</option><option value="6">Saturday</option><option value="7">Sunday</option></select></label>
          <label>Status<select name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
        </div>
        <p class="form-hint">Stores are inactivated rather than deleted so historical attendance, payroll and financial records stay intact.</p>
        <div class="admin-dialog-footer"><button type="button" class="secondary-btn" data-close-dialog>Cancel</button><button type="submit" class="primary-btn compact-btn">Save store</button></div>
      </div>
    </form>
  </dialog>
  <?php endif; ?>

  <?php if ($canDirectory): ?><div id="directoryNotice" class="directory-notice" hidden></div><?php endif; ?>

  <script>
    window.MERDPOS_AUTH = <?= json_encode([
        'role_key'=>$role,
        'is_dev'=>$isDev,
        'role_label'=>$roleLabel,
        'authority_level'=>$authorityLevel,
        'client_role_id'=>$viewRoleId ?? ($user['client_role_id'] ?? null),
        'actual_role_key'=>$actualRole,
        'actual_role_label'=>$actualRoleLabel,
        'is_role_preview'=>$isRolePreview,
        'view_role_key'=>$viewRoleKey,
        'client_id'=>(int)$user['client_id'],
        'auth_client_id'=>(int)($user['auth_client_id'] ?? $user['client_id']),
        'permissions'=>$permissions,
        'permission_levels'=>$user['permission_levels'] ?? [],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  </script>
  <script src="assets/app.js?v=20260828timesheet3"></script>
  <script src="assets/beta.js?v=20260827visual1"></script>
  <script src="assets/management.js?v=20260831analytics2"></script>
  <?php if ($canDirectory): ?><script src="assets/directory.js?v=20260831stores1"></script><?php endif; ?>
</body>
</html>




