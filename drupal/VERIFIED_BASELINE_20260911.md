# Drupal Verified Baseline — 2026-09-11

This file records the immutable Drupal Beta checkpoint immediately before the global page-header contract release.

## Frozen runtime

- Drupal commit: `4dd4eb4546d22a33552168249d4c84001d2264b2`
- Backend commit: `cb2f6a29a5efb3695a00e542c022b13e3d83da65`
- Drupal branch at verification: `beta/drupal-webapp`
- Immutable tag: `drupal-pr123-verified-20260911`
- Baseline branch: `baseline/drupal-pr123-verified-20260911`
- Drupal release marker timestamp: `2026-09-10T22:44:07Z`

## Verification evidence

The frozen runtime passed the deployment marker health probes, authenticated DEV/Admin/Super/User route boundaries, DEV preview cycling, Dashboard Working Week/all-stores behavior, supplied light/dark branding, and 390×844 mobile overflow checks.

The baseline keeps MERDPOS authoritative for authentication, roles, permissions, business logic, operational persistence and audit. Drupal remains the application surface and signed-gateway consumer.

## Freeze rule

The tag and baseline branch are rollback/provenance anchors and must not be moved during normal development. Future releases branch from or contain this checkpoint; they do not rewrite it.
