# Implementation Status — POS LATEST

Source baseline: merged `main` at `561a551` after Milestone 2B.
Status reflects source only, not production deployment.
Existing Timesheet is preserved and excluded from new roadmap scoring.

Status meanings:

- **Complete:** current defined baseline behavior exists in source.
- **Partial:** useful implementation exists but roadmap acceptance is unmet.
- **Missing:** no substantive implementation found.
- **Blocked:** implementation awaits a required product/security decision.

| Roadmap feature | Status | Current evidence / gap |
|---|---|---|
| Documentation authority/current state | Complete | Authoritative pack merged in PR #1; legacy pack deprecated. |
| M1 secure activation/login/session source | Complete | Milestones 2A.1–2A.3 and 2B are merged; production behavior remains deployment-gated by migrations 012–015. |
| CI pipeline | Complete | Milestone 1 CI is merged and verified; 2A.1 adds its deterministic backend harness to the existing PHP job. |
| Repository-wide Dart formatting | Partial | Changed `lib/` and `test/` Dart files are CI-gated; pre-existing formatting debt is deferred to a separate cleanup. |
| Flutter analyzer information debt | Partial | 38 existing information-level findings remain visible but non-fatal; errors and warnings remain fatal, and modified files must not introduce either. |
| Automated Flutter tests | Partial | Local-fixture model and Timesheet parser regression tests run in CI; broader coverage remains incomplete. |
| PHP lint/backend tests | Partial | PHP 8.2 lint and deterministic 2A.1 security tests run in CI; endpoint/database integration tests remain pending. |
| Android CI build | Complete | Debug build and reviewed Gradle wrapper are merged and verified in Milestone 1 CI. |
| Secret-free sample configuration | Complete | Sample values replaced with placeholders; historical value treated as rotated. |
| Numeric backend login | Complete | `login.php` plus Flutter `AuthService`. |
| Password hashing/migration | Complete | `password_hash()`/`password_verify()` and transparent upgrade. |
| PIN brute-force protection | Partial | Source integration is complete; migration 012, schema reconciliation, approval, and deployment remain outstanding. |
| Primary/secondary employee sessions | Complete | Maximum two; primary persistence and promotion implemented. |
| Secure device activation | Partial | Backend and Flutter source integration is complete; migrations 013–015, schema reconciliation, approval, and deployment remain outstanding. |
| Milestone 2A.1 security foundation | Complete | Migration drafts, shared helpers, isolated tests, review, and CI are complete; production execution remains separately controlled. |
| Durable PIN lockout | Partial | Login/password integration and deterministic tests are complete; production behavior is unavailable until migration 012 is reconciled, approved, and deployed. |
| Secure app token storage | Complete | Milestone 2B uses maintained Android-backed secure storage with verified legacy migration and a two-release compatibility value. |
| Token lifecycle policy | Partial | Source enforcement and Flutter bearer transport/controlled reactivation are complete; production behavior is unavailable until migrations 013–015 are reconciled, approved, and deployed. |
| Local product catalogue | Partial | SQLite catalogue exists but is demo-seeded/local only. |
| Authoritative product download | Missing | No inbound catalogue endpoint/sync. |
| Barcode/name/category search | Complete | Local search exists; hardware scanner acceptance remains later. |
| Barcode scanner integration | Blocked | Target scanner/hardware behavior requires decision. |
| POS basket and quantity controls | Complete | Implemented in `pos_page.dart`. |
| Stock validation/deduction | Complete | Checked and transactionally deducted locally. |
| Cash sale recording | Complete | Local tender recording implemented. |
| Card sale recording | Partial | Tender label recorded; payment integration requires decision. |
| Tax/discount policy | Blocked | Fields exist, but rules are not defined/implemented in checkout. |
| Order history | Partial | Summary list exists; full line detail/operations are incomplete. |
| Receipts | Blocked | Format, printer, and legal requirements require decision. |
| Refunds/voids | Blocked | Rules and permissions require decision. |
| Local inventory adjustments | Complete | Local adjustment and stock movement implemented. |
| Server inventory ledger/reconciliation | Partial | Retail movement upload exists; convergence/reconciliation absent. |
| Suppliers | Partial | Admin create/list foundation exists. |
| Purchase orders | Partial | Basic single-product creation and receiving exists. |
| Multi-line/partial receiving | Partial | Schema supports lines; UI/workflow is incomplete. |
| Same-day financial summary | Partial | Local device-only revenue/transactions/margin. |
| Historical/consolidated reports | Partial | Basic admin store sales summaries only. |
| Outbound retail sync | Partial | Transactional aggregate sync exists. |
| Per-record acknowledgement | Missing | Server returns aggregate counts only. |
| Bidirectional synchronization | Missing | No inbound product/price/stock synchronization. |
| Conflict handling | Blocked | Ownership and conflict rules require decision. |
| Retry idempotency | Partial | Unique keys/ignore inserts exist; client acknowledgement is unsafe. |
| Admin authentication/session/CSRF | Complete | ADMIN check, CSRF, regeneration, timeout implemented. |
| Admin product/category/inventory | Partial | Basic forms/listing; full CRUD/permissions incomplete. |
| Admin suppliers/purchasing | Partial | Basic workflows only. |
| Admin sales/devices/reports | Partial | Basic read/report pages; device revocation absent. |
| Audit logging | Partial | Admin actions logged; coverage/context/retention undefined. |
| Role/permission model | Blocked | ADMIN gate exists; detailed matrix requires decision. |
| Blue Ice palette/theme | Complete | Canonical colors/theme mapped in Flutter. |
| Fonts/logo/design assets | Partial | Montserrat declared but not packaged; prism/logo assets absent. |
| Settings/version screen | Partial | Settings placeholder; home shows app label only. |
| Production application ID | Missing | Still `com.example.merdpos_staff`. |
| Release signing | Missing | Release uses debug signing. |
| Landscape/dual display | Blocked | Hardware and customer-display requirements require decision. |
| Deployment manifest/version traceability | Partial | Version endpoint exists; current documented marker is stale. |
| Deployment/rollback automation | Missing | Historical manual FileZilla steps only. |
