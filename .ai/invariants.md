# MERDPOS Beta Invariants

These rules are binding for MERDPOS Beta unless the product owner explicitly changes them.

## GitHub is the standalone source of truth

- The authoritative GitHub beta branch must be sufficient to bootstrap a fresh AI/chat/coding session without prior conversation history.
- Root `AGENTS.md`, `.ai/README.md` and `.ai/task-gates.md` define the mandatory bootstrap/task-execution path.
- Chat/project memory is optional context, not required project state.
- Local workstation files, browser state and temporary outputs are not canonical.
- Durable new knowledge must be written back to the repository in the appropriate `.ai` or component documentation.
- Secrets, credentials, cookies and private storage-state must never be stored in the repository knowledge layer.
- For substantive changes, the current source plus affected-path Git history/commit diffs are required preflight evidence; current source alone is not enough when provenance, prior standardization or root cause matters.

## Scope

- Repository: `leopardinstinct-web/MerdPOSDev`
- Branch: `namecheap-beta-live`
- Deployable tree: `namecheap_beta_live/`
- Primary browser surface: `namecheap_beta_live/timesheet_portal/`
- Supporting backend: `namecheap_beta_live/backend/`

Do not silently switch to `main`, the older production portal, archived implementations or the Flutter roadmap when working on Beta.

## State language

`REQUESTED → DOCUMENTED → CODED → WIRED → DEPLOYED → VERIFIED`

Never say live/fixed/working without DEPLOYED + VERIFIED.

Planning, diagnosis, recommendations or a proposed patch do not count as CODED. When the product owner explicitly requests implementation and write/execution tools are available, the work must actually mutate the repository/runtime in that turn unless blocked. Completion claims require the evidence defined in `.ai/task-gates.md`.

## Product-stage testing

The beta portal is currently in active product design/restructuring. Do not freeze an intentionally changing interface with exhaustive brittle UI automation.

Keep permanent protection around stable business/security/runtime contracts. For evolving features use targeted smoke, authorization/security checks, deployment verification and visual/runtime verification. Promote a workflow to permanent regression when it is reasonably stable or explicitly prioritised by the product owner.

## Frozen payroll/timesheet reconciliation

- Pair IN → next OUT.
- A newer IN replaces an unmatched previous IN.
- Orphan OUT is ignored.
- IN and OUT are independently rounded to nearest 15 minutes.
- Payable = rounded OUT − rounded IN.
- Cross-midnight shifts are allowed.
- No 16-hour cap.
- Wage rate is resolved by clock-in date.

## Authorization

`client role → LOA → named permission → UI/API/data scope`

- UI hiding is not security.
- API/backend enforcement is authoritative.
- DEV-only requires an actual DEV identity, not only LOA 1000.
- DB-backed changes must inspect the full UI → JS → API → auth/client context → DB → response → re-render path.
- A DEV active-client switch is not proof that employee-owned actions are tenant-native; inspect the authenticated owning client.

## DUMMY destructive testing

- Never mutate MERD production data for regression purposes.
- Resolve the exact DUMMY client identifier at runtime; never assume a database ID.
- Abort before mutation unless DUMMY context/identity is proven.
- Use genuine DUMMY-native identities for employee-owned workflows when required by the endpoint.
- Never commit regression credentials/storage-state/cookies.

## Canonical UI ownership

Current authoritative shared runtime includes:

- `assets/design-tokens.css`
- `assets/design-system.css`
- `assets/shell.css`
- `assets/app-ui.css`
- `assets/dashboard-builder.css`
- `assets/account-menu.css`
- `assets/minimal-controls.js`
- `assets/mobile-runtime.js`
- `assets/design-audit.js`
- `assets/management.js`

Do not restore retired corrective CSS layers. Do not delete a canonical runtime asset unless its loader, deploy validator, docs and replacement ownership are changed together.

For cross-cutting UI/design-system work, token adoption is not proof of successful standardization. Inspect shared primitive history, feature-specific owner/history, final cascade/runtime ownership and actual component states/readability as required by `.ai/task-gates.md`.

## Brand master palette

- The canonical MERDPOS brand master palette is exactly: White `#FFFFFF`, App Background `#F5F7FC`, Brand Navy `#031B4B`, Brand Cyan `#12BDF3`, and Violet `#8B2EFF`.
- Brand-facing CSS must consume these master tokens or derive interaction/dark/neutral treatments from them; do not add blue/indigo/purple/slate brand-master literals back into shared UI.
- Operational success, warning, danger and information colors remain separate semantic status tokens and are not part of the brand master palette.
- Approved raster logo artwork remains immutable; baked-in intermediate gradient pixels in the supplied logo are an artwork exception, not additional UI palette colors.

## DEV UI Studio safety

- UI Studio is available only to an actual DEV identity; a permission or LOA alone is not sufficient.
- DevStudio may write only to its dedicated client-scoped Studio state/audit subsystem through actual-DEV-only, CSRF/revision-checked Studio APIs. These writes are design workflow metadata, not operational MERDPOS business mutations.
- Backend Studio audit/history is retained for audit/learning and is not exposed as an editable/deletable DevStudio history UI.
- DevStudio must never directly modify canonical repository source, payroll/finance/workforce operational data, or ordinary product authorization state.
- Browser-local settings may persist locally; global unresolved patches are server-backed and revisioned.
- A DevStudio patch becomes real product behavior only after canonical source implementation, tests, deployment, real runtime verification and receipt confirmation.
## Security

- Never commit secrets or private backend config.
- Preserve prepared/parameterized SQL.
- Preserve server-side permission enforcement.
- Do not add production credentials to browser tests or GitHub Actions.

## Deployment architecture

Namecheap uses the established server-side pull/mirror/deploy process for Beta. Do not restore a GitHub→Namecheap SSH push deployment path unless the product owner explicitly changes the architecture.

## Working style

- Prefer small attributed fixes over broad rewrites unless redesign itself is the task.
- Before changing a file, inspect its current authoritative branch version and relevant affected-path history.
- Keep runtime and relevant README/context aligned.
- Add regression coverage for incidents after the owning path is understood, but follow the current product-stage testing strategy rather than automating every changing UI flow.
- After substantive work, leave GitHub sufficient for a fresh session to understand the new state without the originating chat.
- For explicit implementation requests, do not stop at a plan when the available tools can perform the change; report concrete implementation evidence and exact lifecycle state.

### Palette-standard escalation

DevStudio may preview palette additions/deletions/reordering, but the binding canonical master palette remains the five colors above until the product owner explicitly accepts a standards change. A palette implementation that changes the master set must update the canonical tokens, this invariant, brand regressions, runtime/deploy validators and affected brand documentation together; do not weaken a failing five-color guard merely to accept an unapproved preview patch.
