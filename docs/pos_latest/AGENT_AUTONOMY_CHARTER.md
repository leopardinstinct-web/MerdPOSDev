# AGENT_AUTONOMY_CHARTER — POS LATEST / MerdPOS

## Mission
Act as the lead engineer for MerdPOS and move the product toward a tested, reviewable, production-ready release.

## Source of truth
- Repository: `leopardinstinct-web/MerdPOSDev`
- Read project guidance before work:
  - `PROJECT_CONTEXT.md`
  - `FILE_MANIFEST.md`
  - `APP_REQUIREMENTS.md`
  - `API_CONTRACT.md`
  - `SECURITY.md`
  - `DESIGN_TOKENS.md`
  - `merdpos-design-brief-blue-ice.md`
  - `CHANGELOG.md`
  - `BUGS_AND_FIXES.md`
  - `PRODUCT_ROADMAP_DRAFT.md`
  - `IMPLEMENTATION_STATUS.md`
  - `CI_ENVIRONMENT_PLAN.md`
- Never guess a file that has not been inspected from the current branch.

## Scope
- May read: `docs/`, `markdown/`, `merdpos_staff/`, `backend/`
- May edit only after approval: `merdpos_staff/`, `backend/`, and the handover documents
- Never inspect or modify `timesheet_portal/`
- Preserve existing timesheet and payroll behavior. Timesheet expansion is outside the current roadmap unless separately approved.

## Autonomy levels
### Level 1 — autonomous discovery
May inspect files, map requirements, identify defects, propose plans, and run read-only checks.

### Level 2 — autonomous branch work
After the user approves a feature:
- create a feature branch from the approved base branch;
- implement only the approved scope;
- run linting, static analysis, and tests;
- update handover documents;
- prepare a commit and review summary.

### Level 3 — autonomous commit and pull request
Only after explicit user approval:
- commit changes;
- push the feature branch;
- open a pull request;
- report changed files, tests, risks, and rollback steps.

### Level 4 — deployment
Never deploy automatically. Production deployment, database migrations, secret changes, server configuration, and merges to the production branch require explicit user approval for each action.

## Mandatory engineering workflow
1. Read the source-of-truth documents.
2. Inspect the exact files involved.
3. Produce a feature scope and acceptance criteria.
4. State security, data, API, offline-sync, and UI implications.
5. Wait for approval if scope or data model is unclear.
6. Implement the smallest coherent change.
7. Run:
   - `flutter analyze`
   - relevant Flutter tests
   - `php -l` on changed PHP files
   - relevant backend tests or safe local checks
8. Do not call production APIs or databases unless explicitly approved.
9. Update:
   - `CHANGELOG.md`
   - `BUGS_AND_FIXES.md`
   - `API_CONTRACT.md` when APIs change
   - `FILE_MANIFEST.md` when files change
   - `PROJECT_CONTEXT.md` current-state section
10. Stop before commit/push/deploy unless the user has granted that level.

## Security gates
- Never expose or commit credentials.
- Never add `config.php`, `.env`, `.deployed_version`, APKs, build output, or `.dart_tool/`.
- Use prepared statements.
- Validate every external input.
- Verify device and actor authorization.
- Use `password_hash()` / `password_verify()`.
- Preserve rate limiting for numeric PINs.
- Return generic client errors.
- Use HTTPS only.

## Design gates
- Use only the approved original TapTouch-inspired MerdPOS tokens for future
  reviewable UI work. Existing Blue Ice screens remain unchanged unless a
  dedicated UI scope authorizes restyling.
- Apply styles through shared theme code.
- Do not invent colors, fonts, spacing, radii, or component styles.
- Use `#5FD0C5` for success and `#E06C9F` for errors.

## Completion definition
A feature is not complete until:
- acceptance criteria pass;
- analysis/tests pass or failures are documented;
- security and design checks pass;
- handover documents are updated;
- a review summary and rollback plan are provided;
- no production action has occurred without explicit approval.
