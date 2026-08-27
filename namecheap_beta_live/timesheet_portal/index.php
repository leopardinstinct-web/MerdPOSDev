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
  <title>MERDPOS Login</title>
  <link rel="icon" href="assets/brand/merdpos-mark.svg" type="image/svg+xml">
  <link rel="stylesheet" href="assets/design-tokens.css?v=20260827brand2">
  <link rel="stylesheet" href="assets/styles.css?v=20260826ds1">
  <link rel="stylesheet" href="assets/modern.css?v=20260826ds1">
  <link rel="stylesheet" href="assets/typography.css?v=20260826ds1">
  <link rel="stylesheet" href="assets/app-ui.css?v=20260826ds1">
  <link rel="stylesheet" href="assets/brand/brand.css?v=20260827brand2">
  <link rel="stylesheet" href="assets/design-system.css?v=20260827brand2">
</head>
<body class="login-body merd-login-body merd-shell">
  <main class="login-shell">
    <section class="login-card merd-login-card" aria-labelledby="loginTitle">
      <div class="merd-logo-lockup merd-brand merd-brand--primary" aria-label="MERDPOS — Smarter, Faster, Together">
        <img class="merd-brand__mark" src="assets/brand/merdpos-mark.svg" alt="" aria-hidden="true">
        <div class="merd-brand__copy">
          <strong class="merd-brand__wordmark">MERD<span class="merd-brand__pos">POS</span></strong>
          <small class="merd-brand__tagline">Smarter<span class="merd-brand__dot">•</span>Faster<span class="merd-brand__dot">•</span>Together</small>
        </div>
      </div>
      <h1 id="loginTitle"><?= isset($_SESSION['pending_qr']) ? 'Complete attendance.' : 'Welcome back.' ?></h1>
      <p class="muted"><?= isset($_SESSION['pending_qr']) ? 'Sign in once to securely complete the POS QR attendance scan.' : 'Sign in to your MERDPOS workspace.' ?></p>

      <form id="loginForm" autocomplete="off" aria-describedby="loginError">
        <label for="user_id">User ID</label>
        <input id="user_id" name="user_id" inputmode="numeric" pattern="[0-9]*" type="text" placeholder="Numeric User ID" autocomplete="username" required autofocus>

        <label for="password">Password</label>
        <input id="password" name="password" inputmode="numeric" pattern="[0-9]*" type="password" placeholder="Numeric Password" autocomplete="current-password" required>

        <button id="loginSubmit" type="submit" class="primary-btn"><span>Enter MERDPOS</span></button>
        <p id="loginError" class="error-message" role="alert" aria-live="assertive" hidden></p>
      </form>
    </section>
  </main>

  <script>
    document.getElementById('loginForm').addEventListener('submit', async (event) => {
      event.preventDefault();
      const error = document.getElementById('loginError');
      const button = document.getElementById('loginSubmit');
      const label = button.querySelector('span');
      error.hidden = true;
      button.disabled = true;
      button.setAttribute('aria-busy', 'true');
      label.textContent = 'Signing in…';
      const formData = new FormData(event.target);
      try {
        const response = await fetch('api/login.php', { method: 'POST', body: formData });
        const data = await response.json();
        if (!data.success) throw new Error(data.error || 'Login failed');
        label.textContent = 'Opening workspace…';
        window.location.href = data.next || 'dashboard.php';
      } catch (errorValue) {
        error.textContent = errorValue.message;
        error.hidden = false;
        label.textContent = 'Enter MERDPOS';
        button.disabled = false;
        button.removeAttribute('aria-busy');
      }
    });
  </script>
</body>
</html>
