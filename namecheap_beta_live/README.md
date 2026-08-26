# MERDPOS Namecheap Beta — Authoritative Runtime README

**Branch:** `namecheap-beta-live`  
**Runtime source:** `namecheap_beta_live/`  
**Deployment target:** Namecheap beta at `~/merdpos.com/app/beta`  
**Status:** This README is the first-read source for work on the current web beta.

## 0. Binding project-scope default

**Every chat, prompt, screenshot, bug report, design request, audit, implementation request or follow-up inside the POS LATEST project is about this beta by default.**

Unless the product owner explicitly names another target, interpret all project work as applying to:

```text
Branch: namecheap-beta-live
Deployable tree: namecheap_beta_live/
Live target: ~/merdpos.com/app/beta
Portal: namecheap_beta_live/timesheet_portal/
Backend: namecheap_beta_live/backend/
```

Do not infer `main`, the older production portal, an archived build, or the broader Flutter roadmap from vague wording such as “MERDPOS”, “the app”, “portal”, “this”, “fix it”, or “implement this”. A non-beta target must be explicitly requested.

## 1. Source of truth

For the current MERDPOS web beta:

1. GitHub branch `namecheap-beta-live` is authoritative for source.
2. `namecheap_beta_live/` is the authoritative deployable beta tree.
3. `docs/pos_latest/PROJECT_CONTEXT.md` supplies project-wide context, but any historical instruction that conflicts with this README is superseded for beta work.
4. The live Namecheap database/schema must be verified independently from source migrations before DB-dependent behavior is claimed live.
5. Existing approved Timesheet/payroll reconciliation behavior is frozen unless the product owner explicitly changes it.

## 2. Mandatory implementation-state language

Never collapse planning/documentation and runtime implementation into one status.

Every requested beta change has one of these states:

```text
REQUESTED
DOCUMENTED
CODED
WIRED
DEPLOYED
VERIFIED
```

Definitions:

- **REQUESTED** — user asked for it.
- **DOCUMENTED** — standards/context/specification changed only.
- **CODED** — implementation file exists.
- **WIRED** — implementation is loaded/called from the actual beta runtime entry path and required API/DB path is connected.
- **DEPLOYED** — the relevant commit is confirmed in `.beta_deployed_commit` on Namecheap.
- **VERIFIED** — the intended runtime behavior was checked through the real execution path or a deterministic deploy/runtime validator.

### Language rule

- Do **not** say `implemented` when a change is merely documented or coded.
- `Implemented in beta source` requires **CODED + WIRED**.
- `Deployed` requires the Namecheap deployed marker.
- `Live / working / fixed` requires **DEPLOYED + VERIFIED**.
- If runtime verification has not happened, say exactly that.

## 3. Beta runtime architecture

### `timesheet_portal/`

Current PHP/JavaScript browser portal. Active development surface.

Includes:

- login and QR attendance;
- dashboard and role-specific widgets;
- centralized client-role/LOA permission policy;
- Stores, Workforce, Roles, Clients, Defaults and DEV diagnostics;
- Timesheets and disputes;
- Finance;
- client-specific Google legacy migration/preview/cutover subsystem.

### `backend/`

SQL migrations, device/POS API support, finance/workforce helpers, CLI migration runners and deployment validators.

### Browser authorization

Portal authorization is governed by `docs/pos_latest/BETA_AUTHORIZATION_STANDARD.md`.

```text
client role → LOA → named permission → UI/API/data scope
```

DEV-only permissions additionally require an actual DEV identity; numeric LOA alone cannot delegate them.

## 4. GUI + mobile runtime contract

Every beta UI follows `docs/pos_latest/GUI_STANDARD.md`.

Binding rules include:

- mobile-ready means **functional parity**, not just responsive width;
- shared spacing/type/control/table/dialog grammar;
- Dashboard Add and Add Store / Employee / Client / Role use the same canonical circular `+` primitive;
- desktop Add/Search action diameter is 46px; tablet/phone is 48px;
- Search and Add coexist in one adjacent right-aligned action cluster;
- mobile top bar remains one stable row;
- bottom navigation respects safe-area insets;
- software keyboards must not hide active form/dialog controls;
- dialogs remain internally scrollable and actions reachable;
- tables scroll inside their own container instead of causing page-level horizontal overflow;
- Dashboard add/remove/reorder remains usable on mobile even though free drag/resize is desktop-only;
- older browsers receive a minimal dialog fallback rather than dead modal actions.

Runtime enforcement layers:

