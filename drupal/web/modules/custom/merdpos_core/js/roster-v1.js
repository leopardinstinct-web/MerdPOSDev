/**
 * Roster planner: local-first edits with an idempotent queue.
 *
 * Mirrors the Financials offline contract deliberately (the same versioned queue
 * key pattern, the same UUID v4 idempotency id per submission, the same
 * `retryable` handshake), because a planner works on a shop floor where the
 * connection drops mid-week. Two rules are non-negotiable here:
 *
 *  1. A queued week is NEVER applied twice. Each queued submission carries its
 *     own UUID, and the portal treats a repeat of that id as a duplicate, so a
 *     retry after a dropped response is safe.
 *  2. The browser never decides what is TRUE. It decides what to RETRY. An
 *     authoritative rejection (a 4xx that is not retryable) drops the entry and
 *     tells the planner why; anything the portal could not be asked is kept.
 *
 * The week itself is rendered server-side, so the grid the planner sees offline
 * is the last confirmed week - no fetch is required to read it.
 */
((Drupal, once) => {
  const DEFAULT_QUEUE_KEY = 'merdpos_roster_queue_v1';
  const DEFAULT_CACHE_KEY = 'merdpos_roster_cache_v1';

  const readQueue = (key) => {
    try {
      const value = JSON.parse(localStorage.getItem(key) || '[]');
      return Array.isArray(value) ? value : [];
    } catch (_) {
      return [];
    }
  };

  const writeQueue = (key, queue) => {
    try {
      localStorage.setItem(key, JSON.stringify(queue));
      return true;
    } catch (_) {
      return false;
    }
  };

  const newSubmissionId = () => {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
    // Only reached on a browser without randomUUID; still a v4-shaped id so the
    // portal's validation accepts it.
    const bytes = new Uint8Array(16);
    (window.crypto || {}).getRandomValues?.(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
  };

  const clock = (value) => {
    const text = String(value || '').trim();
    return /^(?:[01]\d|2[0-3]):[0-5]\d$/.test(text) ? `${text}:00` : null;
  };

  Drupal.behaviors.merdposRoster = {
    attach(context) {
      once('merdpos-roster', '[data-roster-offline-root]', context).forEach((root) => {
        const queueKey = root.dataset.rosterQueueKey || DEFAULT_QUEUE_KEY;
        const cacheKey = root.dataset.rosterCacheKey || DEFAULT_CACHE_KEY;
        const submitUrl = root.dataset.rosterSubmitUrl || '';
        const token = root.dataset.rosterToken || '';
        const storeId = Number(root.dataset.rosterStoreId || 0);
        const weekStart = root.dataset.rosterWeekStart || '';
        const canManage = root.dataset.rosterCanManage === '1';
        const badge = root.querySelector('[data-roster-queue-badge]');
        const message = root.querySelector('[data-roster-status-message]');
        const offlineNote = root.querySelector('[data-roster-offline-note]');

        const setMessage = (text) => {
          if (message) message.textContent = text || '';
        };

        const updateQueueBadge = () => {
          const count = readQueue(queueKey).length;
          if (badge) badge.textContent = count === 0 ? '✓ Up to date' : `${count} pending`;
        };

        const updateConnectivity = () => {
          const offline = !navigator.onLine;
          if (offlineNote) offlineNote.hidden = !offline;
          if (offline) setMessage('Offline — showing the last confirmed week plus this device’s pending changes.');
        };

        /** The grid as the portal expects it: one entry per cell that has a shift. */
        const collectShifts = () => {
          const shifts = [];
          root.querySelectorAll('[data-roster-cell]').forEach((cell) => {
            const assignments = [...cell.querySelectorAll('[data-roster-employee]')]
              .map((chip) => Number(chip.dataset.rosterEmployee || 0))
              .filter((id) => id > 0)
              .map((employee_id) => ({ employee_id }));
            const start = clock(cell.querySelector('[data-roster-start]')?.value);
            const end = clock(cell.querySelector('[data-roster-end]')?.value);
            const endsNextDay = Boolean(cell.querySelector('[data-roster-ends-next-day]')?.checked);
            // An untouched, unassigned cell is not a shift: the portal replaces the
            // week wholesale, so sending empty cells would erase planned shifts.
            if (!start || !end) return;
            if (assignments.length === 0 && cell.classList.contains('is-empty')) return;
            shifts.push({
              shift_date: cell.dataset.rosterDate,
              slot_key: cell.dataset.rosterSlot,
              start_time: start,
              end_time: end,
              ends_next_day: endsNextDay,
              assignments,
            });
          });
          return shifts;
        };

        const queueWeek = (status) => {
          if (!canManage) return;
          const shifts = collectShifts();
          const item = {
            submission_id: newSubmissionId(),
            store_id: storeId,
            week_start: weekStart,
            status,
            note: String(root.querySelector('[data-roster-note]')?.value || '').slice(0, 255),
            shifts,
            queued_at: new Date().toISOString(),
          };
          const queue = readQueue(queueKey);
          queue.push(item);
          if (!writeQueue(queueKey, queue)) {
            window.alert('This browser could not save the roster for offline retry.');
            return;
          }
          updateQueueBadge();
          setMessage(`Saved on this phone. Sending ${shifts.length} shift${shifts.length === 1 ? '' : 's'}…`);
          flushQueue();
        };

        const cacheConfirmedWeek = () => {
          try {
            localStorage.setItem(cacheKey, JSON.stringify({
              store_id: storeId,
              week_start: weekStart,
              cached_at: new Date().toISOString(),
            }));
          } catch (_) {
            /* A full or blocked storage must not break planning. */
          }
        };

        const flushQueue = () => {
          const queue = readQueue(queueKey);
          if (queue.length === 0) {
            updateQueueBadge();
            return;
          }
          if (!navigator.onLine) {
            updateConnectivity();
            return;
          }
          const remaining = [];
          let sent = 0;
          let reload = false;
          const next = (index) => {
            if (index >= queue.length) {
              writeQueue(queueKey, remaining);
              updateQueueBadge();
              if (remaining.length === 0) {
                setMessage('Saved successfully.');
                if (reload) window.location.reload();
              } else if (sent > 0) {
                setMessage(`${sent} week${sent === 1 ? '' : 's'} saved. ${remaining.length} still pending.`);
              }
              return;
            }
            const item = queue[index];
            window.fetch(submitUrl, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'X-MERDPOS-CSRF': token },
              body: JSON.stringify(item),
              credentials: 'same-origin',
            }).then((response) => response.json().catch(() => ({})).then((data) => ({ response, data })))
              .then(({ response, data }) => {
                if (response.ok && data?.success) {
                  sent += 1;
                  reload = true;
                } else if (data?.retryable === true || response.status >= 500 || response.status === 0) {
                  // The portal could not be asked, so the week stays queued.
                  remaining.push(item);
                } else {
                  // Authoritative rejection: retrying cannot help, so say why and drop it.
                  setMessage(String(data?.error || 'MERDPOS rejected this roster change.'));
                }
                next(index + 1);
              })
              .catch(() => {
                remaining.push(item);
                next(index + 1);
              });
          };
          next(0);
        };

        if (canManage) {
          root.querySelectorAll('[data-roster-add]').forEach((select) => {
            select.addEventListener('change', () => {
              const employeeId = Number(select.value || 0);
              if (!employeeId) return;
              const cell = select.closest('[data-roster-cell]');
              const list = cell?.querySelector('[data-roster-assignments]');
              const option = select.options[select.selectedIndex];
              if (!cell || !list || !option) return;
              if (list.querySelector(`[data-roster-employee="${employeeId}"]`)) {
                select.value = '';
                return;
              }
              const chip = document.createElement('li');
              chip.className = 'merdpos-roster-chip';
              chip.dataset.rosterEmployee = String(employeeId);
              const label = document.createElement('span');
              label.textContent = option.textContent || '';
              const remove = document.createElement('button');
              remove.type = 'button';
              remove.className = 'merdpos-roster-chip-remove';
              remove.dataset.rosterRemove = '';
              remove.setAttribute('aria-label', `Remove ${label.textContent}`);
              remove.textContent = '×';
              chip.append(label, remove);
              list.append(chip);
              cell.classList.remove('is-empty');
              select.value = '';
            });
          });

          root.addEventListener('click', (event) => {
            const remove = event.target.closest('[data-roster-remove]');
            if (!remove) return;
            const chip = remove.closest('[data-roster-employee]');
            const cell = remove.closest('[data-roster-cell]');
            chip?.remove();
            if (cell && !cell.querySelector('[data-roster-employee]')) cell.classList.add('is-empty');
          });

          root.querySelectorAll('[data-roster-save]').forEach((button) => {
            button.addEventListener('click', () => queueWeek(button.dataset.rosterSave || 'draft'));
          });
        }

        cacheConfirmedWeek();
        updateQueueBadge();
        updateConnectivity();
        window.addEventListener('online', () => {
          updateConnectivity();
          flushQueue();
        });
        window.addEventListener('offline', updateConnectivity);
        flushQueue();
      });
    },
  };
})(Drupal, once);
