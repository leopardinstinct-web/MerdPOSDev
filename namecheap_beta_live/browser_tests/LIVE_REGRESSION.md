# MERDPOS live regression

The authenticated live audit is intentionally separate from CI credentials. Never commit browser storage state, cookies, passwords, or audit output.

## Developer live audit

Set `MERDPOS_AUTH_STATE` to a local Playwright storage-state JSON created after a one-time manual login, then run:

```bash
node namecheap_beta_live/browser_tests/live-authenticated-audit.js
```

Optional environment variables:

- `MERDPOS_BASE_URL` — defaults to the live beta portal.
- `MERDPOS_AUDIT_OUTPUT` — output directory for screenshots/report JSON.
- `MERDPOS_AUDIT_PROFILE` — currently only `developer` is defined.

## DUMMY destructive core regression

`live-dummy-core-transactions.js` is the destructive business-flow regression runner. It must only run with a Developer session that can switch the working client to the exact `DUMMY` client. The runner aborts if the switch does not land on DUMMY.

It verifies DUMMY-scoped store create/edit/deactivate, employee create/edit/pay-rate/credential-reset/deactivate, role create/edit/delete, a permission LOA change followed by restoration, DUMMY dispute read access, desktop report/panel surfaces, mobile 390x844 surfaces, browser runtime errors, failed portal HTTP responses, and final DUMMY context preservation.

Run locally with an external storage-state file:

```bash
MERDPOS_AUTH_STATE=/absolute/path/auth-state.json \
node namecheap_beta_live/browser_tests/live-dummy-core-transactions.js
```

A manual GitHub Actions workflow is also defined in `.github/workflows/beta-live-dummy-regression.yml`. It deliberately uses `workflow_dispatch`; destructive DUMMY mutations are not run automatically on every pull request. The workflow expects repository secret `MERDPOS_AUTH_STATE_B64`, containing base64-encoded Playwright storage state. The secret is materialized only in the runner temp directory and removed in an `always()` cleanup step.

## DUMMY financial regression

`live-dummy-financial-transaction.js` covers the isolated DUMMY Financial flow: Open Day, Cash In, Cash Out, Z Report, and statement verification. It also requires external `MERDPOS_AUTH_STATE` and must never be pointed at MERD production data.

## Capture current authorization policy

With the same external auth state:

```bash
node namecheap_beta_live/browser_tests/capture-live-policy.js
```

The capture reads `role_authority.php` and records the current role LOAs and all named permission thresholds. This is the source for building USER, ADMIN, SUPER, DEV and custom-permission regression profiles.

## Safety

The general live authenticated audit remains read-only. It must not open/close financial days, submit finance entries, edit stores, employees, roles, clients, defaults, passwords, or permission policy.

Mutation coverage belongs only in the disposable DUMMY tenant/store. Attendance and employee-owned dispute creation are not DUMMY-safe through the current portal authentication model and remain excluded from destructive verification until DUMMY-native authentication exists.
