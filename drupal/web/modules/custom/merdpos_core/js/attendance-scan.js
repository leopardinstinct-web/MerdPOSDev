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
        const target = root.querySelector('.merdpos-attendance-target');
        const status = root.querySelector('[data-attendance-status]');
        const open = root.querySelector('[data-attendance-open]');
        const stop = root.querySelector('[data-attendance-stop]');
        const state = {
          stream: null,
          detector: null,
          timer: null,
          feedbackTimer: null,
          busy: false,
          frame: 0,
        };

        const setStatus = (message) => {
          if (status) status.textContent = message || '';
        };

        const setTargetState = (name = '') => {
          if (!target) return;
          target.classList.remove('is-detected', 'is-invalid', 'is-accepted');
          if (name) target.classList.add(name);
          if (state.feedbackTimer) window.clearTimeout(state.feedbackTimer);
          if (name && name !== 'is-accepted') {
            state.feedbackTimer = window.setTimeout(() => {
              target.classList.remove('is-detected', 'is-invalid');
              state.feedbackTimer = null;
            }, 800);
          }
        };

        const stopCamera = () => {
          if (state.timer) window.clearTimeout(state.timer);
          if (state.feedbackTimer) window.clearTimeout(state.feedbackTimer);
          state.timer = null;
          state.feedbackTimer = null;
          if (state.stream) state.stream.getTracks().forEach((track) => track.stop());
          state.stream = null;
          state.detector = null;
          state.frame = 0;
          if (video) {
            video.pause();
            video.srcObject = null;
          }
          setTargetState('');
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
          setTargetState('is-detected');
          setStatus('QR detected. Validating Shop QR.');
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
            setTargetState('is-accepted');
            setStatus('Shop QR accepted.');
            window.setTimeout(() => {
              closeDialog();
              window.location.reload();
            }, 180);
          } catch (_) {
            setTargetState('is-invalid');
            setStatus('QR detected but it is not a valid current MERDPOS Shop QR.');
            state.busy = false;
            if (state.stream) state.timer = window.setTimeout(scanFrame, 850);
          }
        };

        const drawScanFrame = () => {
          if (!video || !canvas || video.readyState < 2 || !video.videoWidth || !video.videoHeight) return null;

          state.frame = (state.frame + 1) % 5;
          const fullFrame = state.frame === 0;
          const sourceWidth = video.videoWidth;
          const sourceHeight = video.videoHeight;
          const cropSide = Math.max(1, Math.floor(Math.min(sourceWidth, sourceHeight) * 0.76));
          const sx = fullFrame ? 0 : Math.floor((sourceWidth - cropSide) / 2);
          const sy = fullFrame ? 0 : Math.floor((sourceHeight - cropSide) / 2);
          const sw = fullFrame ? sourceWidth : cropSide;
          const sh = fullFrame ? sourceHeight : cropSide;
          const maxDecodeWidth = 720;
          const scale = Math.min(1, maxDecodeWidth / sw);
          const width = Math.max(1, Math.round(sw * scale));
          const height = Math.max(1, Math.round(sh * scale));

          canvas.width = width;
          canvas.height = height;
          const ctx = canvas.getContext('2d', { willReadFrequently: true });
          if (!ctx) return null;
          ctx.drawImage(video, sx, sy, sw, sh, 0, 0, width, height);
          return { ctx, width, height };
        };

        const decodeFrame = async () => {
          const frame = drawScanFrame();
          if (!frame) return '';

          if (state.detector) {
            try {
              const codes = await state.detector.detect(canvas);
              const qr = codes.find((code) => code.rawValue);
              if (qr?.rawValue) return qr.rawValue;
            } catch (_) {}
          }

          if (typeof window.jsQR === 'function') {
            try {
              const image = frame.ctx.getImageData(0, 0, frame.width, frame.height);
              const code = window.jsQR(image.data, frame.width, frame.height, { inversionAttempts: 'attemptBoth' });
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
          if (state.stream) state.timer = window.setTimeout(scanFrame, 140);
        };

        const getCameraStream = async () => {
          const videoDefaults = {
            width: { ideal: 1280 },
            height: { ideal: 720 },
          };
          const attempts = [
            { ...videoDefaults, facingMode: { exact: 'environment' } },
            { ...videoDefaults, facingMode: { ideal: 'environment' } },
            videoDefaults,
          ];
          let lastError = null;
          for (const videoConstraints of attempts) {
            try {
              return await navigator.mediaDevices.getUserMedia({ audio: false, video: videoConstraints });
            } catch (error) {
              lastError = error;
            }
          }
          throw lastError || new Error('Camera unavailable.');
        };

        const optimizeCamera = async (stream) => {
          const track = stream?.getVideoTracks?.()[0];
          if (!track || typeof track.getCapabilities !== 'function' || typeof track.applyConstraints !== 'function') return;
          try {
            const capabilities = track.getCapabilities();
            const advanced = {};
            if (Array.isArray(capabilities.focusMode) && capabilities.focusMode.includes('continuous')) {
              advanced.focusMode = 'continuous';
            }
            if (Object.keys(advanced).length) await track.applyConstraints({ advanced: [advanced] });
          } catch (_) {}
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
            if ('BarcodeDetector' in window && typeof window.BarcodeDetector === 'function') {
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

            state.stream = await getCameraStream();
            await optimizeCamera(state.stream);
            video.srcObject = state.stream;
            await video.play();
            setStatus('Camera ready. Point it at a MERDPOS Shop QR.');
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
