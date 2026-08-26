# MERDPOS Web Beta Portal

This is the active PHP + JavaScript MERDPOS web beta deployed from branch `namecheap-beta-live`.

**Read `../README.md` first.** It is the authoritative beta runtime README and defines the implementation-state language, deployment contract, frozen payroll rules, Google migration safety and README-maintenance requirements.

## Current portal scope

The portal includes:

- numeric employee login and secure password change;
- signed POS QR attendance;
- role-specific dashboards;
- centralized client Role / LOA / named-permission authorization;
- Stores, Workforce, Roles, Clients, Defaults and DEV diagnostics;
- Timesheets and disputes;
- Finance;
- client-level Google legacy migration with Preview / Sync / Final cutover;
- responsive/mobile-ready UI standards.

## Authorization

Portal authorization follows `docs/pos_latest/BETA_AUTHORIZATION_STANDARD.md`.

Do not authorize new portal behavior with nominal `ADMIN`/`SUPER` checks alone. The authoritative pattern is:

```text
client role → LOA → named permission → UI/API/data scope
```

DEV-only permissions also require an actual DEV identity and cannot be delegated merely by raising another role to LOA 1000.

The backend is authoritative. Hiding a menu/button with JavaScript or CSS is not security.

## UI standard

All user-facing beta features follow `docs/pos_latest/GUI_STANDARD.md`.

Current binding interaction rules include:

- Add Store / Employee / Client / Role and equivalent list-level create actions use a circular `+` control only;
- list search begins as a circular magnifier and expands on click/tap;
- mobile/tablet interactive targets are at least 48px;
- forms collapse cleanly to one column;
- dialogs remain inside the dynamic viewport;
- tables scroll within their own container instead of causing page-level horizontal scrolling.

Runtime layers include:

- `assets/ui-standard.css`
- `assets/minimal-controls.css`
- `assets/minimal-controls.js`
- `assets/management.js` runtime loading/wiring

A rule written in Markdown but not loaded/called by the portal is **DOCUMENTED**, not implemented.

## Timesheet/payroll behavior — frozen

Do not change without explicit product-owner instruction:

- pair IN → next OUT;
- newer IN replaces an unmatched previous IN;
- orphan OUT ignored;
- round IN and OUT independently to nearest 15 minutes;
- payable = rounded OUT − rounded IN;
- cross-midnight allowed;
- no 16-hour cap;
- wage rate resolved by clock-in date.

## Google legacy migration

The client migration subsystem is DEV-only.

```text
Google Sheets → read-only fetch → staging → validation/reconciliation → MERDPOS SQL
```

Safety rules:

- Preview does not write operational attendance/finance data;
- importer does not edit source Google Sheets;
- Sync requires the exact source snapshot last Previewed;
- Sync is blocked if Preview has rejected rows or conflicts;
- operational application is transactional/all-or-nothing;
- imported historical finance must not echo through the SQL→Google outbox;
- final cutover makes MERDPOS SQL authoritative and prevents Google from overwriting SQL later.

Known workbooks must use deterministic mappings rather than generic header guessing. Current approved structures:

```text
Time Sheet:
USER_NAME / STORE_NAME / LOG_TYPE / DATE / TIME

Employee Setup:
NAME / TYPE / USER_ID / PASSWORD / STATUS / LOG_STORE / PAY_RATE
(the real header may occur below row 1)

PayRate:
NAME / PAY_RATE

Start Time:
Store Name / Shift Start Time

General Ledger:
DATE / STORE_NAME / ACCOUNT / TYPE / HEAD / AMOUNT / Key

zReport Ledger:
DATE / STORE_NAME / REGISTER_DENOMINATIONS / REGISTER_TOTAL / PETTYCASH_ADDIN
```

Plaintext legacy passwords/PINs/secrets must never be persisted to staging, logs or audit output.

## Data authority during transition

Historical Google data is being migrated into SQL, while new portal transactions are SQL-first. The existing outbound SQL→Google mirror is a separate compatibility/reporting path and must never create a circular Google→SQL→Google migration loop.

## Main runtime areas

- `index.php` — login
- `dashboard.php` — authenticated portal shell/panels
- `scan.php` — attendance confirmation
- `api/` — authenticated portal APIs
- `includes/beta_api.php` — live authorization refresh and fail-closed route policy
- `includes/timesheet_logic.php` — frozen timesheet reconciliation
- `includes/legacy_migration*.php` — legacy source fetch/staging/reconciliation
- `assets/management.js` — shared runtime module loader/management behavior
- `assets/directory.js` — Store/Workforce admin UI
- `assets/roles.js` — roles/permission policy UI
- `assets/client.js` — client and migration UI
- `assets/ui-standard.css` — global GUI/mobile standard
- `assets/minimal-controls.*` — circular Add and expandable Search standard

## Deployment

Do not manually upload portal files. Deploy through the repository mirror and `scripts/deploy_namecheap_beta.sh`.

The normal immediate command after beta code changes is:

```bash
cd ~/git/MerdPOSDev-beta-mirror

GIT_SSH_COMMAND="ssh -i $HOME/.ssh/merdpos_github -o IdentitiesOnly=yes -o BatchMode=yes" \
git pull --ff-only origin namecheap-beta-live

/bin/bash scripts/deploy_namecheap_beta.sh

echo "=== DEPLOYED ==="
cat ~/merdpos.com/app/beta/.beta_deployed_commit
```

Do not say a change is `live`, `fixed` or `working` based only on a GitHub commit. Confirm the deployed marker and the real runtime path.

## Implementation status discipline

Use the following states explicitly:

```text
REQUESTED → DOCUMENTED → CODED → WIRED → DEPLOYED → VERIFIED
```

`Implemented in beta source` means at least **CODED + WIRED**.  
`Live/fixed` means **DEPLOYED + VERIFIED**.

This distinction is mandatory for all future beta changes.

## README maintenance

Update this README by default whenever portal behavior, authorization, UI conventions, data-flow, synchronization or migration behavior changes. README maintenance is part of beta Definition of Done, but documentation itself must never be mistaken for runtime implementation.
