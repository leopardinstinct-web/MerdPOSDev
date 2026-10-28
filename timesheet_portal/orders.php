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
  <link rel="stylesheet" href="assets/styles.css?v=20261028alpha1">
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
    <button id="logoutBtn" class="ghost-btn" type="button">Log Out</button>
  </header>

  <main class="page-shell order-page">
    <section class="orders-intro">
      <p class="orders-eyebrow">Cigarette Orders · Step 1</p>
      <h1>Choose a store</h1>
      <p>Select any store to begin its stock-counting and ordering workflow. Your selection is retained for this login session.</p>
    </section>

    <?php if ($error !== ''): ?>
      <p class="status-card error-card" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

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
        <li><strong>Scan cigarette stock</strong> <span>Coming next</span></li>
        <li><strong>Add tobacco accessories</strong> <span>Coming next</span></li>
        <li><strong>Review, generate and save order</strong> <span>Coming next</span></li>
      </ol>
      <p class="orders-note">This first milestone only saves your store selection in the login session. It does not yet create an order, change the Inventory DBA sheet or save files to Google Drive.</p>
    </section>
  </main>

  <script>
    document.getElementById('logoutBtn').addEventListener('click', async () => {
      try { await fetch('api/logout.php', { credentials: 'same-origin' }); } catch (_) {}
      window.location.href = 'index.php';
    });
  </script>
</body>
</html>
