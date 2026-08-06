# POS LATEST Product Roadmap — Draft

Status: M0 and M1 source implementation approved and merged; the eight M2
catalogue-policy decisions are approved, with contract details still gated.

Source baseline: merge commit `561a551`

Timesheet rule: preserve existing behavior; no new Timesheet development.

This roadmap turns the current Retail/Admin v1 foundation into a secure,
testable, offline-first MerdPOS release. “Card” means tender recording only
until payment-terminal integration is separately approved.

Complexity uses S/M/L/XL relative sizing, not calendar estimates.

## M0 — Trusted baseline and CI

**Objective:** Make source, documentation, tests, and artifacts reproducible.

**Scope:** Reconcile docs; resolve tracked sample secret; pin Flutter/Dart/Java
and Android versions; establish CI for analysis, tests, PHP lint, secret scan,
and debug/release-candidate Android builds; define artifact retention.

**Dependencies:** Product owner confirms documentation authority and credential
status; CI provider/repository permissions; signing is not required for initial
unsigned/debug CI artifacts.

**Acceptance criteria:** Docs match source; no tracked secret; CI runs on every
branch/PR; failures block merge; build artifacts are checksummed and retained
under an approved policy; production VPS is not used as an Android builder.

**Tests:** Markdown links/duplicate-state scan; secret scan; `flutter analyze`;
Flutter unit/widget tests; PHP lint; Android debug build; clean-clone workflow.

**Risks:** Historical secret remains in Git history; tool versions differ from
developer machines; CI minutes/storage policy.

**Estimated complexity:** M.

## M1 — Secure setup, activation, login, and sessions

**Status:** Source-complete through merged Milestones 2A.1–2A.3 and 2B;
production deployment-gated. Migrations 012–015 remain unexecuted, so
production activation, lockout, token lifecycle, and audit behavior are not
available until schema reconciliation, migration approval, and deployment.
The retained legacy SharedPreferences token is due for removal in the third
compatible release.

**Objective:** Establish a durable trust boundary before retail expansion.

**Scope:** Decide setup proof and activation-token lifecycle; enforce methods,
validation, tenant/device binding, expiry/revocation/rotation; add the
`employee_auth_attempts` migration and fail-safe throttling; move token to
secure storage; preserve numeric login, password migration, primary/secondary
session behavior, and existing Timesheet entry points.

**Dependencies:** Approved token and PIN-lockout policies; non-production DB;
secure-storage dependency approval; migration approval for later deployment.

**Acceptance criteria:** Unauthorized activation fails; revoked/expired tokens
fail; login/change password enforce cooldown; no password fields reach Flutter;
existing sessions behave identically; secrets never appear in logs/preferences;
tenant-crossing attempts fail.

**Tests:** Endpoint contract/negative tests; brute-force/cooldown/reset tests;
secure-storage migration tests; session promotion/logout widget tests; offline
relaunch tests; regression smoke test confirming existing Timesheet still opens.

**Risks:** Locking out legitimate terminals; breaking already activated devices;
shared-hosting clock/schema differences.

**Estimated complexity:** L.

## M2 — Canonical catalogue and inbound synchronization

**Status:** In progress. M2.1 supplies catalogue identity/lifecycle foundations;
M2.2 supplies the reviewed effective pricing/tax shadow schema. Neither changes
endpoint or Flutter behavior. Runtime integration, later M2 contracts, and
production schema reconciliation remain gated.

**Objective:** Replace demonstration data with authoritative store retail data.

**Scope:** Versioned product/category/store-price/tax/stock contracts; initial
download; incremental cursor/version sync; SQLite schema migrations; tombstones
for disabled products; explicit development-only demo mode.

**Dependencies:** Approved M2 catalogue decisions; M1 device trust; contract
detail approval; server schema verification; separately approved migrations.

Approved policy uses immutable server product IDs, a client-global master with
store overlays, flat client categories, effective-dated tax-inclusive pricing,
versioned product tax codes, a store stock ledger, explicit lifecycle states,
and full-then-cursor synchronization. `M2_CATALOGUE_DECISIONS.md` is binding;
remaining contract details must not be guessed.

**Acceptance criteria:** Clean authorized device loads correct store catalogue;
incremental changes apply idempotently; disabled products cannot be sold;
production contains no demo products; last-good catalogue works offline.

**Tests:** Initial/incremental sync; interrupted/retried sync; schema upgrade;
wrong-tenant payload; product disable/price/stock update; large catalogue
performance; offline search.

**Risks:** Source schema divergence; large sync payloads; stale price/stock;
identifier collisions.

**Estimated complexity:** XL.

## M3 — Barcode POS and durable sales

**Objective:** Complete reliable offline cash/card sale recording.

**Scope:** Scanner-friendly barcode input; basket; quantity/stock validation;
approved price/tax/discount rules; cash/card tender recording; durable sale and
line identifiers; receipt requirements; order detail/history; void/refund rules
only after separate decisions.

**Dependencies:** M2 catalogue; approved tax, discount, receipt, card, refund,
and stock-oversell policies; target scanner behavior.

**Acceptance criteria:** Sale commits atomically with stock movements; duplicate
submission cannot duplicate a sale; order detail reconstructs the sale; cash
and card tenders are distinguishable; restart/power loss does not lose a
completed transaction; unauthorized adjustments/discounts fail.

