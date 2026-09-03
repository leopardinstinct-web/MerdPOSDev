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
