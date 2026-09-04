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

On phone layouts DEV does not occupy the four-destination primary bar. Section landing pages are safe adapter-pending states only; they do not fabricate operational data or bypass existing MERDPOS authorization/service contracts.

## Resource manifest

Cross-runtime design resources are declared in:

`drupal/resources/merdpos-resources.json`

Synchronize all declared resources with:

`php drupal/tools/sync_merdpos_resources.php`

Fail closed on drift with:

`php drupal/tools/sync_merdpos_resources.php --check`

## Working Now integration boundary

The first Drupal operational adapter is Working Now. Drupal remains an HTTP consumer and must not connect directly to MERDPOS operational tables.

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

Drupal uses `merdpos_core.portal_gateway` for the remaining Beta parity work. Calls are JSON envelopes sent to the authoritative Beta `backend/api/integrations/portal_gateway.php`; the gateway synthesizes only the approved service identity and then executes the existing portal API so its current role/LOA permission policy and business rules remain authoritative.

Gateway signatures cover the exact raw JSON body (`sha256:<body hash>`) in addition to the service/timestamp/client/actor fields. The gateway allowlist excludes login/logout, multipart store-logo upload and all UI Studio/DevStudio endpoints. `dashboard_layout` is rejected whenever a `dev_studio` flag is present. Drupal must not reproduce Beta write logic or query operational tables as a fallback.

Runtime adds `MERDPOS_DRUPAL_GATEWAY_URL`. If absent, the client may derive the sibling `portal_gateway.php` URL from the Working Now service URL. Namecheap deployment resolves an active actual DEV service actor and refuses to publish a release marker unless Working Now, generalized `beta_state`, and `dev_status` probes all pass through the signed boundary.

The endpoint under `namecheap_beta_live/backend/api/integrations/working_now.php` is present on the Drupal experiment branch for contract development. It is not live merely because it exists here. It must be reviewed/promoted to the authoritative Beta branch and deployed with the corresponding environment secret before Drupal can truthfully display real MERDPOS Working Now data.

## Namecheap Beta deployment

The isolated Drupal runtime is deployed from cPanel Git checkout `/home/dridsheikh/merdpos-drupal` on branch `beta/drupal-webapp`. The public document root is `/home/dridsheikh/merdpos-drupal/drupal/web`; existing `app.merdpos.com` Beta paths are not reused or modified.

Deployment is driven by the checked-in root `.cpanel.yml`, which invokes `drupal/tools/namecheap_deploy.sh`. The deploy installs the committed Composer lockfile, resolves private runtime configuration, installs/updates Drupal idempotently, enables `merdpos_core`, rebuilds caches and refuses to publish a release marker unless Working Now and the generalized Beta-state/DEV gateway probes all return successful signed responses.

Production database credentials start in `/home/dridsheikh/.merdpos_drupal_db.php`. During deployment, `namecheap_resolve_runtime.php` reads the authoritative Beta config only in the deployment process, selects an active actual DEV service actor by testing the live signed bridge, and writes `/home/dridsheikh/.merdpos_drupal_runtime.php` mode `0600`. Normal Drupal requests read that private runtime file and do not connect to the MERDPOS operational database.

`drupal/deploy/settings.php` is the tracked production settings template. It trusts only `drupal-beta.merdpos.com` and places private files/config sync outside the web root. Real database credentials, hash salt, service secret and actor identifiers remain private server state and must never be committed.
