(function (Drupal, once) {
  'use strict';

  const extractQr = (raw) => {
    const value = String(raw || '').trim();
    if (!value || value.length > 1800) return '';
    try {
      const url = new URL(value, window.location.origin);
      const candidate = url.searchParams.get('q') || url.searchParams.get('token');
      if (candidate) return candidate.trim();
    } catch (_) {}
    return value;
  };

  Drupal.behaviors.merdposAttendanceScan = {
    attach(context) {
      once('merdpos-attendance-scan', '[data-attendance-scan]', context).forEach((root) => {
        const endpoint = root.dataset.endpoint || '';
        const csrf = root.dataset.csrf || '';
        const panel = root.querySelector('[data-attendance-panel]');
        const video = root.querySelector('[data-attendance-video]');
        const status = root.querySelector('[data-attendance-status]');
        const result = root.querySelector('[data-attendance-result]');
        const manual = root.querySelector('[data-attendance-manual]');
        const input = root.querySelector('[data-attendance-input]');
        const open = root.querySelector('[data-attendance-open]');
        const stop = root.querySelector('[data-attendance-stop]');
        const refresh = root.querySelector('[data-attendance-refresh]');
        const state = { stream: null, detector: null, timer: null, busy: false };

        const setStatus = (message, tone = '') => {
          status.textContent = message;
          status.classList.toggle('is-error', tone === 'error');
          status.classList.toggle('is-success', tone === 'success');
        };

        const stopCamera = () => {
          if (state.timer) window.clearTimeout(state.timer);
          state.timer = null;
          if (state.stream) state.stream.getTracks().forEach((track) => track.stop());
          state.stream = null;
          if (video) { video.pause(); video.srcObject = null; }
        };

        const showResult = (scan) => {
          const action = String(scan.action || '').toUpperCase();
          const attendanceMode = !!scan.attendance;
          const shopActive = !!scan.shop_active;
          root.querySelector('[data-attendance-result-action]').textContent = action === 'IN'
            ? (attendanceMode ? 'CLOCKED IN' : 'SHOP LOGGED IN')
            : (attendanceMode ? 'CLOCKED OUT' : 'SHOP LOGGED OUT');
          root.querySelector('[data-attendance-result-store]').textContent = scan.store_name || 'MERDPOS store';
          root.querySelector('[data-attendance-result-time]').textContent = scan.occurred_at
            ? `Recorded ${scan.occurred_at} UTC`
            : (attendanceMode ? 'Attendance recorded' : 'Shop access updated');
          result.classList.toggle('is-in', action === 'IN');
          result.classList.toggle('is-out', action === 'OUT');
          result.hidden = false;
          root.dataset.shopActive = shopActive ? '1' : '0';
          root.classList.toggle('is-shop-active', shopActive);
          const shopName = root.querySelector('.merdpos-dashboard-shop-name');
          if (shopName) {
            shopName.textContent = shopActive ? (scan.store_name || 'Current shop') : '';
            shopName.hidden = !shopActive;
          }
          setStatus(
            scan.duplicate
              ? 'This QR was already processed.'
              : (attendanceMode ? 'Attendance recorded successfully.' : (shopActive ? 'Shop access is active.' : 'Shop access closed.')),
            'success',
          );
        };

        const submitQr = async (raw) => {
          const qr = extractQr(raw);
          if (!qr) { setStatus('Scan or paste a MERDPOS Shop QR first.', 'error'); return; }
          if (state.busy) return;
          state.busy = true;
          stopCamera();
          setStatus('Validating this QR with MERDPOS…');
          try {
            const response = await fetch(endpoint, {
              method: 'POST',
              credentials: 'same-origin',
              headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-MERDPOS-CSRF': csrf },
              body: JSON.stringify({ qr }),
            });
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.error || 'Attendance scan failed.');
            showResult(data.result || {});
            if (input) input.value = '';
          } catch (error) {
            setStatus(error instanceof Error ? error.message : 'Attendance scan failed.', 'error');
          } finally {
            state.busy = false;
          }
        };

        const scanFrame = async () => {
          if (!state.stream || !state.detector || state.busy) return;
          try {
            if (video.readyState >= 2) {
              const codes = await state.detector.detect(video);
              const qr = codes.find((code) => code.rawValue);
              if (qr) { await submitQr(qr.rawValue); return; }
            }
          } catch (_) {}
          if (state.stream) state.timer = window.setTimeout(scanFrame, 220);
        };

        const openCamera = async () => {
          result.hidden = true;
          if (typeof panel.showModal === 'function') {
            if (!panel.open) panel.showModal();
          } else {
            panel.setAttribute('open', '');
          }
          setStatus('Starting camera…');
          stopCamera();
          if (!navigator.mediaDevices?.getUserMedia || !('BarcodeDetector' in window)) {
            setStatus('Live QR scanning is not supported by this browser. Paste the MERDPOS QR link below.', 'error');
            input?.focus();
            return;
          }
          try {
            state.detector = new BarcodeDetector({ formats: ['qr_code'] });
            state.stream = await navigator.mediaDevices.getUserMedia({
              audio: false,
              video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
            });
            video.srcObject = state.stream;
            await video.play();
            setStatus('Point the camera at the current MERDPOS Shop QR.');
            scanFrame();
          } catch (error) {
            stopCamera();
            const denied = error instanceof DOMException && (error.name === 'NotAllowedError' || error.name === 'SecurityError');
            setStatus(denied ? 'Camera access was denied. Allow camera access or paste the QR link below.' : 'The camera could not start. Paste the QR link below.', 'error');
            input?.focus();
          }
        };

        const closeDialog = () => {
          stopCamera();
          if (typeof panel.close === 'function' && panel.open) panel.close();
          else panel.removeAttribute('open');
        };

        open?.addEventListener('click', openCamera);
        stop?.addEventListener('click', closeDialog);
        panel?.addEventListener('click', (event) => { if (event.target === panel) closeDialog(); });
        panel?.addEventListener('close', stopCamera);
        manual?.addEventListener('submit', (event) => { event.preventDefault(); submitQr(input?.value || ''); });
        refresh?.addEventListener('click', () => window.location.reload());
        window.addEventListener('pagehide', stopCamera, { once: true });
      });
    },
  };
})(Drupal, once);
