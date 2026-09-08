(function (Drupal) {
  'use strict';
  const DEFAULT_QUEUE_KEY = 'merdpos_financial_queue_v1';
  const NOTICE_KEY = 'merdpos_financial_queue_notice_v1';
  const uuid = () => crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
    const r = crypto.getRandomValues(new Uint8Array(1))[0] & 15;
    return (c === 'x' ? r : (r & 3 | 8)).toString(16);
  });

  Drupal.behaviors.merdposFinanceWrite = {
    attach(context) {
      context.querySelectorAll('[data-finance-offline-root]').forEach((root) => {
        if (root.dataset.merdposFinanceBound === '1') return;
        root.dataset.merdposFinanceBound = '1';
        const queueKey = root.dataset.financeQueueKey || DEFAULT_QUEUE_KEY;
        const badge = root.querySelector('[data-finance-queue-badge]');
        const status = root.querySelector('[data-finance-status]');
        const cashAccount = root.querySelector('[data-finance-cash-account]');
        const effectiveNode = root.querySelector('[data-finance-effective-available]');
        const getQueue = () => {
          try { const value = JSON.parse(localStorage.getItem(queueKey) || '[]'); return Array.isArray(value) ? value : []; }
          catch (_) { return []; }
        };
        const setQueue = (queue) => localStorage.setItem(queueKey, JSON.stringify(queue));
        const setStatus = (message, error = false) => {
          if (!status) return;
          status.textContent = message || '';
          status.classList.toggle('is-error', error);
        };
        const confirmedAvailable = (name) => {
          const card = Array.from(root.querySelectorAll('[data-finance-account]')).find((node) => node.dataset.financeAccount === name);
          if (!card) return null;
          const value = Number(card.dataset.financeAvailable);
          return Number.isFinite(value) ? value : null;
        };
        const effectiveAvailable = (name) => {
          const storeId = Number(root.dataset.financeStoreId || 0);
          const date = root.dataset.financeBusinessDate || '';
          let value = confirmedAvailable(name);
          for (const item of getQueue()) {
            if (Number(item.store_id) !== storeId || item.business_date !== date) continue;
            if (item.submission_type === 'open_day' && value === null) value = Number(name === 'Register' ? item.payload?.register_opening : item.payload?.petty_cash_opening);
            if ((item.submission_type === 'cash_in' || item.submission_type === 'cash_out') && value !== null) {
              for (const tx of item.payload?.transactions || []) if (tx.account === name) value += (item.submission_type === 'cash_in' ? 1 : -1) * Number(tx.amount);
            }
          }
          return Number.isFinite(value) ? value : null;
        };
        const updateAvailable = () => {
          if (!effectiveNode || !cashAccount) return;
          const available = effectiveAvailable(cashAccount.value);
          effectiveNode.textContent = available === null ? 'Not opened' : `$${available.toFixed(2)}`;
        };
        const updateQueueBadge = () => {
          const count = getQueue().length;
          if (badge) {
            badge.textContent = count ? `${count} pending` : '✓ Up to date';
            badge.classList.toggle('is-clear', count === 0);
          }
          updateAvailable();
        };
        const queueFinancial = (submissionType, payload) => {
          const storeId = Number(root.dataset.financeStoreId || 0);
          const businessDate = root.dataset.financeBusinessDate || '';
          if (!storeId) { window.alert('Clock in before submitting store financials.'); return false; }
          const sameDay = getQueue().filter((item) => Number(item.store_id) === storeId && item.business_date === businessDate);
          if (sameDay.some((item) => item.submission_type === 'z_report')) { window.alert('This day already has a closing waiting to sync.'); return false; }
          if (submissionType === 'open_day' && sameDay.some((item) => item.submission_type === 'open_day')) { window.alert('This day already has an opening waiting to sync.'); return false; }
          const item = {submission_id: uuid(), store_id: storeId, business_date: businessDate, submission_type: submissionType, payload};
          const queue = getQueue();
          try { queue.push(item); setQueue(queue); }
          catch (_) { window.alert('This browser could not save the financial submission for offline retry.'); return false; }
          updateQueueBadge();
          setStatus('Saved on this phone. Sending…');
          flushQueue();
          return true;
        };

        const flushQueue = async () => {
          if (!navigator.onLine) { updateQueueBadge(); return; }
          const queue = getQueue();
          if (!queue.length) { updateQueueBadge(); return; }
          const remaining = [];
          let lastNotice = null;
          for (const item of queue) {
            let response;
            try {
              response = await fetch(root.dataset.financeSubmitUrl || '', {
                method: 'POST', credentials: 'same-origin',
                headers: {'Accept':'application/json','Content-Type':'application/json','X-MERDPOS-CSRF':root.dataset.financeToken || ''},
                body: JSON.stringify(item),
              });
            }
            catch (_) { remaining.push(item); continue; }
            const data = await response.json().catch(() => null);
            if (!response.ok || !data || data.success !== true) {
              if (data?.retryable === true) { remaining.push(item); continue; }
              const message = data?.error || `Request failed (${response.status}).`;
              setStatus(`Not accepted: ${message}`, true);
              lastNotice = {message:`Not accepted: ${message}`, error:true};
              continue;
            }
            setStatus('Saved successfully.');
            lastNotice = {message:'Saved successfully.', error:false};
          }
          setQueue(remaining);
          updateQueueBadge();
          if (!remaining.length && queue.length) {
            if (lastNotice) sessionStorage.setItem(NOTICE_KEY, JSON.stringify(lastNotice));
            window.location.reload();
          }
        };

        cashAccount?.addEventListener('change', updateAvailable);
        root.querySelectorAll('[data-finance-queue-form]').forEach((form) => {
          form.addEventListener('submit', (event) => {
            event.preventDefault();
            const values = Object.fromEntries(new FormData(form));
            const kind = form.dataset.financeSubmissionType || '';
            if (kind === 'open_day') {
              const register = Number(values.register_opening), petty = Number(values.petty_cash_opening);
              if (!Number.isFinite(register) || register < 0 || !Number.isFinite(petty) || petty < 0) { window.alert('Enter valid opening balances.'); return; }
              if (queueFinancial('open_day', {register_opening:register, petty_cash_opening:petty})) form.reset();
              return;
            }
            if (kind === 'cash_movement') {
              const amount = Number(values.amount), head = String(values.head || '').trim();
              if (head.length < 2 || !Number.isFinite(amount) || amount <= 0) { window.alert('Enter a reason and an amount greater than zero.'); return; }
              const available = effectiveAvailable(values.account);
              if (available === null) { window.alert('Open this financial day and confirm its balance before entering Cash IN / OUT.'); return; }
              if (values.submission_type === 'cash_out' && amount > available) { window.alert(`${values.account} has $${available.toFixed(2)} available. Cash OUT cannot exceed this amount.`); return; }
              if (queueFinancial(values.submission_type, {transactions:[{account:values.account, head, amount}]})) form.reset();
              updateAvailable();
              return;
            }
            if (kind === 'z_report') {
              const total = Number(values.register_total), petty = Number(values.petty_cash_addin || 0), available = effectiveAvailable('Register');
              if (available === null) { window.alert('Open this financial day before closing it.'); return; }
              if (!Number.isFinite(total) || total < 0 || !Number.isFinite(petty) || petty < 0 || petty > total) { window.alert('Enter valid closing totals. Petty Cash transfer cannot exceed the Register total.'); return; }
              if (total - available + petty < 0) { window.alert('Register total is below the recorded balance. Review Cash IN / OUT first.'); return; }
              if (!window.confirm('Close this financial day? This can only be done once.')) return;
              const denominations = String(values.denominations || '').split(',').map((value) => value.trim()).filter(Boolean);
              if (queueFinancial('z_report', {register_total:total, petty_cash_addin:petty, denominations})) form.reset();
            }
          });
        });

        window.addEventListener('online', flushQueue);
        window.addEventListener('offline', () => {
          setStatus('Offline — showing the last confirmed balance plus this device’s pending entries.');
          updateQueueBadge();
        });
        try {
          const savedNotice = JSON.parse(sessionStorage.getItem(NOTICE_KEY) || 'null');
          if (savedNotice?.message) setStatus(savedNotice.message, savedNotice.error === true);
          sessionStorage.removeItem(NOTICE_KEY);
        }
        catch (_) { sessionStorage.removeItem(NOTICE_KEY); }
        updateQueueBadge();
        flushQueue();
      });
    }
  };
})(Drupal);
