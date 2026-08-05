# API Contract — POS LATEST / MerdPOS

Source reconciliation: commit `29de6f4`, 2026-08-05. This describes checked-in
code, not verified production behavior. No production API was called.

## Base and transport

- Compiled Flutter base URL: `https://app.merdpos.com/api`
- Format: JSON over HTTPS.
- Error envelopes are inconsistent across legacy endpoints; standardization is
  required without breaking deployed clients.
- CORS policy is inconsistent and wildcard CORS exists on sensitive endpoints.

## Device token model

`activate_device.php` creates a 32-byte random token represented as hex and
upserts it by device UUID. Current device-authenticated endpoints compare some
combination of client, store, UUID, token, and active status.

The following are **requires decision**:

- proof required to activate/re-activate a device;
- expiry and refresh;
- explicit revocation;
- token rotation and overlap;
- lost/replaced device handling;
- whether every endpoint must bind the UUID as well as the token.

Until decided, treat the token as a permanent bearer secret and do not expand
the activation design by assumption.

## Flutter-used endpoints

### `get_stores.php`

- Method in source: GET.
- Input: `client_code`, `setup_key` query parameters.
- Output: `success`, `client {id,name,client_code}`, `stores
  [{id,store_name,store_code}]`, or `error`.
- Current issue: setup secret is placed in a URL and PHP error display is on.

### `activate_device.php`

- Intended method: POST JSON; source does not enforce the method.
- Input: `client_id`, `store_id`, `device_uuid`, optional `device_name`.
- Output: `success`, `activation_token`.
- Current issue: the endpoint does not verify company setup authorization when
  issuing/rotating a token.

### `get_employees.php`

- Method: GET (OPTIONS supported).
- Input: `client_id`, `store_id`, `activation_token`.
- Auth: active device matching client/store/token; UUID is not required.
- Output: `success`, version `client-wide-employees-v3-no-passwords`, and
  client-wide active employee records with no password/PIN fields.

### `login.php`

- Method: POST JSON (OPTIONS supported).
- Version: `hashed-login-v1`.
- Input: `client_id`, `store_id`, `device_uuid`, `activation_token`, numeric
  `user_id`, numeric `password` of at least four digits.
- Auth: active device matching client/store/UUID/token; active client employee.
- Password: verify supported hash or legacy plaintext; successful legacy login
  upgrades both secret columns with `password_hash()`.
- Output: sanitized employee record plus password-storage/migration metadata.
- Lockout: conditional on `employee_auth_attempts` existing. Its migration is
  missing at this commit, so protection is not guaranteed.

### `change_password.php`

- Method: POST JSON (OPTIONS supported).
- Version: `hashed-password-change-v2`.
- Input: device credentials, `employee_id`, numeric `old_password`, numeric
  `new_password` of at least four digits.
- Stores one `password_hash()` in both legacy secret fields.
- Lockout has the same missing-table limitation as login.

### `get_timesheet.php`

- Existing endpoint only; preserve behavior and keep outside new roadmap.
- Source marker: `payroll-rounded-v7-auto-db-resolver-pdf-match`.
- Current source does not validate the activation token passed by Flutter.
- Do not redesign this contract during non-Timesheet work.

### `sync_retail.php`

- Method: POST JSON (OPTIONS supported).
- Input: client/store/device credentials, `sales[]`, `stock_movements[]`.
- Auth: active device matching client/store/UUID/token.
- Behavior: transactionally inserts idempotent retail sales, lines, and stock
  movements using prepared statements.
- Output: `success`, version `retail-sync-v1`, aggregate synchronized counts.
- Gap: no per-record acknowledgement/rejection and no inbound master data.

## Other current endpoints

- `get_working_now.php` — device-authorized working-employee query.
- `sync_employee_logs.php` — employee-log upload; no enforced HTTP method.
- `sync_shifts.php` — shift upload; no enforced HTTP method.
- `import_actual_employees.php` — import utility; authorization requires review.
- `import_timesheet_logs.php` — import utility; authorization requires review.
- `init_employee_logs.php` — schema utility; authorization/deployment review.
- `init_db.php` — database initialization utility; do not run without approval.
- `cors_test.php` — debug utility; not for public production deployment.
- `test_activate.php` — debug activation utility; not for production.
- `index.php` — API landing response.
- `version_check.php` — reports deployment marker, time, PHP version, status.

## Admin web

`backend/admin/` uses PHP form posts and sessions rather than JSON REST.
Routes are handled through `admin/index.php?page=...` with CSRF verification for
mutations. Admin v1 scope is documented in `PROJECT_CONTEXT.md`.

## Contract work required

1. Decide activation-token lifecycle and setup proof.
2. Define a versioned standard success/error envelope.
3. Document every non-Timesheet utility endpoint or remove it from deploys.
4. Define product/price/stock download and incremental-sync contracts.
5. Define per-record retail acknowledgement and conflict responses.
6. Define API compatibility/versioning and deprecation policy.
