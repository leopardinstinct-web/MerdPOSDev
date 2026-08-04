# Security note

During source inspection, the committed `backend/api/config.sample.php` appeared to contain non-empty database values. Treat those values as exposed even if they are old. Replace the sample with empty placeholders, confirm the real DB password is rotated, and never include `config.php` or credentials in this ZIP or a commit.

The new `sync_retail.php`:
- accepts POST JSON only;
- validates client/store/device identifiers;
- verifies the existing active device token;
- uses prepared PDO statements;
- uses idempotent unique keys for retry-safe syncing;
- returns generic errors while logging server details.
