# MERDPOS Beta Invariants

These rules are binding for MERDPOS Beta unless the product owner explicitly changes them.

## GitHub is the standalone source of truth

- The authoritative GitHub beta branch must be sufficient to bootstrap a fresh AI/chat/coding session without prior conversation history.
- Root `AGENTS.md` and `.ai/README.md` define the mandatory bootstrap path.
- Chat/project memory is optional context, not required project state.
- Local workstation files, browser state and temporary outputs are not canonical.
- Durable new knowledge must be written back to the repository in the appropriate `.ai` or component documentation.
- Secrets, credentials, cookies and private storage-state must never be stored in the repository knowledge layer.

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

## Security

- Never commit secrets or private backend config.
- Preserve prepared/parameterized SQL.
- Preserve server-side permission enforcement.
- Do not add production credentials to browser tests or GitHub Actions.

## Deployment architecture

Namecheap uses the established server-side pull/mirror/deploy process for Beta. Do not restore a GitHub→Namecheap SSH push deployment path unless the product owner explicitly changes the architecture.

## Working style

- Prefer small attributed fixes over broad rewrites unless redesign itself is the task.
- Before changing a file, inspect its current authoritative branch version.
- Keep runtime and relevant README/context aligned.
- Add regression coverage for incidents after the owning path is understood, but follow the current product-stage testing strategy rather than automating every changing UI flow.
- After substantive work, leave GitHub sufficient for a fresh session to understand the new state without the originating chat.
