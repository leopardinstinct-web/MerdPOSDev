/**
 * Roster planner: local-first edits with an idempotent queue.
 *
 * Mirrors the Financials offline contract deliberately (the same versioned queue
 * key pattern, the same UUID v4 idempotency id per submission, the same
 * `retryable` handshake), because a planner works on a shop floor where the
 * connection drops mid-week. Three rules are non-negotiable here:
 *
 *  1. A queued week is NEVER applied twice. Each queued submission carries its
 *     own UUID, and the portal treats a repeat of that id as a duplicate, so a
 *     retry after a dropped response is safe.
 *  2. The browser never decides what is TRUE. It decides what to RETRY. An
 *     authoritative rejection (a 4xx that is not retryable) drops the entry and
 *     tells the planner why; anything the portal could not be asked is kept and
 *     retried with backoff while the tab is open and online.
 *  3. "No employees on this shift" is not "no shift here". The portal replaces a
 *     week wholesale, so a cell that held a stored shift is sent back as a shift
 *     even when its last employee has just been removed.
 *
 * The week is rendered server-side into the markup, so it stays readable for as
 * long as this tab is open with no fetch at all. Nothing else is persisted: there
 * is no service worker and no stored copy of the week.
 */
((Drupal, once) => {
  const DEFAULT_QUEUE_KEY = 'merdpos_roster_queue_v1';
  // Bounded retry for a week the portal could not be asked about: 30s, then 60s,
  // doubling to a ten-minute cap, and only while items remain and the tab is online.
  const RETRY_BASE_MS = 30000;
  const RETRY_MAX_MS = 600000;
  // The note is truncated here in UTF-8 BYTES, which is stricter than either server:
  // the portal keeps 255 CHARACTERS (roster_save_week: mb_substr($note, 0, 255)) and
  // RosterController::normalizeQueuedWeek() truncates the same way - asserted by
  // drupal/tools/verify_roster_controller.php, which runs that method directly.
  // 255 bytes never exceeds 255 characters, so a payload this script accepts fits.
  const NOTE_BYTE_LIMIT = 255;

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

  /**
   * A fresh v4 submission id.
   *
   * The portal dedupes on submission_id, so a CONSTANT fallback id would be
   * catastrophic: every later week would look like a repeat of the first and would
   * be silently dropped as a duplicate. The last resort is therefore Math.random,
   * which is weaker than a CSPRNG but is never constant - that is the property this
   * fallback must guarantee.
   */
  const newSubmissionId = () => {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
    const bytes = new Uint8Array(16);
    const crypto = window.crypto || {};
    if (typeof crypto.getRandomValues === 'function') crypto.getRandomValues(bytes);
    else for (let index = 0; index < bytes.length; index += 1) bytes[index] = Math.floor(Math.random() * 256);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
  };

  const clock = (value) => {
    const text = String(value || '').trim();
    // Seconds are optional because both forms reach this function: the template
    // renders HH:MM while the controller's own normal form is HH:MM:SS. Accepting
    // only one of them would let the emptied-shift fallback silently no-op if the
    // template ever emitted the other - and that is silent data loss, not a
    // cosmetic difference.
    return /^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/.test(text)
      ? (text.length === 5 ? `${text}:00` : text)
      : null;
  };

  /**
   * Truncate to at most `limit` UTF-8 BYTES without splitting a character.
   *
   * Truncating by UTF-16 units (String.prototype.slice) is not the same limit the
   * controller enforces, so a long non-ASCII note would pass here and come back as
   * a 422 that drops the entire queued week.
   */
  const truncateUtf8Bytes = (value, limit) => {
    const text = String(value || '');
    let bytes = 0;
    let out = '';
    for (const char of text) {
      const code = char.codePointAt(0) || 0;
      const size = code <= 0x7f ? 1 : code <= 0x7ff ? 2 : code <= 0xffff ? 3 : 4;
      if (bytes + size > limit) break;
      bytes += size;
      out += char;
    }
    return out;
  };

  Drupal.behaviors.merdposRoster = {
    attach(context) {
      once('merdpos-roster', '[data-roster-offline-root]', context).forEach((root) => {
        const queueKey = root.dataset.rosterQueueKey || DEFAULT_QUEUE_KEY;
        const submitUrl = root.dataset.rosterSubmitUrl || '';
        const token = root.dataset.rosterToken || '';
        const storeId = Number(root.dataset.rosterStoreId || 0);
        const weekStart = root.dataset.rosterWeekStart || '';
        const canManage = root.dataset.rosterCanManage === '1';
        const badge = root.querySelector('[data-roster-queue-badge]');
        const message = root.querySelector('[data-roster-status-message]');
        const offlineNote = root.querySelector('[data-roster-offline-note]');

        let retryTimer = null;
        let retryDelay = RETRY_BASE_MS;
        let flushInFlight = false;
        // Rejection reasons outlive the flush that produced them: a later re-entrant
        // flush drains the remaining entries and would otherwise overwrite the reason
        // with "Saved successfully", hiding the loss. All of them are kept, not just
        // the last, and they are cleared when the planner saves again - so the message
        // can outlive an empty queue, which is deliberate: a rejection must never be
        // silently dropped in favour of a success notice.
        let pendingRejectionReasons = [];

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
          // Says only what is true: the week shown is the one this tab last
          // rendered, and queued changes are counted, not drawn into the grid.
          if (offline) setMessage('Offline — showing the week exactly as this tab last rendered it. Changes are queued on this device and sent when the connection returns.');
        };

        /**
         * What a cell means, or null when it must not be sent at all.
         *
         * A cell belongs to the week when it has employees, OR when the server
         * rendered it with a stored shift, OR when its times no longer match the
         * slot defaults. "No employees" alone is deliberately not "no shift": the
         * portal replaces the week wholesale, so omitting an emptied shift would
         * delete the shift itself. A cell the planner explicitly cleared is the one
         * case that IS omitted, because deleting a shift has to stay possible.
         */
        const readCell = (cell) => {
          const removed = cell.dataset.rosterRemoved === '1';
          let start = clock(cell.querySelector('[data-roster-start]')?.value);
          let end = clock(cell.querySelector('[data-roster-end]')?.value);
          if (!start || !end) {
            // A stored shift whose time inputs were emptied must not vanish: fall
            // back to the times the server last confirmed and put them back in the
            // fields, rather than dropping the shift from a wholesale replacement.
            const originalStart = clock(cell.dataset.rosterOriginalStart);
            const originalEnd = clock(cell.dataset.rosterOriginalEnd);
            if (cell.dataset.rosterOriginal === 'shift' && originalStart && originalEnd) {
              start = originalStart;
              end = originalEnd;
              const startField = cell.querySelector('[data-roster-start]');
              const endField = cell.querySelector('[data-roster-end]');
              if (startField) startField.value = originalStart.slice(0, 5);
              if (endField) endField.value = originalEnd.slice(0, 5);
            }
            else {
              return null;
            }
          }
          const assignments = [...cell.querySelectorAll('[data-roster-employee]')]
            .map((chip) => Number(chip.dataset.rosterEmployee || 0))
            .filter((id) => id > 0)
            .map((employee_id) => ({ employee_id }));
          const defaultStart = clock(cell.dataset.rosterDefaultStart);
          const defaultEnd = clock(cell.dataset.rosterDefaultEnd);
          const timesChanged = Boolean(defaultStart && defaultEnd) && (start !== defaultStart || end !== defaultEnd);
          const planned = !removed && (assignments.length > 0 || cell.dataset.rosterOriginal === 'shift' || timesChanged);
          return {
            assignments,
            planned,
            shift: {
              shift_date: cell.dataset.rosterDate,
              slot_key: cell.dataset.rosterSlot,
              start_time: start,
              end_time: end,
              // Derived from the times, never an independent control: an end at or
              // before the start lands on the next day (the late 16:00-00:00 shift),
              // and an end after the start does not. The controller re-derives it.
              ends_next_day: end <= start,
              assignments,
            },
          };
        };

        /**
         * Keep the read-only "+1d" indicator and the vacant styling in step with
         * the times and the chips. Presentation only - the payload is built by
         * readCell, so the two can never disagree about what counts as a shift.
         */
        const syncCellState = (cell) => {
          if (!cell) return;
          const read = readCell(cell);
          const removed = cell.dataset.rosterRemoved === '1';
          // The two states are mutually exclusive on purpose: is-vacant means "nothing
          // here was ever planned", is-removed means "the planner deleted this shift".
          // Letting both apply would leave whichever background wins by source order.
          cell.classList.toggle('is-vacant', !removed && (!read || !read.planned));
          cell.classList.toggle('is-removed', removed);
          const indicator = cell.querySelector('[data-roster-next-day]');
          if (indicator) indicator.hidden = !read || !read.shift.ends_next_day;
        };

        /** The grid as the portal expects it: one entry per cell that holds a shift. */
        const collectShifts = () => {
          const shifts = [];
          root.querySelectorAll('[data-roster-cell]').forEach((cell) => {
            const read = readCell(cell);
            if (read && read.planned) shifts.push(read.shift);
          });
          return shifts;
        };

        /**
         * Settle entries by re-reading storage and filtering on submission_id.
         *
         * Writing back a snapshot taken before the flush would resurrect an entry
         * the portal already rejected and would lose a save made while the flush
         * was in flight, so removal is always a read-modify-write of current state.
         */
        const dropFromQueue = (submissionIds) => {
          if (submissionIds.length === 0) return;
          const settled = new Set(submissionIds);
          const current = readQueue(queueKey);
          writeQueue(queueKey, current.filter((item) => !settled.has(item.submission_id)));
        };

        const enqueueItem = (item) => {
          const current = readQueue(queueKey);
          current.push(item);
          return writeQueue(queueKey, current);
        };

        const cancelRetry = () => {
          if (retryTimer === null) return;
          window.clearTimeout(retryTimer);
          retryTimer = null;
        };

        const scheduleRetry = () => {
          if (retryTimer !== null || !navigator.onLine) return;
          if (readQueue(queueKey).length === 0) return;
          const delay = retryDelay;
          retryDelay = Math.min(retryDelay * 2, RETRY_MAX_MS);
          retryTimer = window.setTimeout(() => {
            retryTimer = null;
            if (!navigator.onLine || readQueue(queueKey).length === 0) return;
            flushQueue();
          }, delay);
        };

        const flushQueue = () => {
          // An in-flight flush already holds these entries; starting a second one
          // would race it and could send the same week twice.
          if (flushInFlight) return;
          const queue = readQueue(queueKey);
          if (queue.length === 0) {
            cancelRetry();
            retryDelay = RETRY_BASE_MS;
            updateQueueBadge();
            return;
          }
          if (!navigator.onLine) {
            updateConnectivity();
            return;
          }
          flushInFlight = true;
          let sent = 0;
          let reload = false;
          let deferred = 0;
          let rejected = 0;
          const next = (index) => {
            if (index >= queue.length) {
              flushInFlight = false;
              updateQueueBadge();
              const left = readQueue(queueKey).length;
              if (left === 0) {
                // Nothing is queued any more, so no retry may stay scheduled - that
                // invariant has to hold on the rejection path too, not only on success.
                cancelRetry();
                retryDelay = RETRY_BASE_MS;
              }
              if (left === 0 && rejected === 0 && pendingRejectionReasons.length === 0) {
                setMessage('Saved successfully.');
                if (reload) window.location.reload();
                return;
              }
              // A rejection must not be reported as success, and it must not stop the
              // drain either: the other entries are independent weeks, and holding them
              // back would strand a valid change behind an unrelated rejection.
              if (rejected === 0 && pendingRejectionReasons.length === 0 && sent > 0) {
                setMessage(`${sent} week${sent === 1 ? '' : 's'} saved. ${left} still pending.`);
              }
              if (deferred > 0) scheduleRetry();
              else if (left > 0) window.setTimeout(() => flushQueue(), 0);
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
                  retryDelay = RETRY_BASE_MS;
                  dropFromQueue([item.submission_id]);
                  // Settle the badge per item: waiting until the end overcounts a
                  // multi-item queue while it drains.
                  updateQueueBadge();
                } else if (data?.retryable === true || response.status >= 500 || response.status === 0) {
                  // The portal could not be asked, so the week stays queued and the
                  // next attempt is scheduled with backoff.
                  deferred += 1;
                } else {
                  // Authoritative rejection: retrying cannot help, so say why and drop
                  // it. Every reason is remembered, and the first is the one shown when
                  // several arrive, so the earliest cause is not lost behind the latest.
                  rejected += 1;
                  pendingRejectionReasons.push(String(data?.error || 'MERDPOS rejected this roster change.'));
                  setMessage(pendingRejectionReasons.length === 1
                    ? pendingRejectionReasons[0]
                    : `${pendingRejectionReasons.length} changes rejected by MERDPOS. First: ${pendingRejectionReasons[0]}`);
                  dropFromQueue([item.submission_id]);
                  updateQueueBadge();
                }
                next(index + 1);
              })
              .catch(() => {
                deferred += 1;
                next(index + 1);
              });
          };
          next(0);
        };

        const queueWeek = (status) => {
          if (!canManage) return;
          // A new save is the planner acting on the last rejection, so it clears it.
          pendingRejectionReasons = [];
          const shifts = collectShifts();
          const noteField = root.querySelector('[data-roster-note]');
          const typedNote = String(noteField?.value || '');
          const note = truncateUtf8Bytes(typedNote.trim(), NOTE_BYTE_LIMIT);
          // Keep the field and the payload identical rather than truncating silently.
          if (noteField && note !== typedNote) noteField.value = note;
          const item = {
            submission_id: newSubmissionId(),
            store_id: storeId,
            week_start: weekStart,
            status,
            note,
            shifts,
            queued_at: new Date().toISOString(),
          };
          if (!enqueueItem(item)) {
            window.alert('This browser could not save the roster for offline retry.');
            return;
          }
          updateQueueBadge();
          setMessage(`Saved on this phone. Sending ${shifts.length} shift${shifts.length === 1 ? '' : 's'}…`);
          flushQueue();
        };

        root.querySelectorAll('[data-roster-cell]').forEach(syncCellState);

        if (canManage) {
          root.querySelectorAll('[data-roster-cell]').forEach((cell) => {
            cell.querySelectorAll('[data-roster-start], [data-roster-end]').forEach((input) => {
              input.addEventListener('input', () => {
                // Editing the times revives a cleared shift: the planner is describing
                // a shift here again.
                delete cell.dataset.rosterRemoved;
                syncCellState(cell);
              });
            });
          });

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
              // Staffing a cleared cell makes it a shift again.
              delete cell.dataset.rosterRemoved;
              select.value = '';
              syncCellState(cell);
            });
          });

          root.addEventListener('click', (event) => {
            const clear = event.target.closest('[data-roster-clear]');
            if (clear) {
              const cleared = clear.closest('[data-roster-cell]');
              if (!cleared) return;
              // An explicit, deliberate deletion. The cell is marked removed, its
              // staff is taken out, and readCell then omits it from the payload -
              // which is what deletes the shift, since the portal replaces the week
              // wholesale. Touching the times or adding an employee revives it.
              cleared.dataset.rosterRemoved = '1';
              cleared.querySelectorAll('[data-roster-employee]').forEach((chip) => chip.remove());
              syncCellState(cleared);
              return;
            }
            const remove = event.target.closest('[data-roster-remove]');
            if (!remove) return;
            const chip = remove.closest('[data-roster-employee]');
            const cell = remove.closest('[data-roster-cell]');
            chip?.remove();
            // Removing the last employee empties the shift, it does not delete it:
            // the cell keeps its times and its stored-shift origin, so the week
            // still carries this shift with no assignments.
            syncCellState(cell);
          });

          root.querySelectorAll('[data-roster-save]').forEach((button) => {
            button.addEventListener('click', () => queueWeek(button.dataset.rosterSave || 'draft'));
          });
        }

        updateQueueBadge();
        updateConnectivity();
        window.addEventListener('online', () => {
          cancelRetry();
          retryDelay = RETRY_BASE_MS;
          updateConnectivity();
          flushQueue();
        });
        window.addEventListener('offline', () => {
          cancelRetry();
          updateConnectivity();
        });
        flushQueue();
      });
    },
  };
})(Drupal, once);
