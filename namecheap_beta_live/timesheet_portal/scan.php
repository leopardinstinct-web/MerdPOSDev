<?php
require_once __DIR__ . '/includes/auth.php';
start_app_session();
if (isset($_GET['q']) && is_string($_GET['q']) && strlen($_GET['q']) <= 1400) $_SESSION['pending_qr'] = $_GET['q'];
$user = current_user();
if (!$user) { header('Location: index.php'); exit; }
$token = (string)($_SESSION['pending_qr'] ?? '');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#F4F7FB"><title>Attendance Scan</title><link rel="stylesheet" href="assets/styles.css"><link rel="stylesheet" href="assets/modern.css"><link rel="stylesheet" href="assets/typography.css"></head>
<body class="login-body merd-login-body"><main class="login-shell"><section class="login-card scan-card merd-login-card">
<div class="brand-mark">QR</div><h1>Attendance</h1><p id="scanStatus" class="muted">Validating the authorised POS QR…</p>
<div id="scanReceipt" hidden></div><a class="secondary-link" href="dashboard.php">Open dashboard</a>
</section></main><script>
const token=<?= json_encode($token, JSON_UNESCAPED_SLASHES) ?>,csrf=<?= json_encode(csrf_token()) ?>;
(async()=>{const status=document.getElementById('scanStatus'),receipt=document.getElementById('scanReceipt');
try{if(!token)throw new Error('No QR is waiting. Scan the current POS QR.');const r=await fetch('api/attendance_scan.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token,csrf})});const d=await r.json();if(!d.success)throw new Error(d.error||'Scan failed');const x=d.result;status.textContent=x.duplicate?'This QR was already processed.':(x.action==='IN'?'You are clocked in.':'You are clocked out.');receipt.hidden=false;receipt.innerHTML=`<div class="scan-result ${x.action==='IN'?'in':'out'}"><strong>${x.action}</strong><span>${escapeHtml(x.store_name)}</span><small>${escapeHtml(x.occurred_at)} UTC</small></div>`;}catch(e){status.textContent=e.message;status.classList.add('error-message');}})();
function escapeHtml(v){return String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));}
</script></body></html>
