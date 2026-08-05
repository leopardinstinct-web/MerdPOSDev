# Security Requirements — POS LATEST / MerdPOS

Binding guidance reconciled against commit `29de6f4`. Source-confirmed gaps are
listed explicitly; historical claims do not override current files.

## Mandatory per-change gate

- No credentials, `config.php`, `.env`, deployment markers, APKs, or generated
  build/cache output in Git.
- Validate every external input by type, length, range, and allowed values.
- Parameterize every external value used in SQL.
- Enforce client/store/device/actor authorization appropriate to the action.
- Hash new PIN/password values with `password_hash()` and verify with
  `password_verify()`.
- Apply durable throttling to numeric-PIN verification.
- Return generic client errors; log safe operational detail server-side.
- Use HTTPS only and restrict CORS.
- Add authorization, negative-path, and tenant-isolation tests.

## Current confirmed controls

- `backend/api/config.php` and deployment markers are ignored.
- `login.php` and `change_password.php` use hashing and support transparent
  legacy plaintext migration.
- Employee-list responses omit password/PIN fields.
- Login/change password validate numeric credentials and active device records.
- Retail sync uses prepared PDO statements and a transaction.
- Admin v1 uses password verification, ADMIN checks, CSRF, session regeneration,
  inactivity timeout, output escaping, prepared statements, and audit logging.

## Current confirmed gaps

1. `config.sample.php` contains a real-looking non-empty value despite the
   documentation saying it is a blank template. Treat it as exposed if genuine;
   never reproduce it.
2. The 2A.1 branch adds a lockout migration draft and fail-closed shared
   service, but existing endpoints do not use it until 2A.2. Current endpoint
   code still silently skips lockout when the table does not exist.
3. Activation issues/rotates a token without proving the setup secret.
4. Token lifetime, expiry, rotation, and revocation are undefined.
5. Flutter stores the token in plain `SharedPreferences`.
6. Sensitive APIs use wildcard CORS.
7. Several legacy/import/init endpoints do not enforce a request method or have
   complete device/actor authorization.
8. Debug/test/init/import endpoints are stored beside production endpoints.
9. Release builds use debug signing and an example application ID.
10. Milestone 2A.1 adds deterministic foundation security tests; endpoint-level
    authorization and tenant-isolation integration tests remain 2A.2/2A.3.

## Approved Password and PIN policy

- Current backend hashing behavior is confirmed; it is no longer “unconfirmed.”
- Minimum numeric PIN length is currently four digits.
- Raising minimum length or permitting longer numeric PINs is
  **requires decision**.
- Five failures for client, user ID, device UUID, and action cause a 15-minute
  lock. Fifteen failures for the same client, user ID, and action across devices
  in a rolling 30-minute window cause a 15-minute employee-wide lock. Login and
  password-change actions remain separate. Missing persistence must fail closed.
- Future manual unlock requires a same-client authenticated SUPER actor, a
  reason, and an immutable redacted audit record. No unlock API or UI is part of
  2A.1.

## Approved device activation policy

- Dedicated POST setup validation issues a hashed, single-use ten-minute grant.
- New tokens are stored only as SHA-256 hashes and expire after 180 days.
- Rotation may accept the previous hash for seven days; there is no refresh
  token in Milestone 2. Revocation is immediate.
- Client, store, UUID, token hash, active status, and non-revoked state are
  mandatory authorization bindings.
- Legacy token transport is isolated to the shared device-auth helper for two
  application releases.

The 2A.1 code provides migrations and shared helpers only. Endpoint enforcement
begins in 2A.2 after review.

## Security logging

- Retain redacted security events for 90 days.
- Use `REMOTE_ADDR` only; forwarding headers remain untrusted unless a future
  trusted-proxy configuration explicitly says otherwise.
- Never retain tokens, grants, PINs, passwords, setup keys, payroll payloads,
  or full request bodies. User-agent and metadata values are bounded.
- Logging failures expose no internal detail and must not weaken required
  authentication controls.

## API and deployment policy

- Public production deployment must exclude or access-restrict debug, test,
  initialization, and import utilities.
- Plain HTTP must be rejected or redirected at the web-server/application edge.
- CORS origins require an approved client-origin model. Native Android does not
  require wildcard browser CORS.
- API errors must not return SQL, exception text, stack traces, paths, database
  shape, secrets, or full personal/payroll payloads.
- Security-event retention is approved at 90 days; production scheduling and
  migration execution still require separate deployment approval.

## Release security

- Use a production package ID and protected release signing key.
- Define Android backup/restore behavior for tokens and local sales.
- Store bearer secrets using Android-backed secure storage where available.
- Signing-key custody, CI secrets, artifact retention, and key rotation are
  **requires decision**.

## Prohibited actions

- Plaintext password storage/comparison in new code.
- SQL concatenation involving external values.
- Secret disclosure in code, documentation, logs, chat, tests, or commits.
- Unapproved production API/database access, migrations, or deployment.
- Weakening or bypassing existing authorization for convenience.
