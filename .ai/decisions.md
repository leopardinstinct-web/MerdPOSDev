# MERDPOS Beta Decisions

This file records durable decisions. Later entries may explicitly supersede earlier ones. Historical decisions remain for provenance but are not binding once superseded.

## 2026-08-27 — Keep `design-audit.js` during recovery

**Decision:** Do not delete `namecheap_beta_live/timesheet_portal/assets/design-audit.js` as part of recovery initialization.

**Reason:** The current authoritative beta source explicitly treats the file as a canonical runtime invariant. It is loaded by `assets/management.js`, cache-revalidated by portal `.htaccess`, required by `validate_beta_runtime_contract.php`, and checked by `scripts/deploy_namecheap_beta.sh` before the deployed marker is written.

Deleting it in isolation would intentionally make the source/deploy contract fail and could remove regression checks for headings, contrast, touch targets, Search/Add placement, accessibility names and page overflow.

**Supersedes:** A stale transferred handoff expectation that a new recovery session would automatically remove `design-audit.js`.

## 2026-08-27 — Add beta-specific CI rather than repurpose main CI

**Decision:** Use a dedicated `beta-guardrails.yml` workflow for `namecheap-beta-live` and keep broader product-area CI path-scoped.

**Reason:** Beta requires fast deterministic checks over the actual deployable tree without forcing unrelated Flutter/Android/root-backend suites to run for portal-only work. Product-area checks should run when their own paths change, while beta guardrails remain authoritative for portal source/runtime contracts.

## 2026-08-27 — Defer authenticated Playwright until fixture strategy exists

**Historical decision:** Do not add production-credential-dependent browser tests until explicit non-production fixture isolation exists.

**Reason at the time:** Recovery tests needed to be reproducible and safe.

**Status:** Superseded by the DUMMY-isolated authenticated regression decision below.

## 2026-08-27 — Use DUMMY-isolated authenticated regression, never MERD mutation

**Decision:** Authenticated regression is allowed when credentials/storage state remain external and all destructive writes are guarded to the exact DUMMY tenant. General authenticated audit may remain read-only; destructive workflows must abort before mutation if DUMMY identity/context is not proven.

**Reason:** This provides real business-path evidence without putting MERD production data at risk.

**Supersedes:** The earlier blanket deferral of authenticated Playwright. The safety principle remains; the fixture/isolation strategy now exists.

## 2026-08-27 — Stage-aware testing: do not over-automate an actively redesigned UI

**Decision:** While the beta webapp is actively being moved, redesigned, included/excluded and restructured, keep a compact safety net rather than exhaustive permanent UI-flow automation.

**Keep now:** runtime smoke, security/authorization/tenant contracts, stable business invariants, deployment guards and targeted verification of changed behavior.

**Defer by default:** brittle contracts around navigation labels, panel order, DOM structure, cosmetic placement and exhaustive CRUD click paths for workflows still being redesigned.

**Promotion rule:** changing features follow `BUILD/CHANGE → QUICK SMOKE → PERMISSION/SECURITY CHECK → DEPLOY → VISUAL/RUNTIME VERIFY`; promote important behavior to permanent regression when the workflow is reasonably stable or the product owner explicitly prioritizes it.

**Reason:** The current goal is product evolution. Tests should protect stable value and safety boundaries, not freeze an intentionally changing interface.

## 2026-08-27 — GitHub is the standalone source of truth and viable AI seed

**Decision:** The authoritative GitHub beta branch must contain enough curated knowledge for a fresh AI/chat/coding session with GitHub access to reconstruct the project and work safely without prior chat history, project memory, local workstation state or undocumented human context.

**Repository bootstrap:**

- root `AGENTS.md` is the entrypoint;
- `.ai/README.md` defines reading order and authority hierarchy;
- `.ai/invariants.md` stores binding rules;
- `.ai/memory.md` stores current state and product stage;
- `.ai/decisions.md` stores durable choices/supersessions;
- `.ai/playbook.md` stores reusable learned procedures;
- test/deployment/component docs beside code remain task-specific authority.

**Maintenance rule:** substantive work must update the knowledge layer when it changes reality. A future session should not need the conversation that produced a change.

**Reason:** Chat context is ephemeral and tool/session-specific. The repository must be self-sustainable, portable and independently understandable.

## 2026-08-27 — Require affected-path history and implementation evidence

**Decision:** Every substantive Beta code change must include a narrow preflight over the current affected source plus relevant Git path/component history. Questions about why prior work behaved or failed a certain way must inspect the relevant commit history/diffs before a confident root-cause answer. When the product owner explicitly requests implementation, analysis/planning alone is not completion if write/execution tools are available.

**Execution contract:** `.ai/task-gates.md` is a mandatory operating contract. It requires concrete implementation evidence for CODED/WIRED claims and deployment/runtime evidence for DEPLOYED/VERIFIED claims.

**Special UI lesson:** Cross-cutting design-system work must inspect both shared primitives and feature-specific styling/history. Token usage alone does not prove readability, semantic correctness or successful propagation.

**Reason:** Two preventable failures occurred: provenance was inferred from current source without checking the relevant history, and implementation requests risked being answered with analysis instead of actual repository changes. These gates make both failures explicit and recoverable in GitHub for future sessions.

## 2026-08-27 ? Apply Concept 7 connected brand kit across Beta UI

**Decision:** Concept 7 is the canonical MERDPOS Beta identity: an interwoven ribbon M representing connected retail channels and one continuous customer journey. The shared product palette is navy `#031B4B`, cyan `#12BDF3`, blue `#1D6CFF`, indigo `#586CFF`, violet `#8B2EFF` plus the supporting brand-kit colors recorded in `BRAND_IDENTITY_STANDARD.md`.

**Typography:** Space Grotesk is the display/brand heading preference; Inter remains the operational UI/body typeface.

**UI application:** The dark navy navigation rail, light workspace, blue functional accent and subtle connected-path treatments carry the brand through the product. The cyan?blue?violet gradient is reserved for identity moments and must not replace success/warning/danger/info semantics. Brand rollout belongs in shared tokens, shared design-system/shell ownership and the isolated `assets/brand/brand.css`, not copied feature CSS.

**Outcome:** The visual system should reinforce the product promise `SMARTER ? FASTER ? TOGETHER` and the business outcome of better customer satisfaction through omnichannel integration without adding decorative friction to operational workflows.

**Supersedes:** The earlier 2026-08-27 interim brand color values (`#111827`, `#2563EB`, `#4F46E5`, `#7C3AED`) in the first connected-brand standard.

## 2026-08-28 - Mobile UX uses One UI interaction principles, MERDPOS visual identity

**Decision:** MERDPOS phone layouts use the supplied Samsung One UI guidelines as an interaction reference, not as a visual clone. MERDPOS branding, semantic tokens and authorization remain canonical.

**Mobile contract:** primary bottom navigation is role-aware and limited to four high-frequency destinations; secondary client/account/theme/About/system utilities belong in a thumb-reachable bottom sheet; phone pages use a consistent title/helper/client-context header with contextual subtabs; safe gutters target 24px and touch targets remain at least 48px; desktop data tables adapt to labelled card rows on phones unless a feature owns a stronger mobile renderer; important actions and loading states stay reachable and local to the affected content.

**Verification rule:** cross-cutting mobile changes require a 390px runtime smoke plus authenticated light/dark rendered verification. The contract protects usability outcomes (overflow, reachability, readable hierarchy, utility access), not exact cosmetic coordinates.
