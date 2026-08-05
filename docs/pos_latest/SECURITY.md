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
2. `employee_auth_attempts` migration is absent. The application silently skips
   lockout when the table does not exist.
3. Activation issues/rotates a token without proving the setup secret.
4. Token lifetime, expiry, rotation, and revocation are undefined.
5. Flutter stores the token in plain `SharedPreferences`.
6. Sensitive APIs use wildcard CORS.
7. Several legacy/import/init endpoints do not enforce a request method or have
   complete device/actor authorization.
8. Debug/test/init/import endpoints are stored beside production endpoints.
9. Release builds use debug signing and an example application ID.
10. No automated security or tenant-isolation tests are present.

## Password and PIN policy

- Current backend hashing behavior is confirmed; it is no longer “unconfirmed.”
- Minimum numeric PIN length is currently four digits.
- Raising minimum length or permitting longer numeric PINs is
  **requires decision**.
- Rate-limit policy (threshold, cooldown, reset, administrator unlock, audit)
  is **requires decision**. Until implemented durably, numeric login is not
  considered adequately protected.

## Device activation policy

Required decisions:

- setup proof and who may activate;
- token lifetime and refresh;
- revocation and lost-device process;
- reactivation/rotation behavior;
- device inventory visibility and secret redaction;
- secure storage and migration for installed devices.

No new activation scheme may be invented before these decisions are approved.

## API and deployment policy

- Public production deployment must exclude or access-restrict debug, test,
  initialization, and import utilities.
- Plain HTTP must be rejected or redirected at the web-server/application edge.
- CORS origins require an approved client-origin model. Native Android does not
  require wildcard browser CORS.
- API errors must not return SQL, exception text, stack traces, paths, database
  shape, secrets, or full personal/payroll payloads.
- Production logging/retention/redaction policy is **requires decision**.

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
