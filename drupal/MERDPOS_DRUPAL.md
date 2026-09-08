# MERDPOS Drupal Branch Contract

This directory is the parallel Drupal implementation of the MERDPOS web application on branch `beta/drupal-webapp`.

It inherits the repository governance in `AGENTS.md` and `.ai/`. The Drupal experiment does not replace or weaken those rules.

## Source-of-truth model

- GitHub source/configuration is canonical; Drupal admin clicks are not durable implementation state.
- Reproducible Drupal configuration belongs in code/config and is committed.
- `composer.lock` is committed; `vendor/` and Drupal core are reconstructed by Composer.
- Environment settings, credentials, SQLite files, uploads, caches, and portable local tools are excluded from Git.
- A source commit is not deployment proof; use the MERDPOS lifecycle `REQUESTED → DOCUMENTED → CODED → WIRED → DEPLOYED → VERIFIED`.

## Parallel migration boundary

The existing MERDPOS beta remains authoritative for operational behavior while Drupal is proven feature by feature.

Drupal must not directly reimplement or bypass frozen business rules such as payroll reconciliation, device security, tenant scoping, or current authorization behavior merely to make a page work.

Initial Drupal integration should prefer read-only adapters to existing MERDPOS services. Writes are enabled only after the owning MERDPOS service contract and authorization path are understood and tested.

## Authorization

Drupal roles are UI/application scaffolds, not a replacement for the MERDPOS authorization standard.

The binding MERDPOS model remains `client role → LOA → named permission → UI/API/data scope`, with backend enforcement authoritative. DEV-only functionality still requires an actual DEV identity when connected to MERDPOS services.

The `merdpos_core` module therefore defines named Drupal permissions and keeps its USER/ADMIN/SUPER/DEV roles non-administrator roles (`is_admin: false`) so access is explicit rather than implicit.

## Design/resource ownership

The canonical MERDPOS design-token source remains:

`namecheap_beta_live/timesheet_portal/assets/design-tokens.css`

Drupal consumes a synchronized copy at:

`drupal/web/modules/custom/merdpos_core/css/design-tokens.css`

Do not hand-edit the Drupal copy. Run:

`php drupal/tools/sync_merdpos_design_tokens.php`

and verify with:

`php drupal/tools/sync_merdpos_design_tokens.php --check`

Feature-specific Drupal CSS may compose canonical semantic tokens but should not introduce a competing brand palette.

## Application shell ownership

The Drupal application shell is owned by:

`drupal/web/themes/custom/merdpos_app/`

`merdpos_core` installs the theme and a high-priority theme negotiator applies it only to `merdpos_core.*` routes. Drupal administrative routes continue to use the configured admin theme.

The MERDPOS theme intentionally does not render Drupal `page_top`; this prevents Drupal's administrative Navigation/toolbar chrome from entering the route-scoped application surface. Do not replace this with CSS hiding. `/admin` remains the escape hatch for Drupal administration.

The primary application destinations follow the current canonical Beta direction:

`Home → Operations → Reports → Finance → DEV`

On phone layouts DEV does not occupy the four-destination primary bar. All five destinations now render read-only, permission-scoped MERDPOS data through service adapters; Drupal does not fabricate operational data or bypass existing MERDPOS authorization/service contracts.

## Resource manifest

Cross-runtime design resources are declared in:

`drupal/resources/merdpos-resources.json`

Synchronize all declared resources with:

`php drupal/tools/sync_merdpos_resources.php`

Fail closed on drift with:

`php drupal/tools/sync_merdpos_resources.php --check`

## Working Now integration boundary

Working Now remains the dedicated low-latency roster adapter. Drupal remains an HTTP consumer and must not connect directly to MERDPOS operational tables.

The backend contract is:

`Drupal dashboard → signed GET → MERDPOS integration endpoint → active service actor → current role/LOA thresholds → named permissions → merd_working_now()`

The backend endpoint follows the existing dashboard-scoped dependency model. It requires:

- `dashboard.view`
- `dashboard.widget.working_now`

