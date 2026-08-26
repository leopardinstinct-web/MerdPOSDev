(function () {
  'use strict';

  // The centralized LOA renderer intentionally omits panels and controls that
  // the current user is not allowed to see. Older beta.js code still expects a
  // handful of those IDs to exist, so provide inert placeholders rather than
  // allowing one missing control to abort the entire shared runtime. API
  // authorization remains authoritative.
  const betaCompatIds = [
    'workingNow',
    'recentShifts',
    'disputeList',
    'financialDate',
    'financialStore',
    'refreshFinancial',
    'cashAccount',
    'cashAvailable',
    'financialSummary',
    'financialEntries',
    'cashMovementForm',
    'closingForm',
    'financialQueue',
    'financialStatus',
    'refreshBetaBtn',
    'passwordBtn',
    'passwordClose',
    'passwordForm',
    'passwordStatus',
    'passwordDialog'
  ];

  betaCompatIds.forEach(id => {
    if (document.getElementById(id)) return;
    const shim = document.createElement('div');
    shim.id = id;
    shim.hidden = true;
    shim.setAttribute('aria-hidden', 'true');
    shim.dataset.permissionRuntimeShim = '1';
    shim.value = '';
    shim.disabled = false;
    shim.reset = () => {};
    shim.showModal = () => {};
    shim.close = () => {};
    document.body.appendChild(shim);
  });

  const hasTimesheetDom = [
    'weekSelect',
    'statusBox',
    'reportContainer',
    'reportTitle',
    'reportSubtitle'
  ].every(id => document.getElementById(id));

  if (hasTimesheetDom) {
    const script = document.createElement('script');
    script.src = 'assets/timesheet-app.js?v=20260826permissionhotfix1';
    script.async = false;
    script.dataset.timesheetApp = '1';
    document.body.appendChild(script);
    return;
  }

  // app.js historically owned logout. Preserve that behavior even when the
  // Timesheet panel is not rendered for the current role.
  const logout = document.getElementById('logoutBtn');
  if (logout && !logout.dataset.logoutRuntimeBound) {
    logout.dataset.logoutRuntimeBound = '1';
    logout.addEventListener('click', async () => {
      try { await fetch('api/logout.php'); } catch (_) {}
      window.location.href = 'index.php';
    });
  }
})();
