<?php
require_once __DIR__ . '/includes/auth.php';
if (current_user()) {
    start_app_session();
    header('Location: ' . (isset($_SESSION['pending_qr']) ? 'scan.php' : 'dashboard.php'));
    exit;
}
start_app_session();
if (isset($_GET['q']) && is_string($_GET['q']) && strlen($_GET['q']) <= 1400) {
    $_SESSION['pending_qr'] = $_GET['q'];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Timesheet Login</title>
  <link rel="stylesheet" href="assets/styles.css">
</head>
<body class="login-body">
  <main class="login-shell">
    <section class="login-card">
      <div class="brand-mark">TS</div>
      <h1>Timesheet Portal</h1>
      <p class="muted"><?= isset($_SESSION['pending_qr']) ? 'Log in to complete your POS attendance scan.' : 'Timesheets, attendance, disputes and store financials.' ?></p>

      <form id="loginForm" autocomplete="off">
        <label for="user_id">User ID</label>
        <input id="user_id" name="user_id" inputmode="numeric" pattern="[0-9]*" type="text" placeholder="Numeric User ID" required autofocus>

        <label for="password">Password</label>
        <input id="password" name="password" inputmode="numeric" pattern="[0-9]*" type="password" placeholder="Numeric Password" required>

        <button type="submit" class="primary-btn">Log In</button>
        <p id="loginError" class="error-message" hidden></p>
      </form>
    </section>
  </main>

  <script>
    document.getElementById('loginForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const error = document.getElementById('loginError');
      error.hidden = true;
      const formData = new FormData(e.target);
      try {
        const res = await fetch('api/login.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Login failed');
        window.location.href = data.next || 'dashboard.php';
      } catch (err) {
        error.textContent = err.message;
        error.hidden = false;
      }
    });
  </script>
</body>
</html>
