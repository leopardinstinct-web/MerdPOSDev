# Application Requirements — POS LATEST / MerdPOS

Reconciled through merge commit `11f22a1`. Requirements marked **requires decision**
must be resolved before related implementation; do not guess.

## Scope

Build a production-ready Flutter Android POS, PHP/MySQL API, and browser admin
for multi-store retail. Preserve existing Timesheet/payroll behavior, but do
not add, redesign, migrate, or refactor Timesheet features unless required to
prevent an approved change from causing a regression.

## Authentication and sessions

- Employee User ID and PIN/password are numeric strings; no email login.
- Backend login is mandatory. Flutter must never download password fields.
- Store secrets with `password_hash()` and verify with `password_verify()`.
- Legacy plaintext may be accepted only once for immediate transparent rehash.
- Login and password change require durable per-user/per-device throttling.
- Device, client, store, and active employee authorization must be enforced.
- Support one persistent primary and one temporary secondary employee.
- A third visible user is prohibited.
- Promoting secondary to primary must not clock either employee in or out.

## Setup and device activation

- Setup must establish client/store/device authorization before staff login.
- Activation tokens are bearer secrets: HTTPS only, secure storage, never logs.
- Token lifetime, expiry, rotation, revocation, device replacement, and setup
  authorization are **requires decision**.
- App reset must not silently create an unauthorized device identity.

## Retail application

- Follow the approved catalogue policy in `M2_CATALOGUE_DECISIONS.md`.
- Products use immutable server IDs. Optional SKUs are client-unique when
  present, and products may have zero or multiple barcode aliases.
- Product/category identity and tax assignment are client-global; availability,
  effective selling price, stock, and reorder level may be store-specific.
- Authoritative multi-store product catalogue with barcode, SKU, category,
  price, cost, tax, status, and store-specific price/stock where approved.
- Barcode/name/category search and scanner-friendly input.
- Offline basket with stock validation and quantity controls.
- Record cash and card tenders. Payment-terminal integration is
  **requires decision**.
- Durable completed order history with line details and synchronization state.
- Inventory stock movements for sales, adjustments, receiving, and later
  approved transfers/counts.
- Same-day and historical financial summaries appropriate to employee role.
- Demonstration data must never appear in production mode.

## Offline-first and synchronization

- Initial catalogue synchronization is a full snapshot followed by transactional,
  replay-safe pages using an opaque server-issued monotonic cursor.
- Devices advance the cursor only after commit and preserve the last working
  catalogue on failure. Offline catalogue creation is prohibited in M2.
- Core selling must work without connectivity after an initial authorized sync.
- Local writes must be transactional, idempotent, retryable, and auditable.
- Server returns per-record acknowledgement/rejection; client marks only
  acknowledged records synced.
- Products, prices, tax, and stock require server-to-device synchronization.
- Completed offline sales remain accepted. Resulting negative server-ledger
  stock is flagged for review rather than rewriting or rejecting the sale.
- Remaining duplicate-sale, clock-skew, and device-replacement conflict rules
  are **requires decision** where not already governed by idempotency contracts.
- Sync must be tenant/store scoped and expose actionable non-secret status.

## Admin portal

- ADMIN-only authenticated sessions with CSRF, inactivity timeout, output
  escaping, authorization, and audit logging.
- Manage employees/roles, stores, categories, products, store inventory and
  pricing, suppliers, purchasing, sales, reports, devices, and settings.
- Role/permission matrix and destructive-action policy are **requires decision**.
- Sensitive changes must create auditable before/after context without secrets.

## Security

- Follow `SECURITY.md` for every backend/auth change.
- Prepared statements for every external value.
- Strict input validation and tenant isolation.
- Generic client errors; detailed server logs without secrets.
- No wildcard CORS on sensitive endpoints.
- Debug/init/import utilities must not be publicly available in production.
- Secrets must not be committed. `config.php`, `.env`, deployment markers,
  build outputs, and APKs remain excluded.

## Design and device experience

- Follow `DESIGN_TOKENS.md`; use shared theme/components.
- Dark-mode-first Blue Ice design and accessible contrast.
- Android landscape and dual-display requirements are **requires decision**
  pending the exact terminal hardware and customer-display behavior.
- Production package ID, signing, versioning, and backup policy are mandatory.

## Quality and delivery

- Work on a separate approved branch.
- Define acceptance criteria before coding.
- Provide complete replacement files for changed code.
- Run Flutter analysis/tests, PHP lint/tests, and Android build checks in CI or
  an approved non-production environment.
- Update contracts, changelog, manifest, status, and handover docs with changes.
- No commit, push, merge, migration, deployment, credential use, or production
  data change without the corresponding explicit approval.