The widget permission authorizes only this dashboard roster. It does not grant the broader Workforce area. A stricter client override on `dashboard.widget.working_now` takes effect immediately; a separate `workforce.view` threshold remains authoritative for the Workforce feature itself.

The service request uses an HMAC-SHA256 signature with a short timestamp window. Device/POS tokens are not reused.

Runtime configuration is environment-only:

- `MERDPOS_DRUPAL_SERVICE_URL`
- `MERDPOS_DRUPAL_SERVICE_SECRET`
- `MERDPOS_DRUPAL_CLIENT_ID`
- `MERDPOS_DRUPAL_ACTOR_USER_ID`

The service secret must be at least 32 characters and must never be committed. Production service URLs must use HTTPS; plain HTTP is accepted only for localhost testing.

If configuration is absent or the upstream cannot be authenticated, Drupal fails closed to an explicit unconfigured/unavailable state and never falls back to direct SQL.

## Generalized MERDPOS portal gateway

Drupal uses `merdpos_core.portal_gateway` for five-surface Beta read parity across Home, Operations, Reports, Finance and DEV. Calls are JSON envelopes sent to the authoritative Beta `backend/api/integrations/portal_gateway.php`; the gateway synthesizes only the approved service identity and then executes the existing portal API so its current role/LOA permission policy and business rules remain authoritative.

Gateway signatures cover the exact raw JSON body (`sha256:<body hash>`) in addition to the service/timestamp/client/actor fields. The gateway allowlist excludes login/logout, multipart store-logo upload and all UI Studio/DevStudio endpoints. `dashboard_layout` is rejected whenever a `dev_studio` flag is present. Drupal must not reproduce Beta write logic or query operational tables as a fallback.

Runtime adds `MERDPOS_DRUPAL_GATEWAY_URL`. If absent, the client may derive the sibling `portal_gateway.php` URL from the Working Now service URL. Namecheap deployment resolves an active actual DEV service actor and refuses to publish a release marker unless Working Now, generalized `beta_state`, `dev_status`, and all five rendered data providers pass through the signed boundary.

The Working Now and generalized portal gateway endpoints are promoted on authoritative `namecheap-beta-live`; the Drupal runtime consumes those deployed services with the private server-side service identity. UI Studio/DevStudio routes remain intentionally excluded.

## Five-surface read parity

`merdpos_core.parity_provider` is the shared read-only presentation adapter. It maps existing canonical MERDPOS responses into Drupal surface view models without querying operational tables or reimplementing payroll/finance business rules.

- Home: `dashboard_data` plus dedicated `working_now`.
- Operations: `admin_directory`, `store_identity`, and `store_timings`.
- Reports: `dashboard_data`, `weeks`, `timesheet`, and `disputes`; the canonical frozen timesheet reconciliation output is rendered as returned.
- Finance: `dashboard_data`, `store_identity`, and read-only `financials` statements.
- DEV: `dev_status`, `clients`, `role_authority`, and `client_context`; DevStudio/UI Studio is excluded.

The shared `merdpos-surface.html.twig` template renders metrics, cards, tables, trend bars, and safe GET filters for report week / finance store and business date. No operational write form is exposed by this parity milestone.

## Dashboard layout parity v1

Normal Beta dashboard personalization is exposed in Drupal through the signed `dashboard_layout` route, separate from DevStudio/UI Studio. Home reads the authoritative selected role, allowed widget catalog and saved 12-column layout before rendering `dashboard_data`, so allowed widgets are not treated as automatically visible and an intentionally empty saved dashboard remains empty.

Actors with canonical `dashboard.configure` can select a dashboard role and use **Edit dashboard**, **Add widget**, widget search, the Store operations / Finance / Workforce quick templates, remove, clear/reset, desktop drag/resize and mobile Move up / Move down controls. Drupal validates its own CSRF token and forwards save/reset requests through the signed gateway; MERDPOS remains authoritative for role selection, allowed widgets, duplicate/geometry checks and persistence. Drupal explicitly rejects a `dev_studio` flag and the frontend never emits one.

## Namecheap Beta deployment

