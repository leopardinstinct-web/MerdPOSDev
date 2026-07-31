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
    <button id="logoutBtn" class="ghost-btn">Log Out</button>
  </header>

  <main class="page-shell">
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
  </main>

  <script src="assets/app.js"></script>
</body>
</html>
