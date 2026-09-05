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

## Finance v2

`/merdpos/finance` is a role-scoped, read-only Drupal command centre backed by the signed MERDPOS `dashboard_data`, `store_identity`, and `financials` services. It renders store/date filters, sales and cash KPIs, Drupal Charts, Register/Petty Cash account status and ledger detail. Drupal does not submit financial transactions or reproduce MERDPOS financial validation rules; future writes require a separately governed write-parity milestone.

## DEV v2 platform command centre

The Drupal DEV surface is a DEV-only, read-only platform command centre. It combines signed `dev_status`, `clients`, `role_authority`, `client_context`, `dashboard_data`, and `beta_state` reads with a whitelisted view of Drupal's local release marker. It renders environment/service health, role and permission policy, sync/outbox telemetry, attendance security flags, client context, database diagnostic probes, and deployment evidence. Drupal performs no operational SQL for this surface. DevStudio/UI Studio and write actions remain excluded from the Drupal gateway.

## MERDPOS brand hierarchy and theme modes

The route-scoped MERDPOS app theme now uses the approved brand assets by context: the full approved lockup remains the login identity, the standalone gradient M is the compact shell mark, and the approved MERDPOS wordmark is the persistent desktop shell identity. The source wordmark and tagline assets are copied unchanged from the canonical Beta brand directory into the Drupal theme so Drupal does not recreate brand artwork.

The application supports `System`, `Light`, and `Dark` theme preferences. Preference is stored only in browser `localStorage` as `merdpos-theme`; an inline pre-paint bootstrap resolves the effective light/dark mode before CSS loads to avoid theme flash. The runtime selector synchronizes across login and authenticated shell controls and tracks operating-system changes while `System` is selected.

Dark mode is semantic-token driven through `design-tokens.css`; the app shell adds cross-surface compatibility treatment for legacy v2 cards that still contain light-only literals. New Drupal surface work must consume semantic tokens directly rather than adding another independent palette.