The isolated Drupal runtime is deployed from cPanel Git checkout `/home/dridsheikh/merdpos-drupal` on branch `beta/drupal-webapp`. The public document root is `/home/dridsheikh/merdpos-drupal/drupal/web`; existing `app.merdpos.com` Beta paths are not reused or modified.

Deployment is driven by the checked-in root `.cpanel.yml`, which invokes `drupal/tools/namecheap_deploy.sh`. The deploy installs the committed Composer lockfile, validates the gateway client and five-surface provider, checks synchronized design resources, resolves private runtime configuration, installs/updates Drupal idempotently, enables `merdpos_core`, rebuilds caches and refuses to publish a release marker unless Working Now, Beta-state, DEV and Home/Operations/Reports/Finance/DEV provider probes all return successful signed responses.

Production database credentials start in `/home/dridsheikh/.merdpos_drupal_db.php`. During deployment, `namecheap_resolve_runtime.php` reads the authoritative Beta config only in the deployment process, selects an active actual DEV service actor by testing the live signed bridge, and writes `/home/dridsheikh/.merdpos_drupal_runtime.php` mode `0600`. Normal Drupal requests read that private runtime file and do not connect to the MERDPOS operational database.

`drupal/deploy/settings.php` is the tracked production settings template. It trusts only `drupal-beta.merdpos.com` and places private files/config sync outside the web root. Real database credentials, hash salt, service secret and actor identifiers remain private server state and must never be committed.

## Authoritative MERDPOS browser login

Drupal `/login` uses the approved MERDPOS login graphics and numeric User ID/password flow, but credential verification remains owned by the authoritative Beta login service:

`Drupal /login → server-side HTTPS POST → /beta/timesheet_portal/api/login.php → existing lockout/password/active-account/role resolution → Drupal shadow session`

Drupal never stores the submitted MERDPOS password. A successful MERDPOS identity is represented by a non-administrator shadow Drupal account whose random local password is not exposed to the user. The MERDPOS employee/client/User ID/role/LOA profile is stored as Drupal user metadata only for session context.

Authenticated MERDPOS pages sign gateway and Working Now requests using the logged-in employee's MERDPOS client and User ID. The MERDPOS backend therefore re-resolves the current active employee, client role, LOA and named permissions for each service call. The private deployment DEV actor remains only the anonymous/deployment probe fallback.

Anonymous access to `merdpos_core.*` routes redirects to the MERDPOS login screen with the original destination. Drupal core/local credentials are not the operational application login path.

## Account password parity v1

The authenticated account menu now exposes Beta-equivalent **Change password** only when the signed actor has `password.change_own`. The modal preserves the Beta field names and labels: Current password, New password, and Confirm new password. Drupal validates its own CSRF token and forwards only those values through the signed `change_password` gateway route; MERDPOS Beta remains authoritative for current-password verification, the 6-20 digit rule, password storage, session-security behavior, and security audit evidence.

Drupal does not persist the submitted password fields. The return target is restricted to `/merdpos` paths, and deployment verifies the route is POST-only plus the authoritative permission is present before publishing the release marker. Browser closure must not change a real password merely to prove the boundary.

### Account password + dark-brand verified checkpoint

PR #82 delivered the account-password and approved Dark-mode brand treatment, and PR #83 closed the mobile Home overflow found by the established acceptance kit. Namecheap release 53b518c7be00 passed the complete Drupal deployment gate. A clean source-only run from C:\Dev\.merdpos-test logged in through the normal MERDPOS Drupal login with bounded DUMMY DEV credentials, confirmed Dark mode, the canonical multicolor tagline, the canonical full lockup on contrast-preserving glass, the permission-scoped Change password action, the modal and all three Beta-equivalent fields. No password was submitted or changed. Desktop had no horizontal overflow; mobile at 390x844 measured document width 390/390 while the dashboard table retained intentional internal scrolling (overflow-x:auto, 759/295).

## Free UI capability stack

The reviewed free/open-source Drupal UI stack is documented in `drupal/FREE_UI_STACK.md`. The selected Composer-managed projects are Dashboard, Charts, UI Patterns, UI Icons, Gin, Gin Toolbar and Better Exposed Filters. Gin is administration-only; the Git-owned `merdpos_app` theme remains the operational application shell and the canonical MERDPOS SVG icon set remains the primary visual language.

