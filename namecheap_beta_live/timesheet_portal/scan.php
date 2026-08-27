<?php
require_once __DIR__ . '/includes/auth.php';
start_app_session();
if (isset($_GET['q']) && is_string($_GET['q']) && strlen($_GET['q']) <= 1400) $_SESSION['pending_qr'] = $_GET['q'];
$user = current_user();
if (!$user) { header('Location: index.php'); exit; }
$token = (string)($_SESSION['pending_qr'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>MERDPOS Attendance</title>
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
    <section class="login-card scan-card merd-login-card" aria-live="polite" aria-labelledby="attendanceTitle">
      <div class="merd-logo-lockup merd-brand merd-brand--compact" aria-label="MERDPOS">
        <img class="merd-brand__mark" src="assets/brand/merdpos-mark.svg" alt="" aria-hidden="true">
        <div class="merd-brand__copy">
          <strong class="merd-brand__wordmark">MERD<span class="merd-brand__pos">POS</span></strong>
        </div>
      </div>
      <h1 id="attendanceTitle">Attendance</h1>
      <p id="scanStatus" class="muted" aria-live="polite">Validating the authorised POS QR…</p>
      <div id="scanReceipt" hidden></div>
      <a class="secondary-link" href="dashboard.php">Open workspace</a>
    </section>
  </main>
  <script>
    const token=<?= json_encode($token, JSON_UNESCAPED_SLASHES) ?>,csrf=<?= json_encode(csrf_token()) ?>;
    (async()=>{
      const status=document.getElementById('scanStatus'),receipt=document.getElementById('scanReceipt');
      try{
        if(!token)throw new Error('No QR is waiting. Scan the current POS QR.');
        const response=await fetch('api/attendance_scan.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({token,csrf})});
        const data=await response.json();
        if(!data.success)throw new Error(data.error||'Scan failed');
        const result=data.result;
        status.textContent=result.duplicate?'This QR was already processed.':(result.action==='IN'?'You are clocked in.':'You are clocked out.');
        receipt.hidden=false;
        receipt.innerHTML=`<div class="scan-result ${result.action==='IN'?'in':'out'}"><strong>${result.action}</strong><span>${escapeHtml(result.store_name)}</span><small>${escapeHtml(result.occurred_at)} UTC</small></div>`;
      }catch(error){status.textContent=error.message;status.classList.add('error-message');status.setAttribute('role','alert');}
    })();
    function escapeHtml(value){return String(value??'').replace(/[&<>'"]/g,character=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[character]));}
  </script>
</body>
</html>
