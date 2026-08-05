# CI and Build Environment Plan

## Goal

Run Flutter analysis/tests, PHP checks, and Android builds outside the
production VPS. The VPS should receive only reviewed deployment artifacts after
explicit approval; it should not host Android SDKs, Gradle caches, signing keys,
or general-purpose build tooling.

## Recommended model

Use GitHub Actions for pull-request and protected-branch validation. Pin action
revisions and SDK versions. If private-repository policy prevents hosted
runners, use a dedicated non-production self-hosted runner with no production
network/database credentials.

Milestone 1 decision: use GitHub Actions with Flutter `3.44.2` exactly. Do not
install Flutter, Dart, Java, Gradle, or Android tooling on the production VPS.

### Jobs

1. **Documentation and repository hygiene**
   - Markdown lint/link checks.
   - Detect stale commit markers/placeholders in current-state blocks.
   - Secret scanning and forbidden-file checks.
   - `git diff --check`.

2. **Flutter analysis and tests**
   - Ubuntu runner with pinned Flutter stable matching `pubspec.yaml` SDK.
   - Restore dependency caches keyed by lockfile and SDK version.
   - `flutter pub get` with the committed lockfile.
   - `dart format --output=none --set-exit-if-changed lib test`.
   - `flutter analyze`.
   - `flutter test --coverage` once tests exist.
   - No production URLs called by tests; inject fake/local transports.

3. **PHP checks**
   - Container or runner with pinned PHP 8.2 CLI and required extensions.
   - Lint every tracked PHP file except local secret config.
   - Run unit/contract tests using fixtures and a disposable database container
     only when database tests are added.
   - Static analysis with a pinned tool may be proposed separately; adding a
     dependency requires approval.

4. **Android build**
   - Pinned JDK 17, Flutter, Android SDK platform/build-tools, and Gradle inputs.
   - Build a debug APK for pull requests.
   - Milestone 1 builds a debug APK only.
   - Retain the debug APK and checksum for seven days.
   - Production release signing, keystores, release environments, and release
     distribution are excluded.
   - Upload checksummed artifacts with commit, version, and toolchain metadata.

5. **Security tests**
   - Secret scan.
   - Dependency review/advisory scan.
   - API authorization/tenant-isolation tests against local fixtures.
   - Fail if forbidden artifacts (`config.php`, `.env`, `.deployed_version`,
     APKs, `build/`, `.dart_tool/`) are tracked.

## Signing and secrets

- Do not place production DB/API credentials in CI.
- No Android signing secrets are required or permitted in Milestone 1.
- `GITHUB_TOKEN` with read-only contents permission is sufficient for current
  workflows.
- Future signing-key custody remains a later release decision.

## Test architecture

- Flutter HTTP services must accept an injectable client/base URL for tests.
- Use deterministic JSON fixtures for API contracts.
- Use temporary SQLite databases for offline retail tests.
- Backend integration tests use disposable MySQL/MariaDB with synthetic data.
- Never connect CI to production API/database.
- Preserve existing Timesheet behavior through a small regression fixture only;
  do not expand Timesheet scope.

## Branch and release gates

- Pull request: docs/hygiene, Flutter format/analyze/tests, PHP lint/tests,
  debug Android build.
- Protected release candidate workflow is deferred until separately approved.
- Production: always a separate explicit approval; CI must not auto-deploy.

## Version matrix to decide and record

- Flutter `3.44.2` stable (approved and exact).
- Dart version bundled with Flutter `3.44.2`.
- JDK 17 distribution/version.
- Android compile/target SDK and build-tools.
- PHP CLI 8.2 patch line matching hosting as closely as practical.
- MySQL versus MariaDB test version matching production.

JDK/Android versions are supplied by the pinned Flutter/GitHub runner during the
first CI attempt. If the missing Gradle wrapper blocks the build, stop and
review wrapper restoration from the approved Flutter template; do not generate
it on the VPS.

## Local development alternative

A dedicated Windows/Linux development machine may run the same pinned commands
for fast feedback. CI remains the merge authority. The production VPS may run
only narrowly scoped read-only health checks and reviewed PHP syntax checks if
a reliable PHP CLI is later provided; it must not become the primary builder.

## Initial CI acceptance criteria

- Clean clone completes all jobs without production access.
- A formatting/analyzer/PHP syntax/test failure blocks the PR.
- No job writes credentials to logs or artifacts.
- Debug APK is traceable to commit and checksum.
- CI configuration and action dependencies are pinned and reviewable.
- Production deployment remains disabled by default.

Repository hygiene has one exact legacy exception for
`timesheet_portal/includes/config.php`. The portal file remains excluded from
inspection and modification. This exception does not allow any other
`config.php`, and it requires a future separate security review. The policy
continues to reject `.env` files, `.deployed_version`, APKs, keystores,
`build/`, and `.dart_tool/`.

Wrapper restoration is performed only by the temporary, manually dispatched
`restore-gradle-wrapper.yml` workflow. It generates a clean Flutter 3.44.2
project on a disposable GitHub-hosted runner with read-only repository
permission, verifies its distribution URL against the existing wrapper
properties, and publishes only the three approved wrapper files plus review
metadata. It has no secrets or production-system access. Remove the temporary
workflow after the reviewed files are added.

## Milestone 1 historical-secret policy

- The previous sample value is considered exposed and already rotated.
- PR/push scans cover introduced commits with redacted Gitleaks output.
- A manual full-history scan may identify the historical value.
- If necessary, record only its Gitleaks fingerprint in a narrowly scoped
  ignore entry after review.
- Do not print/test the value, contact production, or rewrite Git history in
  this milestone.
