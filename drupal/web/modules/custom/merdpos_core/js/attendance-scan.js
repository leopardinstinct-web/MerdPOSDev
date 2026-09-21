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
        const canvas = root.querySelector('[data-attendance-canvas]');
        const status = root.querySelector('[data-attendance-status]');
        const open = root.querySelector('[data-attendance-open]');
        const stop = root.querySelector('[data-attendance-stop]');
        const state = { stream: null, detector: null, timer: null, busy: false };

        const setStatus = (message) => {
          if (status) status.textContent = message || '';
        };

        const stopCamera = () => {
          if (state.timer) window.clearTimeout(state.timer);
          state.timer = null;
          if (state.stream) state.stream.getTracks().forEach((track) => track.stop());
          state.stream = null;
          state.detector = null;
          if (video) {
            video.pause();
            video.srcObject = null;
          }
        };

        const syncHeaderState = (scan) => {
          const shopActive = !!scan.shop_active;
          root.dataset.shopActive = shopActive ? '1' : '0';
          root.classList.toggle('is-shop-active', shopActive);
          if (open) {
            open.setAttribute(
              'aria-label',
              shopActive ? 'Shop logged in. Scan Shop Log OUT QR' : 'Shop logged out. Scan Shop Log IN QR',
            );
            open.setAttribute(
              'title',
              shopActive ? 'Shop logged in — scan to log out' : 'Shop logged out — scan to log in',
            );
          }
        };

        const closeDialog = () => {
          stopCamera();
          if (typeof panel?.close === 'function' && panel.open) panel.close();
          else panel?.removeAttribute('open');
        };

        const submitQr = async (raw) => {
          const qr = extractQr(raw);
          if (!qr || state.busy) return;
          state.busy = true;
          setStatus('Validating Shop QR.');
          try {
            const response = await fetch(endpoint, {
              method: 'POST',
              credentials: 'same-origin',
              headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-MERDPOS-CSRF': csrf,
              },
              body: JSON.stringify({ qr }),
            });
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.error || 'Shop QR scan failed.');
            syncHeaderState(data.result || {});
            setStatus('Shop QR accepted.');
            closeDialog();
            window.setTimeout(() => window.location.reload(), 120);
          } catch (_) {
            setStatus('Shop QR was not accepted.');
            state.busy = false;
            if (state.stream) state.timer = window.setTimeout(scanFrame, 500);
          }
        };

        const decodeFrame = async () => {
          if (!video || video.readyState < 2 || !video.videoWidth || !video.videoHeight) return '';

          if (state.detector) {
            try {
              const codes = await state.detector.detect(video);
              const qr = codes.find((code) => code.rawValue);
              if (qr?.rawValue) return qr.rawValue;
            } catch (_) {}
          }

          if (typeof window.jsQR === 'function' && canvas) {
            try {
              const width = video.videoWidth;
              const height = video.videoHeight;
              canvas.width = width;
              canvas.height = height;
              const ctx = canvas.getContext('2d', { willReadFrequently: true });
              if (!ctx) return '';
              ctx.drawImage(video, 0, 0, width, height);
              const image = ctx.getImageData(0, 0, width, height);
              const code = window.jsQR(image.data, width, height, { inversionAttempts: 'attemptBoth' });
              if (code?.data) return code.data;
            } catch (_) {}
          }

          return '';
        };

        const scanFrame = async () => {
          if (!state.stream || state.busy) return;
          const raw = await decodeFrame();
          if (raw) {
            await submitQr(raw);
            return;
          }
          if (state.stream) state.timer = window.setTimeout(scanFrame, 180);
        };

        const openCamera = async () => {
          if (typeof panel?.showModal === 'function') {
            if (!panel.open) panel.showModal();
          } else {
            panel?.setAttribute('open', '');
          }

          stopCamera();
          state.busy = false;
          setStatus('Starting camera.');

          if (!navigator.mediaDevices?.getUserMedia) {
            setStatus('Camera unavailable.');
            return;
          }

          try {
            if ('BarcodeDetector' in window) {
              try {
                const supported = typeof BarcodeDetector.getSupportedFormats === 'function'
                  ? await BarcodeDetector.getSupportedFormats()
                  : ['qr_code'];
                if (supported.includes('qr_code')) state.detector = new BarcodeDetector({ formats: ['qr_code'] });
              } catch (_) {
                state.detector = null;
              }
            }

            if (!state.detector && typeof window.jsQR !== 'function') {
              setStatus('QR scanner unavailable.');
              return;
            }

            state.stream = await navigator.mediaDevices.getUserMedia({
              audio: false,
              video: {
                facingMode: { ideal: 'environment' },
                width: { ideal: 1280 },
                height: { ideal: 720 },
              },
            });
            video.srcObject = state.stream;
            await video.play();
            setStatus('Camera ready.');
            scanFrame();
          } catch (_) {
            stopCamera();
            setStatus('Camera unavailable.');
          }
        };

        open?.addEventListener('click', openCamera);
        stop?.addEventListener('click', closeDialog);
        panel?.addEventListener('click', (event) => { if (event.target === panel) closeDialog(); });
        panel?.addEventListener('close', stopCamera);
        window.addEventListener('pagehide', stopCamera, { once: true });
      });
    },
  };
})(Drupal, once);
