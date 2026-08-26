# POS LATEST / MERDPOS — Current Project Context

**Reconciled:** 2026-08-26  
**Repository:** `leopardinstinct-web/MerdPOSDev`  
**Active beta branch:** `namecheap-beta-live`  
**Active deployable beta tree:** `namecheap_beta_live/`

## Project scope — binding default

**From 2026-08-26 onward, Every chat, prompt, screenshot, bug report, design request, code request, audit, comparison or follow-up inside this project refers to the MERDPOS beta by default.**

Unless the product owner explicitly names another target, interpret every request as applying to:

```text
Branch: namecheap-beta-live
Deployable tree: namecheap_beta_live/
Live target: ~/merdpos.com/app/beta
Primary browser surface: namecheap_beta_live/timesheet_portal/
Supporting beta backend: namecheap_beta_live/backend/
```

Do not silently switch to `main`, the older production Timesheet Portal, an archived implementation, or the broader Flutter roadmap merely because a prompt is short. A non-beta target must be explicitly requested.

## Authority

For current beta work, read in this order:

1. `namecheap_beta_live/README.md`
2. this `PROJECT_CONTEXT.md`
3. `BETA_AUTHORIZATION_STANDARD.md`
4. `GUI_STANDARD.md`
5. `OMNICHANNEL_IDENTITY_STANDARD.md`
6. `FEATURE_SCOPING_TEMPLATE.md`
7. relevant API/security/history docs
8. actual current GitHub source on `namecheap-beta-live`

GitHub source is authoritative for what is **coded/wired**. The Namecheap deployed marker and runtime verification are authoritative for what is **live**.

Historical documentation that says `never inspect or modify timesheet_portal/` is obsolete. The web portal is an active primary development surface.

## Mandatory implementation-state discipline

```text
REQUESTED → DOCUMENTED → CODED → WIRED → DEPLOYED → VERIFIED
```

- **REQUESTED:** user asked for the change.
- **DOCUMENTED:** spec/context/README/standard changed only.
- **CODED:** implementation file exists.
- **WIRED:** the actual beta runtime entry point loads/calls it and required API/DB path is connected.
- **DEPLOYED:** intended commit confirmed in Namecheap `.beta_deployed_commit`.
- **VERIFIED:** real runtime path has been checked in the relevant environment.

Never say `implemented` for DOCUMENTED-only work. `Implemented in beta source` requires CODED + WIRED. Never say `live`, `fixed` or `working` without DEPLOYED + VERIFIED.

## Active beta architecture

### Web portal

`namecheap_beta_live/timesheet_portal/`

Current scope includes login/password, QR attendance, dashboards, centralized Role/LOA/named permissions, Stores, Workforce, Roles, Clients, Defaults, DEV diagnostics, Timesheets, disputes, Financials, Google legacy migration, and shared desktop/tablet/mobile UI runtime.

### Backend

`namecheap_beta_live/backend/`

Contains SQL migrations/runners, shared workforce/finance helpers, device/POS API support, deployment validators and schema metadata export.

### Deployment

Repository mirror: `~/git/MerdPOSDev-beta-mirror`  
Live target: `~/merdpos.com/app/beta`

Deployment is performed by `scripts/deploy_namecheap_beta.sh`, also invoked by cron. After every beta source change, provide the immediate manual deploy command; do not rely on waiting for cron.

## Frozen Timesheet/payroll reconciliation

Do not change without explicit product-owner instruction:

- pair IN → next OUT;
- newer IN replaces an unmatched prior IN;
- orphan OUT ignored;
- round IN/OUT independently to nearest 15 minutes;
- payable = rounded OUT − rounded IN;
- cross-midnight allowed;
- no 16-hour cap;
- wage rate resolved by clock-in date.

## Authorization standard

Portal authorization follows `BETA_AUTHORIZATION_STANDARD.md`:

```text
client role → LOA → named permission → UI/API/data scope
```

UI hiding is not security. APIs enforce the same permission. DEV-only capabilities require actual DEV identity. Active client/tenant scoping must be verified through UI → JS → API → auth/client context → DB predicates → response.

## GUI/mobile standard

All beta UI follows `GUI_STANDARD.md`.

Binding rules include:

- **mobile-ready means functional parity, not just responsive dimensions**;
- shared spacing/type/control/table/dialog geometry;
- Dashboard Add and Add Store / Employee / Client / Role use the same canonical circular `+` primitive;
- desktop Add/Search diameter is 46px; tablet/phone is 48px;
- Search and Add stay adjacent in one right-aligned action cluster;
- mobile top bar remains a single stable row;
- fixed bottom navigation respects safe-area insets;
- software keyboard must not cover active form/dialog actions;
- dialogs scroll internally within the dynamic visual viewport;
- no page-level horizontal overflow from tables/forms;
- row actions and notices stay above/inside fixed mobile chrome;
- Dashboard widget picker sits above app chrome and has an explicit close route;
- Dashboard add/remove/reorder remains usable on mobile; free drag/resize may remain desktop-only;
- older browsers receive a usable dialog fallback.

