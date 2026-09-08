(function (Drupal, once) {
  'use strict';

  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  })[char]);

  Drupal.behaviors.merdposLegacyMigration = {
    attach(context) {
      once('merdpos-legacy-migration', '[data-merdpos-admin]', context).forEach((root) => {
        if (root.dataset.canManageLegacy !== '1') return;
        const endpoint = root.dataset.legacyUrl || '';
        const token = root.dataset.legacyToken || '';
        const dialog = root.querySelector('[data-legacy-dialog]');
        const body = root.querySelector('[data-legacy-body]');
        const title = root.querySelector('[data-legacy-title]');
        if (!endpoint || !token || !dialog || !body || !title) return;
        let state = null;

        const request = async (method, clientId, payload = null) => {
          const options = {method, credentials: 'same-origin', headers: {'Accept': 'application/json'}};
          let url = endpoint;
          if (method === 'GET') {
            const target = new URL(endpoint, window.location.origin);
            target.searchParams.set('client_id', String(clientId));
            url = target.toString();
          }
          else {
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-MERDPOS-CSRF'] = token;
            options.body = JSON.stringify(payload || {});
          }
          const response = await fetch(url, options);
          const data = await response.json().catch(() => null);
          if (!data || data.success !== true) {
            throw new Error(data?.error || `MERDPOS migration request failed (${response.status}).`);
          }
          return data;
        };

        const sourceDefaults = (type) => {
          const existing = state?.sources?.[type];
          if (existing) return existing;
          if (type === 'attendance') return {
            spreadsheet_id: state?.suggestions?.attendance_spreadsheet_id || '',
            sheet_names: state?.suggestions?.attendance_sheets || {
              timesheet: 'Time Sheet', payrate: 'PayRate', start_time: 'Start Time', employee_setup: 'Employee Setup',
            },
          };
          return {
            spreadsheet_id: state?.suggestions?.financial_spreadsheet_id || '',
            sheet_names: state?.suggestions?.financial_sheets || [],
          };
        };
        const authority = (value) => String(value || 'google_legacy');
        const authorityLabel = (value) => authority(value) === 'merdpos_sql' ? 'MERDPOS SQL' : 'Google legacy';
        const batchBadge = (status) => `<span class="merdpos-legacy-badge ${esc(status)}">${esc(String(status || '').replaceAll('_', ' '))}</span>`;
        const showResult = (message, error = false) => {
          const node = body.querySelector('[data-legacy-result]');
          if (!node) return;
          node.textContent = message || '';
          node.classList.toggle('is-error', error);
          node.hidden = !message;
        };

        const render = () => {
          if (!state) return;
          const attendance = sourceDefaults('attendance');
          const financial = sourceDefaults('financial');
          const migration = state.migration_state || {};
          const counts = state.record_counts || {};
          const batches = Array.isArray(state.recent_batches) ? state.recent_batches : [];
          const conflicts = Array.isArray(state.open_conflicts) ? state.open_conflicts : [];
          const sqlLocked = authority(migration.attendance_authority) === 'merdpos_sql'
            || authority(migration.financial_authority) === 'merdpos_sql';
          const financialTabs = Array.isArray(financial.sheet_names) ? financial.sheet_names.join('\n') : '';
          body.innerHTML = `
            <div class="merdpos-legacy-status-grid">
              <div><span>Attendance authority</span><strong>${esc(authorityLabel(migration.attendance_authority))}</strong></div>
              <div><span>Financial authority</span><strong>${esc(authorityLabel(migration.financial_authority))}</strong></div>
              <div><span>SQL attendance rows</span><strong>${Number(counts.employee_logs || 0).toLocaleString()}</strong></div>
              <div><span>SQL financial submissions</span><strong>${Number(counts.financial_submissions || 0).toLocaleString()}</strong></div>
            </div>`;
          body.insertAdjacentHTML('beforeend', `
            <section class="merdpos-legacy-section">
              <h3>Legacy Google sources</h3>
              <p>Spreadsheet IDs and tab names are stored per client. MERDPOS constructs the Google URL server-side; arbitrary URLs and credentials are never accepted.</p>
              <form data-legacy-sources-form>
                <div class="merdpos-legacy-source-grid">
                  <label>Attendance Spreadsheet ID<input name="attendance_spreadsheet_id" value="${esc(attendance.spreadsheet_id || '')}" autocomplete="off" spellcheck="false" required></label>
                  <div></div>
                  <div class="merdpos-legacy-tabs-grid is-wide">
                    <label>Time Sheet tab<input name="timesheet" value="${esc(attendance.sheet_names?.timesheet || 'Time Sheet')}" required></label>
                    <label>PayRate tab<input name="payrate" value="${esc(attendance.sheet_names?.payrate || 'PayRate')}" required></label>
                    <label>Start Time tab<input name="start_time" value="${esc(attendance.sheet_names?.start_time || 'Start Time')}" required></label>
                    <label>Employee Setup tab<input name="employee_setup" value="${esc(attendance.sheet_names?.employee_setup || 'Employee Setup')}" required></label>
                  </div>
                  <label class="is-wide">Financial Spreadsheet ID<input name="financial_spreadsheet_id" value="${esc(financial.spreadsheet_id || '')}" autocomplete="off" spellcheck="false" placeholder="Leave blank until the Financial source is configured"></label>
                  <label class="is-wide">Financial tab names<textarea name="financial_sheets" placeholder="One tab per line, e.g. Cash In&#10;Cash Out&#10;Z Report">${esc(financialTabs)}</textarea><small>MERDPOS auto-detects opening, Cash IN, Cash OUT and Z/closing rows from the tab name or a type/action column.</small></label>
                </div>
                <div class="merdpos-legacy-actions"><button class="is-primary" type="submit">Save sources</button><span>Saving configuration does not copy any Sheet data.</span></div>
              </form>
            </section>`);
          body.insertAdjacentHTML('beforeend', `
            <section class="merdpos-legacy-section">
              <h3>Migration control</h3>
              <p>Preview stages and validates source rows without changing operational data. Sync applies only safe/idempotent rows. Final Sync permanently makes SQL authoritative.</p>
              <div class="merdpos-legacy-guard">Existing SQL employee passwords are never overwritten. Staged payloads redact password/PIN/secret fields. Changed imported attendance rows update only when MERDPOS can prove the target was not modified elsewhere. Imported financial ledger records are immutable; a changed source row becomes a conflict.</div>
              <div class="merdpos-legacy-actions is-spaced">
                <button type="button" data-legacy-run="preview">Preview changes</button>
                <button class="is-primary" type="button" data-legacy-run="sync" ${sqlLocked ? 'disabled' : ''}>Sync legacy data</button>
                <button class="is-danger" type="button" data-legacy-run="final" ${sqlLocked || !financial.spreadsheet_id ? 'disabled' : ''}>Final Sync &amp; switch to SQL</button>
              </div>
              <div class="merdpos-legacy-result" data-legacy-result hidden></div>
            </section>`);

          body.insertAdjacentHTML('beforeend', `
            <section class="merdpos-legacy-section">
              <h3>Open conflicts</h3>
              <p>Conflicts are fail-closed. MERDPOS will not silently choose between a legacy Sheet value and a different SQL value.</p>
              <div class="merdpos-legacy-conflicts">${conflicts.length ? conflicts.map((row) => `
                <div><strong>${esc(row.conflict_code)} &middot; ${esc(row.source_type)}</strong><small>${esc(row.message)}</small><small>Batch ${esc(row.batch_id)} &middot; ${esc(row.created_at)}</small></div>`).join('') : '<div class="merdpos-admin-empty">No open migration conflicts.</div>'}</div>
            </section>`);
          body.insertAdjacentHTML('beforeend', `
            <section class="merdpos-legacy-section">
              <h3>Migration history</h3>
              <p>Every preview, sync and final cutover is retained as an auditable batch.</p>
              <div class="merdpos-legacy-table-wrap"><table><thead><tr><th>Started</th><th>Mode</th><th>Status</th><th>Attendance</th><th>Financial</th><th>Inserted</th><th>Updated</th><th>Unchanged</th><th>Conflicts</th><th>Rejected</th></tr></thead><tbody>${batches.length ? batches.map((row) => `
                <tr><td>${esc(row.started_at)}</td><td>${esc(row.mode)}</td><td>${batchBadge(row.status)}</td><td>${Number(row.attendance_rows || 0)}</td><td>${Number(row.financial_rows || 0)}</td><td>${Number(row.inserted_rows || 0)}</td><td>${Number(row.updated_rows || 0)}</td><td>${Number(row.unchanged_rows || 0)}</td><td>${Number(row.conflict_rows || 0)}</td><td>${Number(row.rejected_rows || 0)}</td></tr>`).join('') : '<tr><td colspan="10">No migration batches yet.</td></tr>'}</tbody></table></div>
            </section>
            <p class="merdpos-legacy-foot">After final cutover, Google remains available for Preview/history only; it cannot apply changes over MERDPOS SQL.</p>`);

          body.querySelector('[data-legacy-sources-form]')?.addEventListener('submit', saveSources);
          body.querySelectorAll('[data-legacy-run]').forEach((button) => button.addEventListener('click', () => run(button.dataset.legacyRun || '')));
        };

        const saveSources = async (event) => {
          event.preventDefault();
          if (!state) return;
          const form = event.currentTarget;
          if (!form.checkValidity()) { form.reportValidity(); return; }
          const button = form.querySelector('[type="submit"]');
          if (button) button.disabled = true;
          const financialSheets = String(form.elements.financial_sheets.value || '').split(/[\n,]+/).map((value) => value.trim()).filter(Boolean);
          try {
            state = await request('POST', state.client.id, {
              action: 'save_sources', client_id: state.client.id,
              attendance_spreadsheet_id: form.elements.attendance_spreadsheet_id.value,
              attendance_sheets: {
                timesheet: form.elements.timesheet.value,
                payrate: form.elements.payrate.value,
                start_time: form.elements.start_time.value,
                employee_setup: form.elements.employee_setup.value,
              },
              financial_spreadsheet_id: form.elements.financial_spreadsheet_id.value,
              financial_sheets: financialSheets,
            });
            const message = state.message || 'Legacy Google sources saved. No source data was copied yet.';
            render();
            showResult(message, false);
          }
          catch (error) { showResult(error.message, true); }
          finally { if (button && button.isConnected) button.disabled = false; }
        };

        const run = async (mode) => {
          if (!state || !['preview', 'sync', 'final'].includes(mode)) return;
          if (mode === 'sync' && !window.confirm('Sync validated legacy rows into MERDPOS SQL? Existing native/manual changes will not be silently overwritten.')) return;
          let confirmationClientCode = '';
          if (mode === 'final') {
            const expected = String(state.client?.client_code || '');
            confirmationClientCode = window.prompt(`Final cutover makes MERDPOS SQL authoritative and prevents future Google overwrites. Type ${expected} to continue.`) || '';
            if (confirmationClientCode !== expected) { showResult('Final cutover cancelled: Client Code did not match.', true); return; }
          }
          const buttons = [...body.querySelectorAll('[data-legacy-run]')];
          buttons.forEach((button) => { button.disabled = true; });
          showResult(mode === 'preview' ? 'Fetching and validating both legacy sources...' : mode === 'final' ? 'Running final reconciliation and cutover...' : 'Syncing validated legacy rows...', false);
          try {
            const payload = {action: mode, client_id: state.client.id};
            if (mode === 'final') payload.confirmation_client_code = confirmationClientCode;
            state = await request('POST', state.client.id, payload);
            const batch = state.batch_result || {};
            const summary = `${state.message || 'Complete'} Batch ${batch.batch_id || ''}: ${Number(batch.inserted || 0)} inserted, ${Number(batch.updated || 0)} updated, ${Number(batch.unchanged || 0)} unchanged, ${Number(batch.conflicts || 0)} conflicts, ${Number(batch.rejected || 0)} rejected.`;
            render();
            showResult(summary, Number(batch.conflicts || 0) > 0 || Number(batch.rejected || 0) > 0);
          }
          catch (error) {
            render();
            showResult(error.message, true);
          }
        };

        const open = async (clientId) => {
          state = null;
          body.innerHTML = '<div class="merdpos-admin-empty">Loading migration state&hellip;</div>';
          const clientButton = root.querySelector(`[data-legacy-open="${clientId}"]`);
          const code = clientButton?.dataset.legacyClientCode || 'Client';
          title.textContent = `Legacy migration \u00b7 ${code}`;
          if (!dialog.open) dialog.showModal();
          try {
            state = await request('GET', clientId);
            title.textContent = `Legacy migration \u00b7 ${state.client?.name || 'Client'}`;
            render();
          }
          catch (error) { body.innerHTML = `<div class="merdpos-admin-error">${esc(error.message)}</div>`; }
        };
        root.querySelectorAll('[data-legacy-open]').forEach((button) => {
          button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            const clientId = Number(button.dataset.legacyOpen || 0);
            if (clientId > 0) open(clientId);
          });
        });
        root.querySelectorAll('[data-legacy-close]').forEach((button) => button.addEventListener('click', () => dialog.close()));
        dialog.addEventListener('click', (event) => {
          if (event.target === dialog) dialog.close();
        });
      });
    },
  };
})(Drupal, once);