Namecheap deployment enables and probes the selected runtime modules, sets Gin as the Drupal admin theme, and refuses the release marker when the expected free UI stack or authoritative MERDPOS login health check is missing.

## Operations & HR v2

`/merdpos/operations` is a role-aware read surface for every authenticated MERDPOS role. Drupal's local route requires only `access merdpos portal`; the signed MERDPOS actor remains the authority for every dataset shown inside the page.

The surface composes existing authoritative APIs only: `beta_state`, `dashboard_data`, `disputes`, `weeks`, `timesheet`, and permission-scoped management APIs (`admin_directory`, `store_identity`, `store_timings`). A forbidden management response removes that panel instead of being converted into broader Drupal access.

The current Operations v2 presentation includes live open shifts, store staffing, attendance trend, current-week late starts from the existing MERDPOS timesheet result, pending disputes with a Reports drill-down, attendance security flags when permitted, recent attendance, and management-only workforce/store/schedule panels. Drupal does not recalculate payable time, wages, late policy, dispute decisions, attendance security rules, or store authorization.

Deployment writes an `operations_v2` release probe and fails closed unless the DEV service actor resolves at LOA 1000, the surface is live, rich metrics/charts resolve, and the management directory/store slices remain authorized. Five-surface parity is checked again in the same deployment.

## Reports v2

The Drupal Reports surface is a presentation layer over the existing MERDPOS Beta `weeks`, `timesheet`, `disputes`, and dashboard identity services. The existing timesheet reconciliation remains authoritative for pairing, rounding, payable hours, late-start flags, rates, wages, and payroll redaction.

Reports v2 adds week, store, employee, and attendance presentation filters; Drupal Charts views for store hours, employee hours, punctuality, dispute state, and authorized payroll-by-store; a print/PDF browser workflow; and a private no-store CSV export of the currently authorized/filtered shift rows.

The CSV route never queries the operational database. It reuses the signed gateway/provider result for the logged-in MERDPOS actor. Wage fields and payroll charts exist only when the authoritative timesheet payload sets `payroll_visible=true`; USER-scoped output therefore remains payroll-redacted.

Deployment fails closed through `validate_reports_v2.php`, the five-surface parity validator, and the `reports_v2` release-marker probe. Live verification additionally checks desktop/mobile rendering and regression of Home, Operations, Finance, and DEV.

## Finance v2 + governed write parity v1

`/merdpos/finance` is role-scoped by the authoritative MERDPOS named permissions, not by a broad Drupal management role. The local Drupal route requires only `access merdpos portal`; the controller and shell then fail closed unless the signed actor has `finance.view`. `finance.submit`, `finance.open_day`, and `finance.cross_store` continue to come from MERDPOS Beta.

The read surface remains backed by signed `dashboard_data`, `store_identity`, and `financials` responses and renders store/date filters, sales and cash KPIs, Drupal Charts, Register/Petty Cash account status and ledger detail. Governed writes now reuse the existing signed `financials` POST contract for the same four Beta submission types: financial-day opening balances, Cash IN, Cash OUT, and Z-report/day close.

Drupal adds its own CSRF token, strict field/action allowlists, UUIDv4 submission IDs, destructive close confirmation, and POST/redirect/GET refresh. It does not reproduce operational finance SQL or acceptance rules. MERDPOS remains authoritative for named permissions, active-store/clock-in requirements, idempotency, available-balance checks, day-open/day-close sequencing, next-day opening balances, ledger writes, audit evidence, and Google Sheet outbox creation.

The Beta portal also has a browser-local offline queue for financial submissions. This first Drupal write-parity milestone is synchronous and therefore does **not** yet claim exact offline-behavior parity; that delta remains explicitly tracked in `drupal/PARITY_MATRIX.md`.

## DEV v2 platform command centre

