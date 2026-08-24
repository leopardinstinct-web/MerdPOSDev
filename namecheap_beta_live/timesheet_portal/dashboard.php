<?php
require_once __DIR__ . '/includes/auth.php';
$user = current_user();
if (!$user) {
    header('Location: index.php');
    exit;
}
$role = strtoupper((string)($user['role'] ?? $user['actual_employee_type'] ?? $user['employee_type'] ?? 'USER'));
$isManagement = !empty($user['is_super']);
$isDev = $role === 'DEV';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#F4F7FB">
  <title>MERDPOS</title>
  <link rel="stylesheet" href="assets/styles.css">
  <link rel="stylesheet" href="assets/modern.css">
  <link rel="stylesheet" href="assets/typography.css">
</head>
<body class="merd-shell">
  <header class="topbar merd-topbar">
    <div class="merd-logo-lockup">
      <div class="merd-logo-mark">M</div>
      <div>
        <strong>MERD<span>POS</span></strong>
        <small>FUTURE-READY RETAIL</small>
      </div>
    </div>
    <div class="topbar-actions">
      <div class="user-line">Signed in as <strong><?= htmlspecialchars($user['name']) ?></strong><span class="merd-role-pill"><?= htmlspecialchars($role) ?></span></div>
      <button id="passwordBtn" class="ghost-btn">Password</button>
      <button id="logoutBtn" class="ghost-btn">Log out</button>
    </div>
  </header>

  <main class="page-shell merd-page-shell">
    <nav class="portal-tabs merd-nav" aria-label="MERDPOS sections">
      <button class="portal-tab active" data-panel="dashboardPanel"><span class="nav-icon">⌂</span>Dashboard</button>
      <button class="portal-tab" data-panel="timesheetPanel"><span class="nav-icon">◷</span>Timesheets <span id="timesheetBell" class="nav-badge" data-dispute-shortcut hidden>0</span></button>
      <button class="portal-tab" data-panel="disputesPanel"><span class="nav-icon">◇</span>HR / Disputes</button>
      <button class="portal-tab" data-panel="financialPanel"><span class="nav-icon">◒</span>Financial</button>
      <?php if ($isDev): ?><span class="nav-spacer"></span><button class="portal-tab dev-tab" data-panel="devPanel"><span class="nav-icon">⌘</span>DEV</button><?php endif; ?>
    </nav>

    <section id="dashboardPanel" class="portal-panel">
      <?php if ($isManagement): ?>
      <section class="management-hero">
        <article class="hero-panel">
          <div class="hero-kicker">Management command centre</div>
          <h1 class="hero-title">Today across MERDPOS</h1>
          <p class="hero-sub">Live workforce, attendance exceptions, financial position and store activity in one operational view.</p>
          <div class="live-time"><span class="live-time-dot"></span><span id="liveClock">Live</span></div>
        </article>
        <article class="hero-panel hero-role">
          <div><div class="hero-kicker">Access level</div><strong><?= htmlspecialchars($role) ?></strong></div>
          <small><?= $isDev ? 'System diagnostics and management access enabled.' : 'Management operations and approvals enabled.' ?></small>
        </article>
      </section>

      <section id="managementKpis" class="mgmt-kpis">
        <article class="mgmt-kpi"><span class="kpi-icon">◉</span><div class="kpi-value">—</div><div class="kpi-label">Working now</div></article>
        <article class="mgmt-kpi alert"><span class="kpi-icon">◇</span><div class="kpi-value">—</div><div class="kpi-label">Pending disputes</div></article>
        <article class="mgmt-kpi"><span class="kpi-icon">◫</span><div class="kpi-value">—</div><div class="kpi-label">Active employees</div></article>
        <article class="mgmt-kpi"><span class="kpi-icon">↻</span><div class="kpi-value">—</div><div class="kpi-label">Sync attention</div></article>
      </section>

      <section class="mgmt-grid">
        <article class="mgmt-card">
          <div class="mgmt-card-head"><h2>Who is working now</h2><span>Live QR attendance</span></div>
          <div id="workingNow"><div class="status-card">Loading attendance…</div></div>
        </article>
        <article class="mgmt-card">
          <div class="mgmt-card-head"><h2>Workforce by store</h2><span>Open shifts</span></div>
          <div id="workforceChart" class="chart-bars"></div>
        </article>
      </section>

      <section class="mgmt-grid">
        <article class="mgmt-card">
          <div class="mgmt-card-head"><h2>Today’s store cash position</h2><span>Register + Petty Cash</span></div>
          <div id="storeFinanceChart" class="chart-bars"></div>
        </article>
        <article class="mgmt-card">
          <div class="mgmt-card-head"><h2>Register vs Petty Cash</h2><span id="financeChartDate">Today</span></div>
          <div id="financeRingRoot" class="finance-ring-wrap"></div>
        </article>
      </section>

      <section class="controls-card">
        <div class="mgmt-card-head"><h2>Recent attendance</h2><span>Latest verified QR shifts</span></div>
        <div id="recentShifts" class="table-scroll"></div>
      </section>
      <?php else: ?>
      <section id="dashboardSummary" class="card-grid"><div class="status-card">Loading dashboard…</div></section>
      <section id="workingNow" class="card-grid"><div class="status-card">Loading attendance…</div></section>
      <section class="controls-card"><h2>Recent activity</h2><div id="recentShifts" class="table-scroll"></div></section>
      <?php endif; ?>
      <button id="refreshBetaBtn" class="secondary-btn" type="button">Refresh live data</button>
      <?php if ($isManagement): ?><section id="dashboardSummary" hidden></section><?php endif; ?>
    </section>

    <section id="timesheetPanel" class="portal-panel" hidden>
      <section class="controls-card timesheet-header-card">
        <div class="week-picker-row">
          <label for="weekSelect" class="week-picker-label">Week</label>
          <select id="weekSelect" aria-label="Select week"></select>
          <button id="downloadPdfBtn" class="download-pdf-btn" type="button">Download PDF</button>
        </div>
        <p class="sr-only" id="reportTitle">Weekly Timesheet</p>
        <p class="sr-only" id="reportSubtitle">Current calendar week loads by default.</p>
      </section>
      <section id="statusBox" class="status-card">Loading...</section>
      <section id="reportContainer"></section>
    </section>

    <section id="disputesPanel" class="portal-panel" hidden>
      <?php if (!$isManagement): ?>
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
      <?php if ($isManagement): ?><section class="controls-card"><h2>Attendance security flags</h2><div id="attendanceFlags" class="table-scroll"></div></section><?php endif; ?>
    </section>

    <section id="financialPanel" class="portal-panel" hidden>
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
        <button type="button" class="financial-tab" data-finance-panel="cashMovement">Cash In / Out</button>
        <button type="button" class="financial-tab" data-finance-panel="cashClosing">Closing</button>
      </nav>
      <section id="cashStatement" class="controls-card financial-section">
        <div id="financialSummary"><p class="muted">Choose a store and date.</p></div>
        <?php if ($isManagement): ?>
        <form id="openDayForm" class="sub-form financial-open-form" hidden>
          <label>Register opening<input name="register_opening" type="number" min="0" step="0.01" required></label>
          <label>Petty Cash opening<input name="petty_cash_opening" type="number" min="0" step="0.01" required></label>
          <button class="primary-btn compact-btn" type="submit">Open day</button>
        </form>
        <?php endif; ?>
        <div id="financialEntries" class="table-scroll" hidden></div>
      </section>
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
      <p id="financialStatus" class="financial-message muted"></p>
    </section>

    <?php if ($isDev): ?>
    <section id="devPanel" class="portal-panel" hidden>
      <section class="controls-card">
        <div class="mgmt-card-head"><h2>DEV system inspector</h2><span>Read-only diagnostics</span></div>
        <div id="devStatus" class="dev-console"><div class="status-card">Loading system status…</div></div>
        <p class="dev-note">DEV access intentionally provides a read-only SQL/database inspector rather than arbitrary browser SQL execution. Database changes remain migration-controlled and auditable.</p>
      </section>
    </section>
    <?php endif; ?>
  </main>

  <dialog id="passwordDialog" class="portal-dialog">
    <form id="passwordForm" method="dialog">
      <div class="dialog-heading"><h2>Change password</h2><button type="button" id="passwordClose" class="icon-btn" aria-label="Close">×</button></div>
      <p class="muted">Use 6–20 digits. Your password is stored securely.</p>
      <label>Current password<input name="current_password" type="password" inputmode="numeric" pattern="[0-9]*" required></label>
      <label>New password<input name="new_password" type="password" inputmode="numeric" pattern="[0-9]{6,20}" minlength="6" maxlength="20" required></label>
      <label>Confirm new password<input name="confirm_password" type="password" inputmode="numeric" pattern="[0-9]{6,20}" minlength="6" maxlength="20" required></label>
      <p id="passwordStatus" class="error-message" hidden></p>
      <button type="submit" class="primary-btn">Change password</button>
    </form>
  </dialog>

  <script src="assets/app.js"></script>
  <script src="assets/beta.js"></script>
  <script src="assets/management.js"></script>
</body>
</html>
