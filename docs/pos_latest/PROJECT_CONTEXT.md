# POS LATEST / MERDPOS — Current Project Context

**Reconciled:** 2026-08-26  
**Repository:** `leopardinstinct-web/MerdPOSDev`  
**Active beta branch:** `namecheap-beta-live`  
**Active deployable beta tree:** `namecheap_beta_live/`

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

Historical documentation that says `never inspect or modify timesheet_portal/` is obsolete and must not be followed for the current beta. The web portal is an active primary development surface.

## Mandatory implementation-state discipline

Every beta request must distinguish these states:

```text
REQUESTED → DOCUMENTED → CODED → WIRED → DEPLOYED → VERIFIED
```

Definitions:

- **REQUESTED:** user asked for the change.
- **DOCUMENTED:** spec/context/README/standard changed only.
- **CODED:** implementation file exists.
- **WIRED:** the actual beta runtime entry point loads/calls it and its required API/DB path is connected.
- **DEPLOYED:** intended commit confirmed in Namecheap `.beta_deployed_commit`.
- **VERIFIED:** real runtime path or deterministic deployment/runtime verification proves the behavior.

Rules:

- Never say `implemented` for DOCUMENTED-only work.
- `Implemented in beta source` requires CODED + WIRED.
- Never say `live`, `fixed` or `working` without DEPLOYED + VERIFIED.
- If verification has not happened, state that explicitly.
- Documentation itself is never evidence that runtime code exists.

This rule was added after a documented `+` Add / collapsed Search UI standard was mistakenly described as implemented before its behavior layer was actually wired.

## Active beta architecture

### Web portal

`namecheap_beta_live/timesheet_portal/`

Current scope includes:

- numeric employee login/password change;
- QR attendance;
- management/user dashboards;
- centralized Role / LOA / named permission policy;
- Stores, Workforce, Roles, Clients, Defaults and DEV diagnostics;
- Timesheets and disputes;
- Financials;
- client-level legacy Google migration;
- responsive/mobile-ready shared UI standards.

### Backend

`namecheap_beta_live/backend/`

Contains:

- SQL migrations/runners;
- shared workforce/finance helpers;
- device/POS API support;
- deployment validators;
- schema metadata export.

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

Portal authorization follows `BETA_AUTHORIZATION_STANDARD.md`.

```text
client role → LOA → named permission → UI/API/data scope
```

- UI hiding is not security.
- APIs must enforce the same permission.
- DEV-only permissions require actual DEV identity and are not delegable by numeric LOA alone.
- active client/tenant scoping must be verified through UI → JS → API → auth/client context → DB predicates → response.

## GUI/mobile standard

All beta UI follows `GUI_STANDARD.md`.

Binding current rules:

- mobile-ready at implementation time;
- shared spacing/type/control/table/dialog geometry;
- Add Store / Employee / Client / Role and equivalent list create actions use a circular `+` icon only;
- list search starts as a circular magnifier and expands on demand;
- 48px minimum interactive target on mobile/tablet;
- no page-level horizontal overflow from tables/forms;
- dialogs fit the dynamic mobile viewport and keep actions reachable.

Runtime implementation must be verified in actual code, including loading through the portal entry path. A Markdown rule is DOCUMENTED only.

## Legacy Google migration

Client migration is DEV-only and follows:

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
- final cutover changes authority to MERDPOS SQL and prevents Google from overwriting SQL later;
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

For any DB-backed feature/fix, inspect the complete path before claiming completion:

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

- `namecheap_beta_live/README.md` — beta runtime/architecture contract;
- `namecheap_beta_live/timesheet_portal/README.md` — portal behavior/UI/security/data flow;
- `namecheap_beta_live/backend/README.md` — backend/migrations/deployment/security;
- relevant `docs/pos_latest/*.md` — project context, standards, API/history.

Do not update README merely to make a feature appear complete. README must describe the runtime truth, and implementation must be independently wired/verified.

## Current product direction

MERDPOS is evolving from the legacy Google-Sheet-backed Timesheet/Finance workflow into an SQL-authoritative retail operations platform while preserving safe transition/migration paths.

The broader Flutter/POS code remains part of the repository, but the Namecheap web beta is currently an active primary product surface. Historical roadmap notes that excluded Timesheet Portal development are superseded for beta work.

## Deployment command — standard immediate output after changes

```bash
cd ~/git/MerdPOSDev-beta-mirror

GIT_SSH_COMMAND="ssh -i $HOME/.ssh/merdpos_github -o IdentitiesOnly=yes -o BatchMode=yes" \
git pull --ff-only origin namecheap-beta-live

/bin/bash scripts/deploy_namecheap_beta.sh

echo "=== DEPLOYED ==="
cat ~/merdpos.com/app/beta/.beta_deployed_commit
```

When migration/runtime verification matters, also include an appropriate deploy-log tail.