The Drupal DEV surface is a DEV-only, read-only platform command centre. It combines signed `dev_status`, `clients`, `role_authority`, `client_context`, `dashboard_data`, and `beta_state` reads with a whitelisted view of Drupal's local release marker. It renders environment/service health, role and permission policy, sync/outbox telemetry, attendance security flags, client context, database diagnostic probes, and deployment evidence. Drupal performs no operational SQL for this surface. DevStudio/UI Studio and write actions remain excluded from the Drupal gateway.

## MERDPOS brand hierarchy and theme modes

The route-scoped MERDPOS app theme uses the approved brand assets by context: the full approved lockup remains the login identity, the standalone gradient M is the compact shell mark, and the approved MERDPOS wordmark is the persistent desktop shell identity. In Dark mode, the canonical multicolor tagline replaces the plain shell subline, while the full lockup is shown only on restrained light-glass surfaces in the login/account context so its dark lettering retains contrast. The approved lockup/tagline files are reused unchanged; Drupal does not recolor or recreate the artwork.

The application supports `System`, `Light`, and `Dark` theme preferences. Preference is stored only in browser `localStorage` as `merdpos-theme`; an inline pre-paint bootstrap resolves the effective light/dark mode before CSS loads to avoid theme flash. The runtime selector synchronizes across login and authenticated shell controls and tracks operating-system changes while `System` is selected.

Dark mode is semantic-token driven through `design-tokens.css`; the app shell adds cross-surface compatibility treatment for legacy v2 cards that still contain light-only literals. New Drupal surface work must consume semantic tokens directly rather than adding another independent palette.

## Administration write parity v1

`/merdpos/admin` is the first governed Drupal write surface. It delivers Clients, Stores and Workforce administration in one workspace while preserving the MERDPOS service boundary.

Drupal never writes MERDPOS operational tables directly. Every mutation is a signed `PortalGatewayClient` POST to an existing authoritative Beta API (`clients` or `admin_directory`). The Beta endpoint re-resolves the active employee, role, LOA, named permission, validation rules, tenant scope and audit write before any mutation.

The Drupal controller applies its own CSRF token and submits only explicit whitelisted fields. Beta CSRF remains internal to the gateway and is injected server-side. No MERDPOS password or service secret is exposed to the browser.

DEV may manage another active client by placing `context_client_id` inside the signed gateway envelope. The gateway accepts cross-client context only for an actual DEV service actor, validates that the target client is active, and applies the selected client before the canonical Beta API runs. ADMIN/SUPER remain bound to their authenticated client.

Clients support create/update/status through `clients.manage`. Stores support create/update/status/profile fields through the existing store/workforce permission model. Workforce supports create/update/status, role/LOA assignment, store access, pay-rate fields where authorised, and credential reset only where `workforce.credentials.reset` permits it.

The deployment contract validates the administration controller/template, proves there is no operational SQL in Drupal, verifies a context-aware signed gateway request, and performs a live signed administration read/context probe before publishing the Drupal release marker.

### Administration v1 verified checkpoint

The live Namecheap Drupal release at `04368ce5bc9119dde831d6e3d25cd703d75e365b` completed the authenticated Administration closure regression. DEV client switching, governed Client/Store/Workforce writes, save feedback, 1440x1000 desktop and 390x844 mobile layouts, and both Light/Dark themes were verified with no horizontal overflow. Durable evidence is archived under `.ai/work/archive/evidence/MERD-20260906-drupal-admin-write-v1/`.

## Administration & Onboarding v2

The DEV Administration workspace now starts with a guided `Onboard` flow that provisions the minimum working tenant from one browser submission: Client → first Store → initial ADMIN. Drupal remains an orchestration layer only; the provisioner calls the existing signed `clients` and `admin_directory` gateway routes and contains no MERDPOS operational SQL.

The flow deliberately preserves authoritative service boundaries rather than wrapping the three writes in a fake Drupal transaction. If a later step is rejected, the already-audited Client/Store records remain valid and the user is redirected into the new client context at the exact recovery tab. This prevents Drupal from pretending to roll back writes that were already committed by MERDPOS.

The initial ADMIN role is resolved from the new client's seeded authoritative role rows, not hard-coded by local Drupal role ID. The initial administrator is assigned to the first store through the existing employee-store access model. Pay-rate and credential writes remain subject to the existing MERDPOS permissions.

