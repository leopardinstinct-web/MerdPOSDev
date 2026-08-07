# API Contract — POS LATEST / MerdPOS

Source reconciliation baseline: merge commit `561a551`, 2026-08-05. This
describes checked-in code, not verified production behavior. No production API
was called.

## Base and transport

- Compiled Flutter base URL: `https://app.merdpos.com/api`
- Format: JSON over HTTPS.
- Error envelopes are inconsistent across legacy endpoints; standardization is
  required without breaking deployed clients.
- CORS policy is inconsistent and wildcard CORS exists on sensitive endpoints.

## Device token model

Milestones 2A.2 and 2A.3 integrate the shared security foundation across the
non-Timesheet device endpoint set. Deployment remains blocked until the
approved migrations are reconciled and separately authorized.

The approved target contract uses a dedicated POST
`request_activation_grant.php` for a hashed, single-use ten-minute grant. New
bearer tokens are stored only as SHA-256 hashes, expire after 180 days, revoke
immediately, and may retain the previous hash for a seven-day overlap. Every
authenticated request binds client, store, UUID, token hash, active status, and
non-revoked state. The shared device-auth helper alone accepts legacy
`activation_token` transport for two application releases.

Milestone 2B clients prefer `Authorization: Bearer <token>` on authenticated
non-Timesheet endpoints. Device-authorization 401 codes mark the local session
for controlled reactivation without deleting the retained compatibility token.

`get_stores.php` remains the legacy compatibility endpoint and is not expanded
to issue grants.

### `request_activation_grant.php`

- Method/content: POST with `application/json` only.
- Input: `client_code`, `setup_key`.
- Success: client display data, active eligible stores, one plaintext
  `activation_grant`, and `grant_expires_at`.
- Security: the grant is random, stored only as SHA-256, client-bound,
  single-use, and valid for ten minutes. Neither setup key nor grant is logged.

## Flutter-used endpoints

### `get_stores.php`

- Method in source: GET.
- Input: `client_code`, `setup_key` query parameters.
- Output: `success`, `client {id,name,client_code}`, `stores
  [{id,store_name,store_code}]`, or `error`.
- Current issue: setup secret is placed in a URL and PHP error display is on.

### `activate_device.php`

- Method/content: POST with `application/json` only.
- Input: `client_id`, selected `store_id`, `activation_grant`, `device_uuid`,
  optional `device_name`.
- Success: plaintext `activation_token` returned once, `token_type: Bearer`,
  and a 180-day expiry.
- Security: grant consumption and token persistence are atomic; only the token
  hash is stored. The selected store must belong to the grant's client.
  Rotation retains the previous hash for seven days.

### `get_employees.php`

- Method: GET (OPTIONS supported).
- Input: `client_id`, `store_id`, `device_uuid`; bearer token preferred with
  legacy transport accepted only by the shared helper.
- Auth: hash, client/store/UUID, status, expiry, and revocation are enforced.
- Output: `success`, version `client-wide-employees-v3-no-passwords`, and
  client-wide active employee records with no password/PIN fields.

### `login.php`

- Method: POST JSON (OPTIONS supported).
- Version: `secure-login-v2`.
- Input: `client_id`, `store_id`, `device_uuid`, `activation_token`, numeric
  `user_id`, numeric `password` of at least four digits.
- Auth: bearer preferred; the shared helper alone accepts the legacy body token.
  Authorization binds hash, client/store/UUID, active state, revocation, and
  expiry. Employees remain client-wide and must be active.
- Password: verify supported hash or legacy plaintext; successful legacy login
  upgrades both secret columns with `password_hash()`.
- Output: sanitized employee record plus password-storage/migration metadata.
- Lockout: fail-closed five-attempt per-device and fifteen-attempt employee-wide
  policy through the shared durable lockout service.

### `change_password.php`

- Method: POST JSON (OPTIONS supported).
- Version: `secure-password-change-v3`.
- Input: device credentials, `employee_id`, numeric `old_password`, numeric
  `new_password` of at least four digits.
- Stores one `password_hash()` in both legacy secret fields.
- Uses the same device authorization and fail-closed lockout foundation as
  login, with its own `change_password` counter.

### `get_timesheet.php`

