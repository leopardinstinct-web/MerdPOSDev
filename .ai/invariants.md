# MERDPOS Beta Recovery Invariants

These rules are binding during recovery unless the product owner explicitly changes them.

## Scope

- Repository: `leopardinstinct-web/MerdPOSDev`
- Branch: `namecheap-beta-live`
- Deployable tree: `namecheap_beta_live/`
- Primary browser surface: `namecheap_beta_live/timesheet_portal/`
- Supporting backend: `namecheap_beta_live/backend/`

Do not silently switch to `main`, the older production portal, archived implementations or the Flutter roadmap.

## State language

`REQUESTED → DOCUMENTED → CODED → WIRED → DEPLOYED → VERIFIED`

Never say live/fixed/working without DEPLOYED + VERIFIED.

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

## Recovery style

- Prefer small attributed fixes over broad rewrites.
- Before changing a file, inspect its current branch version.
- Keep runtime and relevant README/context aligned.
- Add regression coverage for incidents after the owning path is understood.
