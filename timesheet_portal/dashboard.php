<?php
require_once __DIR__ . '/includes/auth.php';
$user = current_user();
if (!$user) {
    header('Location: index.php');
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Weekly Timesheet</title>
  <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
  <header class="topbar">
    <div>
      <div class="app-title">Timesheet Portal</div>
      <div class="user-line">Signed in as <strong><?= htmlspecialchars($user['name']) ?></strong><?= $user['is_super'] ? ' <span class="role-pill">SUPER</span>' : '' ?></div>
    </div>
    <div class="topbar-actions"><button id="passwordBtn" class="ghost-btn">Change password</button><button id="logoutBtn" class="ghost-btn">Log Out</button></div>
  </header>

  <main class="page-shell">
    <nav class="portal-tabs" aria-label="Portal sections">
      <button class="portal-tab active" data-panel="attendancePanel">Attendance</button>
      <button class="portal-tab" data-panel="timesheetPanel">Timesheet</button>
      <button class="portal-tab" data-panel="disputesPanel">Disputes</button>
      <button class="portal-tab" data-panel="financialPanel">Financials</button>
    </nav>

    <section id="attendancePanel" class="portal-panel">
      <div class="panel-heading"><div><h1>Working now</h1><p class="muted">Live from QR attendance. Refreshes automatically.</p></div><button id="refreshBetaBtn" class="secondary-btn">Refresh</button></div>
      <section id="workingNow" class="card-grid"><div class="status-card">Loading attendance…</div></section>
      <section class="controls-card"><h2>Recent QR shifts</h2><div id="recentShifts" class="table-scroll"></div></section>
    </section>

    <section id="timesheetPanel" class="portal-panel" hidden>
    <section class="controls-card timesheet-header-card">
      <div class="week-picker-row">
        <label for="weekSelect" class="week-picker-label">Please Select Week:</label>
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
      <div class="panel-heading"><div><h1>Fix a shift</h1><p class="muted">Nothing changes until a SUPER user approves it.</p></div></div>
      <section class="controls-card">
        <form id="disputeForm" class="form-grid">
          <label id="disputeShiftField">Which shift?<select name="shift_id" id="disputeShift"></select></label>
          <label>What needs fixing?<select name="dispute_type" id="disputeType"><option value="missing_out">I forgot to clock out</option><option value="wrong_in">Clock-in time is wrong</option><option value="wrong_out">Clock-out time is wrong</option><option value="delete_shift">This shift should be deleted</option><option value="new_shift">A shift is missing — add it</option><option value="other">Something else</option></select></label>
          <label id="proposedStoreField" hidden>Proposed store<select name="proposed_store_id" id="proposedStore"></select></label>
          <label id="requestedInField" hidden>Correct clock-in<input name="requested_clock_in" type="datetime-local"></label>
          <label id="requestedOutField">Correct clock-out<input name="requested_clock_out" type="datetime-local"></label>
          <label class="full-field">Short explanation<textarea name="reason" minlength="5" maxlength="1000" placeholder="Tell the manager what happened" required></textarea></label>
          <button class="primary-btn compact-btn" type="submit">Send for approval</button>
        </form>
      </section>
      <section class="controls-card"><div id="disputeList" class="table-scroll"></div></section>
      <?php if ($user['is_super']): ?><section class="controls-card"><h2>Suspended attendance accounts</h2><div id="attendanceFlags" class="table-scroll"></div></section><?php endif; ?>
    </section>

    <section id="financialPanel" class="portal-panel" hidden>
      <div class="panel-heading"><div><h1>Store financials</h1><p class="muted">Saved on this phone while offline; cleared only after a server receipt.</p></div><span id="financialQueue" class="queue-pill">0 pending</span></div>
      <section class="controls-card">
        <div class="form-grid financial-picker">
          <label>Store<select id="financialStore" required></select></label>
          <label>Business date<input id="financialDate" type="date" required></label>
          <button id="refreshFinancial" class="secondary-btn compact-btn" type="button">Refresh balances</button>
        </div>
      </section>
      <nav class="financial-tabs" aria-label="Financial sections">
        <button type="button" class="financial-tab active" data-finance-panel="cashStatement">Daily Cash Statement</button>
        <button type="button" class="financial-tab" data-finance-panel="cashMovement">Cash In / Out</button>
        <button type="button" class="financial-tab" data-finance-panel="cashClosing">Daily Cash Closing</button>
      </nav>
      <section id="cashStatement" class="controls-card financial-section">
        <div id="financialSummary"><p class="muted">Choose a store and date.</p></div>
        <?php if ($user['is_super']): ?>
        <form id="openDayForm" class="sub-form financial-open-form" hidden>
          <label>Register opening<input name="register_opening" type="number" min="0" step="0.01" required></label>
          <label>Petty Cash opening<input name="petty_cash_opening" type="number" min="0" step="0.01" required></label>
          <button class="primary-btn compact-btn" type="submit">Open this day</button>
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
          <button class="primary-btn compact-btn" type="submit">Save Cash IN / OUT</button>
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
  </main>

  <dialog id="passwordDialog" class="portal-dialog">
    <form id="passwordForm" method="dialog">
      <div class="dialog-heading"><h2>Change password</h2><button type="button" id="passwordClose" class="icon-btn" aria-label="Close">×</button></div>
      <p class="muted">Use 6–20 digits. Your new password is stored securely.</p>
      <label>Current password<input name="current_password" type="password" inputmode="numeric" pattern="[0-9]*" required></label>
      <label>New password<input name="new_password" type="password" inputmode="numeric" pattern="[0-9]{6,20}" minlength="6" maxlength="20" required></label>
      <label>Confirm new password<input name="confirm_password" type="password" inputmode="numeric" pattern="[0-9]{6,20}" minlength="6" maxlength="20" required></label>
      <p id="passwordStatus" class="error-message" hidden></p>
      <button type="submit" class="primary-btn">Change password</button>
    </form>
  </dialog>

  <script src="assets/app.js"></script>
  <script src="assets/beta.js"></script>
</body>
</html>