Canonical shared UI/mobile runtime:

- `assets/design-tokens.css` — semantic tokens and responsive dimensions;
- `assets/design-system.css` — canonical shared controls/cards/buttons/tables/dialogs/Add/Search/accessibility grammar;
- `assets/shell.css` — desktop/tablet/mobile navigation and workspace shell;
- `assets/app-ui.css` — feature composition, including directory, Clients, Roles, Permission Policy and Legacy Migration layouts;
- `assets/minimal-controls.js` — shared Add/Search behavior;
- `assets/mobile-runtime.js` — visual viewport, dialog compatibility, Dashboard mobile editing and structural mobile audit;
- `assets/design-audit.js` — runtime heading/accessibility/placement/contrast/overflow audit;
- loaded through `assets/management.js`;
- shared asset cache revalidation through portal `.htaccess`.

Retired corrective CSS layers must not be restored to the runtime: `ui-standard.css`, `minimal-controls.css`, `mobile-hardening.css`, `apple-principles.css` and `omnichannel-identity.css`.

`window.MERDPOSMobileRuntime.audit()` is the browser-side structural smoke test. It checks page horizontal overflow, topbar wrapping, undersized touch targets, open dialogs outside the viewport and malformed shared icon actions. `window.MERDPOSDesignAudit.run()` additionally checks headings, accessible names, Search/Add placement, contrast and overflow. These are regression detectors, not substitutes for real-device verification.

### Shared-component verification rule

Matching class names/icons do not prove a component is shared. Before calling a shared component implemented, compare representative screens for actual width/height, radius/shape, icon dimensions/stroke, background/border/shadow/focus, sibling placement, and desktop/mobile behavior.

For Add/Search, Dashboard + at least one directory screen are the minimum comparison pair.

## Legacy Google migration

Client migration is DEV-only:

```text
Google Sheets → READ ONLY fetch → staging → validation/reconciliation → MERDPOS SQL
```

Safety rules:

- source Google Sheets are not modified by the import path;
- Preview does not write operational attendance/finance data;
- Sync requires the exact snapshot last Previewed;
- Sync is blocked with any rejected row/conflict;
- operational apply is transactional/all-or-nothing;
- imported historical finance cannot echo back through SQL→Google outbox;
- final cutover makes MERDPOS SQL authoritative;
- plaintext legacy passwords/secrets are not persisted in staging/audit;
- known workbooks use deterministic header contracts, not guessed mappings.

Known source structures:

```text
Time Sheet:
USER_NAME / STORE_NAME / LOG_TYPE / DATE / TIME

Employee Setup:
NAME / TYPE / USER_ID / PASSWORD / STATUS / LOG_STORE / PAY_RATE
(header can occur below row 1)

PayRate:
NAME / PAY_RATE

Start Time:
Store Name / Shift Start Time

General Ledger:
DATE / STORE_NAME / ACCOUNT / TYPE / HEAD / AMOUNT / Key

zReport Ledger:
DATE / STORE_NAME / REGISTER_DENOMINATIONS / REGISTER_TOTAL / PETTYCASH_ADDIN
```

## Full-stack beta change rule

For any DB-backed feature/fix, inspect:

```text
UI
→ runtime JS loading/wiring
→ endpoint/action
→ authorization/client context
→ DB table/column/index/FK contract
→ transaction/storage/audit
→ API response
→ UI re-render
→ mobile behavior
```

A schema migration and feature code must deploy in safe order. DB-dependent portal code must not reach live before its required schema has been applied and verified.

## README maintenance rule

README updates are default Definition-of-Done work for beta changes:

- `namecheap_beta_live/README.md`
- `namecheap_beta_live/timesheet_portal/README.md`
- `namecheap_beta_live/backend/README.md`
- relevant `docs/pos_latest/*.md`

README must describe runtime truth; documentation alone never proves implementation.

## Current product direction

MERDPOS is evolving from the legacy Google-Sheet-backed Timesheet/Finance workflow into an SQL-authoritative retail operations platform while preserving safe migration paths. The broader Flutter/POS code remains in the repository but is **not the default target** in this project.

## Deployment command — standard immediate output after changes

```bash
cd ~/git/MerdPOSDev-beta-mirror

GIT_SSH_COMMAND="ssh -i $HOME/.ssh/merdpos_github -o IdentitiesOnly=yes -o BatchMode=yes" \
git pull --ff-only origin namecheap-beta-live

/bin/bash scripts/deploy_namecheap_beta.sh

echo "=== DEPLOYED ==="
cat ~/merdpos.com/app/beta/.beta_deployed_commit
```

When migration/runtime verification matters, include an appropriate deploy-log tail.