**Tests:** Barcode/keyboard scanner; repeated barcode; insufficient stock;
atomic rollback; process restart; duplicate ID; rounding/tax; cash/card;
role authorization; responsive landscape UI.

**Risks:** Payment semantics misunderstood; device clock drift; stock contention;
receipt/hardware scope expansion.

**Estimated complexity:** L.

## M4 — Bidirectional sync and conflict handling

**Objective:** Reconcile offline work without silent loss or false success.

**Scope:** Per-record acknowledgements; stable idempotency keys; retry/backoff;
rejected/dead-letter state; clock normalization; server-to-device sale/stock
updates; conflict policy and operator-visible resolution; sync health/status.

**Dependencies:** M1 trust, M2 versioning, M3 durable IDs; approved conflict
ownership rules and retention limits.

**Acceptance criteria:** Client marks only accepted records synced; retry never
duplicates; rejected records remain visible/actionable; stock converges under
the approved policy; sync can resume after interruption; support can diagnose
state without viewing secrets.

**Tests:** Partial acceptance; timeout after server commit; duplicated request;
out-of-order events; concurrent devices; clock skew; corrupted payload;
long-offline reconnect; network flapping.

**Risks:** Incorrect conflict policy changes inventory; unbounded queues;
shared-hosting limits; backward compatibility.

**Estimated complexity:** XL.

## M5 — Inventory, suppliers, and purchasing

**Objective:** Complete operational stock control beyond POS deductions.

**Scope:** Inventory movement ledger; adjustments with reasons/permissions;
suppliers; multi-line purchase orders; receiving/partial receiving; reorder
levels; approved counts/transfers; store prices; audit linkage.

**Dependencies:** M2 catalogue, M4 synchronization, approved roles and stock
valuation/negative-stock/transfer policies.

**Acceptance criteria:** Every quantity change has an immutable reason/reference;
PO totals and receipts are correct; partial receiving is idempotent; store stock
and reorder views reconcile; unauthorized changes fail and are audited.

**Tests:** Multi-line/partial/duplicate receipt; adjustment permissions;
concurrent receiving; supplier validation; reorder thresholds; stock ledger
reconciliation; offline restrictions.

**Risks:** Legacy stock inconsistencies; valuation ambiguity; destructive
corrections; concurrent operations.

**Estimated complexity:** XL.

## M6 — Admin portal, audit, financials, and reports

**Objective:** Provide secure management and decision-ready reporting.

**Scope:** Role/permission matrix; complete CRUD/status workflows; device
revocation; inventory/purchasing management; sales/order drill-down; daily and
historical summaries; tax/margin/store reports; export policy; immutable audit
events with safe before/after context.

**Dependencies:** M1 roles/token revocation, M3 sales, M5 inventory/purchasing;
approved reporting definitions and data-retention/export policies.

**Acceptance criteria:** Every route/action enforces permission and tenant;
reports reconcile with source transactions; sensitive actions are audited;
tokens/secrets are redacted; exports follow authorization and retention policy;
CSRF/session protections remain effective.

**Tests:** Role matrix; CSRF/session expiry; tenant isolation; report fixtures;
audit completeness/redaction; export authorization; accessibility/browser smoke.

**Risks:** Over-broad ADMIN access; expensive queries; privacy leakage;
report-definition disputes.

**Estimated complexity:** XL.

## M7 — Android terminal, release, deployment, and rollback

**Objective:** Produce a controlled, supportable production release.

**Scope:** Production application ID/signing/versioning; landscape/kiosk policy;
dual-screen customer display after hardware decision; secure backup behavior;
release CI; staged deployment; PHP/static artifact manifest; DB migration plan;
health checks; monitoring; backup and rollback runbooks.

**Dependencies:** Stable preceding milestones; target terminal/scanner/display;
signing-key custody; hosting deployment constraints; approved rollback/data
compatibility policy.

**Acceptance criteria:** Signed artifact is reproducible and traceable to commit;
supported hardware passes acceptance; deployment never overwrites secrets;
migrations have backup/preflight/rollback or forward-fix plan; previous app/API
can be restored within approved objective; no Timesheet regression.

**Tests:** Release build/signature; install/upgrade/rollback; landscape and
secondary-display lifecycle; scanner; offline endurance; API compatibility;
backup restore; staged deployment and rollback rehearsal.

**Risks:** Signing-key loss; irreversible schema changes; app/API version skew;
hardware-specific failures; shared-hosting constraints.

**Estimated complexity:** L–XL depending on dual-display requirements.

## Decisions still required for later roadmap work

- Activation authority, expiry, rotation, and revocation.
- PIN length, attempt threshold, cooldown, and administrator recovery.
- M2 contract details listed in `M2_CATALOGUE_DECISIONS.md`, including monetary
  rounding, unit of measure, effective-time boundaries, cursor/tombstone
  retention, stale-catalogue limits, and operational roles.
- Conflict resolution and negative-stock policy.
- Discount, tax, receipt, refund/void, and cash-management rules.
- Whether card is record-only or integrated, and with which provider.
- Employee/admin role and permission matrix.
- Target POS/scanner/dual-display hardware and customer-display content.
- Reporting definitions, exports, retention, privacy, and audit retention.
- CI provider, signing-key custody, artifact retention, deployment and rollback
  objectives.
