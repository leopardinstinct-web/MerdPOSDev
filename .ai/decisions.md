# MERDPOS Beta Recovery Decisions

## 2026-08-27 — Keep `design-audit.js` during recovery

**Decision:** Do not delete `namecheap_beta_live/timesheet_portal/assets/design-audit.js` as part of recovery initialization.

**Reason:** The current authoritative beta source explicitly treats the file as a canonical runtime invariant. It is loaded by `assets/management.js`, cache-revalidated by portal `.htaccess`, required by `validate_beta_runtime_contract.php`, and checked by `scripts/deploy_namecheap_beta.sh` before the deployed marker is written.

Deleting it in isolation would intentionally make the source/deploy contract fail and could remove regression checks for headings, contrast, touch targets, Search/Add placement, accessibility names and page overflow.

**Supersedes:** A stale transferred handoff expectation that a new recovery session would automatically remove `design-audit.js`.

## 2026-08-27 — Add beta-specific CI rather than repurpose main CI

**Decision:** Add a dedicated `beta-guardrails.yml` workflow for `namecheap-beta-live`.

**Reason:** Existing workflows are oriented around `main` and broader Flutter/backend checks. Recovery requires fast deterministic checks over the actual beta deployable tree on every beta push/PR without changing the semantics of the existing main workflow.

The beta guardrail should validate:

- PHP syntax under `namecheap_beta_live/backend` and `namecheap_beta_live/timesheet_portal`;
- beta runtime-contract invariants;
- portal permission-policy coverage;
- JavaScript syntax for portal assets;
- secret scanning for beta changes.

## 2026-08-27 — Defer authenticated Playwright until fixture strategy exists

**Decision:** Do not add production-credential-dependent browser tests.

**Reason:** Recovery tests must be reproducible and safe. Playwright can be introduced first for unauthenticated/local structural smoke coverage, then for authenticated flows only after explicit non-production fixtures and isolation are defined.
