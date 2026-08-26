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

## Capture current authorization policy

With the same external auth state:

```bash
node namecheap_beta_live/browser_tests/capture-live-policy.js
```

The capture reads `role_authority.php` and records the current role LOAs and all named permission thresholds. This is the source for building USER, ADMIN, SUPER, DEV and custom-permission regression profiles.

## Safety

The live audit is read-only. It must not open/close financial days, submit finance entries, edit stores, employees, roles, clients, defaults, passwords, or permission policy. Mutation coverage belongs in a disposable test tenant/store.
