<?php
require_once __DIR__ . '/includes/auth.php';
$user = current_user();
if (!$user) {
    header('Location: index.php');
    exit;
}
start_app_session();

$stores = [
    'MX' => ['name' => 'Marrickville Xpress', 'ceiling' => 'Y'],
    'ET' => ['name' => 'Enmore Tobacco', 'ceiling' => 'Z'],
    'DT' => ['name' => 'Double Bay Tobacco', 'ceiling' => 'AA'],
    'RT' => ['name' => 'Rosebay Tobacco', 'ceiling' => 'AB'],
];

if (!isset($_SESSION['order_store_csrf']) || !is_string($_SESSION['order_store_csrf'])) {
    $_SESSION['order_store_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    $storeCode = $_POST['store_code'] ?? null;
    if (!is_string($token) || !hash_equals($_SESSION['order_store_csrf'], $token)) {
        http_response_code(403);
        $error = 'Security check failed. Refresh the page and try again.';
    } elseif (!is_string($storeCode) || !array_key_exists($storeCode, $stores)) {
        http_response_code(400);
        $error = 'Select a valid store.';
    } else {
        // Session-only order draft context: no sheets, stock or orders are written yet.
        $_SESSION['order_store_code'] = $storeCode;
        header('Location: orders.php?selected=1', true, 303);
        exit;
    }
}

$selectedStore = $_SESSION['order_store_code'] ?? null;
if (!is_string($selectedStore) || !array_key_exists($selectedStore, $stores)) {
    $selectedStore = null;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>MERDPOS Portal (Alpha) — Cigarette Orders</title>
  <link rel="stylesheet" href="assets/styles.css?v=20261028alpha2">
</head>
<body>
  <header class="topbar">
    <div>
      <div class="app-title">MERDPOS Portal (Alpha)</div>
      <div class="user-line">Signed in as <strong><?= htmlspecialchars((string)$user['name'], ENT_QUOTES, 'UTF-8') ?></strong><?= !empty($user['is_super']) ? ' <span class="role-pill">SUPER</span>' : '' ?></div>
    </div>
    <nav class="portal-nav" aria-label="Portal sections">
      <a href="dashboard.php">Timesheets</a>
      <a href="orders.php" aria-current="page">Cigarette Orders</a>
    </nav>
    <button id="logoutBtn" class="ghost-btn" type="button" data-logout-csrf="<?= htmlspecialchars(portal_logout_csrf(), ENT_QUOTES, 'UTF-8') ?>">Log Out</button>
  </header>

  <main class="page-shell order-page">
    <section class="orders-intro">
      <p class="orders-eyebrow">Cigarette Orders · Alpha counting preview</p>
      <h1><?= $selectedStore ? "Count stock &amp; accessories" : "Choose a store" ?></h1>
      <p>Choose a store, scan stock and review the ceiling-based quantities. This is a <strong>draft only</strong>: nothing is ordered or saved to Google Drive yet.</p>
    </section>

    <?php if ($error !== ''): ?>
      <p class="status-card error-card" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <p id="logoutError" class="status-card error-card" role="alert" hidden></p>

    <section class="report-card" aria-labelledby="storeSelectionHeading">
      <h2 id="storeSelectionHeading" class="orders-section-heading">Select a store</h2>
      <form method="post" action="orders.php" class="store-selection-grid">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['order_store_csrf'], ENT_QUOTES, 'UTF-8') ?>">
        <?php foreach ($stores as $code => $store): ?>
          <button type="submit" name="store_code" value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>" class="store-choice<?= $selectedStore === $code ? ' is-selected' : '' ?>" aria-pressed="<?= $selectedStore === $code ? 'true' : 'false' ?>">
            <span class="store-code"><?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="store-name"><?= htmlspecialchars($store['name'], ENT_QUOTES, 'UTF-8') ?></span>
            <span class="store-action"><?= $selectedStore === $code ? 'Selected ✓' : 'Select store' ?></span>
          </button>
        <?php endforeach; ?>
      </form>
    </section>

    <section class="report-card order-progress" aria-live="polite">
      <h2 class="orders-section-heading">Order progress</h2>
      <?php if ($selectedStore !== null): ?>
        <p class="order-selected">Selected store: <strong><?= htmlspecialchars($stores[$selectedStore]['name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($selectedStore, ENT_QUOTES, 'UTF-8') ?>)</strong></p>
      <?php else: ?>
        <p class="order-selected">No store selected yet.</p>
      <?php endif; ?>
      <ol class="order-steps">
        <li><strong>Select store</strong> <span><?= $selectedStore !== null ? 'Completed' : 'Current step' ?></span></li>
        <li><strong>Scan cigarette stock</strong> <span><?= $selectedStore ? "Ready" : "Select store first" ?></span></li>
        <li><strong>Add tobacco accessories</strong> <span><?= $selectedStore ? "Ready" : "Select store first" ?></span></li>
        <li><strong>Review, generate and save order</strong> <span>Coming next</span></li>
      </ol>
      <p class="orders-note">Draft counts are held only in your login session, separately by store. No sales retrieval, inventory write, PDF or Drive submission is performed.</p>
    </section>

    <?php if ($selectedStore !== null): ?>
    <p id="countLoading" class="order-notice">Loading Inventory DBA catalogue…</p>
    <section id="countWorkspace" class="order-workspace" data-csrf="<?= htmlspecialchars($_SESSION['order_store_csrf'], ENT_QUOTES, 'UTF-8') ?>" hidden>
      <div class="order-metrics" aria-label="Order calculation summary">
        <div class="order-metric"><span>Cigarettes counted</span><strong id="countedProducts">0</strong></div>
        <div class="order-metric"><span>Cigarette units to order</span><strong id="orderTotal">0</strong></div>
        <div class="order-metric"><span>Accessory units requested</span><strong id="accessoryTotal">0</strong></div>
      </div>
      <p id="ceilingProblems" class="orders-note"></p>
      <section class="report-card" aria-labelledby="countHead">
        <div class="order-tabs" role="group" aria-label="Count type">
          <button id="typeAuto" type="button" aria-pressed="true">1. Cigarette physical stock</button>
          <button id="typeManual" type="button" aria-pressed="false">2. Accessories to order</button>
        </div>
        <h2 id="countHead" class="orders-section-heading">Cigarette physical stock</h2>
        <p id="countHelp" class="muted"></p>
        <div id="countNotice" class="order-notice" role="status" aria-live="polite" hidden></div>
        <div class="order-inputs">
          <div>
            <label for="barcodeEntry">Barcode (manual scanner or typed)</label>
            <form id="barcodeForm" class="order-barcode-form">
              <input id="barcodeEntry" type="text" autocomplete="off" inputmode="numeric" placeholder="Scan barcode, press Enter" aria-label="Barcode">
              <button type="submit" class="secondary-btn">Add scan</button>
            </form>
          </div>
          <div>
            <label for="productSearch">Or select from the standard product list</label>
            <input id="productSearch" type="search" autocomplete="off" placeholder="Search product name">
            <div id="productSuggestions" class="order-suggestions" role="listbox" aria-label="Matching products" hidden></div>
          </div>
        </div>
        <div class="order-camera-actions">
          <button type="button" id="startCamera" class="secondary-btn">Scan with camera</button>
          <span>Mobile browser requires camera permission and HTTPS.</span>
        </div>
        <div id="cameraPanel" class="order-camera" hidden>
          <div id="cameraReader"></div>
          <button id="stopCamera" class="ghost-btn" type="button">Close camera</button>
        </div>
        <div class="order-list-controls">
          <label><input id="showAllCigarettes" type="checkbox"> Show all eligible cigarettes, including unscanned (0 stock)</label>
          <p>Tap a quantity to correct it. Counts and orders are in individual units.</p>
        </div>
        <h3 id="countedLabel">Counted cigarettes</h3>
        <div class="order-table-wrap">
          <table id="countTable" class="order-count-table" hidden>
            <thead><tr><th>Product</th><th id="countCeilingHeading">Ceiling</th><th id="countQtyLabel">On hand</th><th id="countOrderHeading">To order</th><th>Actions</th></tr></thead>
            <tbody id="countedBody"></tbody>
          </table>
          <p id="emptyCounts" class="order-empty">Nothing counted yet. Scan a barcode or select a product above.</p>
        </div>
      </section>
      <section class="report-card">
        <h2 class="orders-section-heading">Draft only — no order submitted</h2>
        <p>Physical counts use your selected store's ceilings from Inventory DBA. Products not scanned are treated as zero. Accessories are separate requested quantities. Google Sheets/PDF creation and SUPER submission status are the next release.</p>
      </section>
    </section>
    <?php endif; ?>
  </main>

  <?php if ($selectedStore !== null): ?>
    <script src="assets/vendor/html5-qrcode.min.js?v=2.3.8"></script>
    <script src="assets/orders.js?v=20261028alpha2" defer></script>
  <?php endif; ?>

  <script>
    document.getElementById('logoutBtn').addEventListener('click', async () => {
      const button = document.getElementById('logoutBtn');
      const error = document.getElementById('logoutError');
      button.disabled = true;
      error.hidden = true;
      try {
        const response = await fetch('api/logout.php', {
          method: 'POST',
          credentials: 'same-origin',
          body: new URLSearchParams({ csrf_token: button.dataset.logoutCsrf || '' })
        });
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.error || 'Logout failed');
        window.location.href = 'index.php';
      } catch (_) {
        error.textContent = 'Could not sign out. Please retry.';
        error.hidden = false;
        button.disabled = false;
      }
    });
  </script>
</body>
</html>