Onboarding can optionally submit the full seven-day trading-hours schedule through the existing store timing contract, together with supported profile fields such as store code, address, timezone and currency. Client, Store and Workforce lists also gain client-side search without changing server-side authorization or data scope.

Deployment runs both a static Onboarding v2 contract validator and a standalone fake-gateway sequence test, then checks live DEV preconditions through the signed gateway before publishing the release marker. Browser closure must verify desktop/mobile and Light/Dark modes without creating test tenants.

### Administration & Onboarding v2 verified checkpoint

The final live Namecheap Drupal release at `86795de180bfea97a721e27d22413b18a60d1bd3` passed the authenticated Onboarding v2 closure regression. The guided Client → first Store → initial ADMIN flow, optional weekly trading-hours controls, DEV client switching, client search, and desktop/mobile Light/Dark layouts were verified without creating test operational records. Durable evidence is archived under `.ai/work/archive/evidence/MERD-20260906-drupal-onboarding-v2/`.

## Home attendance QR widget v1

Home now includes a permission-scoped attendance scanner whenever the authenticated MERDPOS actor has `attendance.scan` (minimum LOA 1 in the authoritative Beta policy). The widget uses the browser rear camera plus native QR detection when available, with a paste-link/token fallback for unsupported or camera-denied browsers.

The browser never calls the Beta attendance API directly and receives no service credential. It POSTs the scanned QR to Drupal with a Drupal CSRF token; Drupal extracts only the signed attendance token and forwards it through `PortalGatewayClient` to the existing `attendance_scan` route. That canonical API still owns QR signature/expiry validation, store assignment, duplicate protection, cooldown, cross-store suspension, IN/OUT selection, attendance writes and outbox/audit behavior. Drupal contains no attendance SQL.

### Home attendance QR v1 verified checkpoint

The live Namecheap Drupal release at `535d541555e9beaa2b4964413a087949a2b621cc` passed the Home attendance QR closure regression. The scanner widget is permission-scoped, its Drupal CSRF + signed gateway path was exercised end to end with a deliberately invalid QR, and the authoritative verifier returned `invalid_qr` without creating an attendance mutation. Desktop/mobile Light/Dark layouts passed with no horizontal overflow. The headless browser exposed the documented paste fallback because native camera QR detection was unavailable; real device camera scanning remains the same browser capability path and no fake attendance shift was created for verification. Durable evidence is archived under `.ai/work/archive/evidence/MERD-20260906-drupal-home-attendance-qr-v1/`.

## Dispute Write Parity v1

`/merdpos/disputes` is the governed Drupal attendance-correction workspace. It supports the same authoritative workflow already exposed by MERDPOS Beta: employees can submit their own correction requests, cancel an open request, and confirm/reject POS-handover corrections; authorised reviewers can approve/reject pending disputes; authorised supervisors can resolve attendance security flags.

Drupal does not edit attendance shifts, employees or payroll tables. Every mutation is a signed `PortalGatewayClient` POST to the existing `disputes` route. The canonical Beta endpoint re-resolves the actor, role/LOA and named permission (`disputes.submit_own`, `disputes.review`, or `attendance_flags.resolve`) before applying any change, and the existing workforce service owns validation, transaction boundaries and audit/outbox evidence.

The Drupal controller adds its own CSRF token, strict action/field allowlists, POST/redirect/GET authoritative refresh and destructive confirmations. The current service has no edit-in-place action for an open employee dispute, so Drupal does not fabricate one: a user cancels an open request and submits a replacement correction when necessary.

Operations and Reports navigation show the permission-scoped open-dispute count, and the Operations dispute panel links directly into the new workspace. Frozen timesheet/payroll calculation logic is unchanged.


### Routine remote deploy helper

Use `python drupal/tools/namecheap_remote_deploy.py preflight|backend|drupal|all`. It uses Paramiko plus the ignored persistent RSA key and executes only server-side Git pulls/deploy scripts. Do not try plain Windows `ssh` first.