- `timesheet_portal/assets/ui-standard.css`
- `timesheet_portal/assets/minimal-controls.css`
- `timesheet_portal/assets/minimal-controls.js`
- `timesheet_portal/assets/mobile-hardening.css`
- `timesheet_portal/assets/mobile-runtime.js`
- runtime loading through `timesheet_portal/assets/management.js`

`window.MERDPOSMobileRuntime.audit()` is the mobile structural smoke test. It detects common regressions including page-level horizontal overflow, wrapped top bar, undersized touch targets, dialogs outside the viewport and malformed shared icon actions. This check supplements—but never replaces—real-device verification.

A standard written only in Markdown is **not implementation**. Shared components must also be checked for actual geometry, placement, touch behavior and mobile usability.

## 5. Legacy Google migration safety

Google legacy migration is DEV-only.

```text
Google Sheets → read-only fetch → staging → validate/reconcile → MERDPOS SQL
```

Rules:

- Preview never writes operational attendance/finance records.
- Migration source Google Sheets are not modified by the import path.
- Sync requires the exact source snapshot that was last Previewed.
- Sync is blocked when the Preview has rejected rows or conflicts.
- Operational apply is transactional/all-or-nothing.
- Imported historical finance must not echo back through the existing SQL→Google outbox.
- Final cutover changes authority to MERDPOS SQL; Google cannot later overwrite SQL.
- Known legacy tabs use deterministic header contracts; do not guess known workbook schemas.

Current known workbook structures include:

```text
Attendance / Time Sheet:
USER_NAME / STORE_NAME / LOG_TYPE / DATE / TIME

Employee Setup:
NAME / TYPE / USER_ID / PASSWORD / STATUS / LOG_STORE / PAY_RATE
(real header may occur below row 1)

PayRate:
NAME / PAY_RATE

Start Time:
Store Name / Shift Start Time

General Ledger:
DATE / STORE_NAME / ACCOUNT / TYPE / HEAD / AMOUNT / Key

zReport Ledger:
DATE / STORE_NAME / REGISTER_DENOMINATIONS / REGISTER_TOTAL / PETTYCASH_ADDIN
```

Never expose/store plaintext legacy passwords in staging, logs or audit output.

## 6. Frozen payroll/timesheet reconciliation

Do not change without explicit product-owner instruction:

- pair `IN` → next `OUT`;
- newer `IN` replaces an unmatched previous `IN`;
- orphan `OUT` ignored;
- round IN and OUT independently to nearest 15 minutes;
- payable = rounded OUT − rounded IN;
- cross-midnight allowed;
- no 16-hour cap;
- wage rate is resolved by clock-in date.

## 7. Deployment

Authoritative branch: `namecheap-beta-live`.

Immediate deploy after beta source changes:

```bash
cd ~/git/MerdPOSDev-beta-mirror

GIT_SSH_COMMAND="ssh -i $HOME/.ssh/merdpos_github -o IdentitiesOnly=yes -o BatchMode=yes" \
git pull --ff-only origin namecheap-beta-live

/bin/bash scripts/deploy_namecheap_beta.sh

echo "=== DEPLOYED ==="
cat ~/merdpos.com/app/beta/.beta_deployed_commit
```

The deploy must fail closed on PHP lint, central permission-policy coverage, required migrations/schema checks and registered runtime-contract invariants. Shared mobile runtime files and their management-loader wiring are release invariants.

## 8. README maintenance — default rule

README maintenance is part of beta implementation, not optional documentation cleanup.

When behavior/architecture changes:

- update this root beta README when the beta contract/runtime architecture changes;
- update `timesheet_portal/README.md` for portal behavior/UI/security/data-flow changes;
- update `backend/README.md` for migrations/backend/deployment/security changes;
- update relevant `docs/pos_latest/*.md` context/standards/history.

A beta feature should not be called complete if the runtime and its relevant README disagree.

## 9. Beta Definition of Done

Before saying a beta change is implemented:

- implementation code exists;
- runtime entry point actually loads/calls it;
- UI → JS → endpoint → auth/client context → DB/schema → audit/storage → response/re-render has been checked where applicable;
- server-side security is authoritative;
- mobile feature parity is covered for user-facing UI;
- visual viewport/software-keyboard behavior is considered for forms and dialogs;
- shared UI components are visually equivalent across representative screens;
- `MERDPOSMobileRuntime.audit()` has no unresolved structural failure for the affected mobile view;
- relevant README/context is updated;
- source lint/validators pass.

Before saying it is live/fixed:

- deploy marker confirms the intended commit;
- migrations/schema required by that code are live;
- runtime verification confirms the feature/fix through its actual execution path on the relevant desktop/mobile environment.