- Existing endpoint only; preserve behavior and keep outside new roadmap.
- Source marker: `payroll-rounded-v7-auto-db-resolver-pdf-match`.
- Current source does not validate the activation token passed by Flutter.
- Do not redesign this contract during non-Timesheet work.

### `sync_retail.php`

- Method: POST JSON (OPTIONS supported).
- Input: client/store/device credentials, `sales[]`, `stock_movements[]`.
- M2.7 stock movements include an exact `quantity_decimal` and receive a
  per-record `accepted`, `duplicate`, or `rejected` acknowledgement under
  `m2.stock.sync.v1`. Accepted/duplicate outcomes include the authoritative
  balance, revision, and negative-stock exception state.
- Auth: active device matching client/store/UUID/token.
- Behavior: transactionally inserts idempotent retail sales, lines, and stock
  movements using prepared statements.
- Output: `success`, version `retail-sync-v3-stock-convergence`, aggregate
  synchronized counts, contract version, and per-record movement outcomes.

### `sync_catalogue.php`

- Method: POST JSON (OPTIONS supported).
- Auth: existing shared device authorization and bearer/centralized legacy
  compatibility transport; generic unauthorized failures are unchanged.
- Input: `contract_version: m2.catalogue.full.v1`, `snapshot_type: full`, and
  existing device lookup identifiers. Server-side matched device scope wins.
- Output: standard success/error envelope, deterministic store-scoped full
  snapshot, content revision, opaque future seed, UTC generation time, currency,
  categories, products/barcodes/lifecycle/units, effective and resolved
  price/tax, M2.3 stock/exception state, and explicit configuration warnings.
- Exact schema and compatibility rules: `M2_4_CATALOGUE_SNAPSHOT_SCHEMA.md`.
- Boundary: read-only; no paging/incremental cursor, `last_sync` mutation,
  Flutter/SQLite application, checkout change, or outbound-sync change.

## Other current endpoints

- `get_working_now.php` — secure device-authorized client-wide query.
- `sync_employee_logs.php`, `sync_shifts.php`, `sync_retail.php` — POST JSON,
  secure device authorization, tenant-scoped actor/data checks, transactional
  writes, and tenant-bound device `last_sync` updates.
- Import/init/test/debug utilities deny by default before loading configuration
  through `maintenance_guard.php`; production deployment should still exclude
  them.
- `index.php` — API landing response.
- `version_check.php` — reports deployment marker, time, PHP version, status.

## Admin web

`backend/admin/` uses PHP form posts and sessions rather than JSON REST.
Routes are handled through `admin/index.php?page=...` with CSRF verification for
mutations. Admin v1 scope is documented in `PROJECT_CONTEXT.md`.

## Contract work required

1. Define a versioned standard success/error envelope.
2. Document every non-Timesheet utility endpoint or remove it from deploys.
3. Implement the approved M2.4 full-snapshot contract on devices and separately
   define M2.6 incremental cursor consumption/expiry semantics.
4. Define per-record retail acknowledgement and conflict responses.
5. Define API compatibility/versioning and deprecation policy.

## M2.2 pricing/tax contract boundary

The M2.2 source foundation stores catalogue prices at four decimal places,
finalized monetary snapshots at two decimal places, and tax rates as integer
basis points. Future APIs must transmit money as decimal strings and UTC times
with explicit offsets. No current endpoint reads or writes the M2.2 shadow
tables; runtime contract integration requires separate approval.

## M2.3 stock contract boundary

Migration 018 defines shadow ledger movement meanings, stable client/store
idempotency and source identities, accepted-server ordering, and maintained
balance revisions. A future writer that receives a duplicate must look up and
return the original movement/balance result rather than creating a second stock
effect. Device event time is audit data and does not override server acceptance
order. Current `sync_retail.php` continues writing only the legacy movement
table and does not read or write M2.3 structures. Runtime integration,
per-record acknowledgement, reconciliation, and cutover require separate
approval.

## Milestone 1 CI boundary

Milestone 1 does not change endpoint behavior or `version_check.php`. PHP CI
uses syntax parsing only and never executes endpoint files. App/API version
reporting remains a separate approved feature milestone after the CI foundation
passes.
