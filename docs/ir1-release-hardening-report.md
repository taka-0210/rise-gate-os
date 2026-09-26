# IR-1 Parallel Track B｜Release Hardening Report

Date: 2026-09-26 JST
Status: Track B implementation complete / Production-dependent gates remain closed

## Boundary

- Production SSH / DB / Application connection: not performed
- host key approval / `known_hosts` change: not performed
- Production Backup / Migration / Deploy: not performed
- Production paths, ledger, IDs, writers, secrets and stop window: intentionally unresolved until R0

## RH-01〜07

| ID | Result | Evidence |
|---|---|---|
| RH-01 Immutable RC | Implemented | A manually supplied 40-character RC SHA is checked out and tested. One deterministic artifact is built, its SHA-256 is fixed, and deploy downloads that exact artifact from the specified build run. |
| RH-02 Release / rollback | Implemented, topology pending | Artifact is extracted to an immutable non-public release directory. `current` is switched atomically and the former target is retained as `current.previous`. Actual Production paths and first transition procedure require R0 evidence. |
| RH-03 Fail closed | Implemented | Maintenance start failure stops release. No EXIT trap calls `artisan up`. Migration, verification or switch failure leaves maintenance active; `up` is a separate final success gate. |
| RH-04 Shared state separation | Implemented, profile pending | `.env` and `storage` must exist under a shared root and are linked into a release. Artifact construction rejects `.env`, SQLite files and runtime storage content. Production storage/upload/backup locations require R0 confirmation. |
| RH-05 Frontend build | Implemented | `package-lock.json`, `npm ci`, production Vite build, non-empty manifest/assets checks, and manifest hash recording are part of RC build. |
| RH-06 Migration safety | Implemented, allowlist pending | Repository Migration set and per-file checksums must exactly match an approved manifest. Only allowlisted pending files are applied individually. Postflight requires no pending or missing-approved Migration. No blind retry, rollback or fresh exists. Production allowlist remains empty/pending until R0 ledger evidence. |
| RH-07 Release verification | Implemented, Production manifest pending | Application verification supports routes, counts, protected record IDs, relations, active owner minimums and asset hashes. HTTP smoke requires at least two explicit checks and is not `/login`-only. Production IDs/expectations are injected after R0. |

The retired in-place deploy script now exits with code 78 and cannot perform `rsync --delete` deployment.

## 409 / full-suite investigation

Existing historical evidence `342 PASS / 174 FAIL` remains unchanged and is not counted as successful Scope 9 or release evidence.

The first diagnostic run inherited the local subdirectory `APP_URL` and produced `138 PASS / 383 FAIL`; representative failures were route-base 404s. This was a test-environment contamination issue. PHPUnit now pins testing to root `APP_URL`, isolated in-memory SQLite, and Admission ON.

With that isolation, the measured full suite is:

- 354 passed
- 172 failed
- 3,269 assertions
- 272.32 seconds

Representative classification:

1. Most 409 responses are **test-environment fixture defects**: legacy feature fixtures create Organization/Workspace membership without the required `ProductAccountEligibility`, so the current Admission boundary correctly fails closed before the intended endpoint assertion.
2. Legacy tests that expect an existing Account to start a second company are **expected rejection / obsolete expectations** under the closed single-Organization Admission contract.
3. The 16 MariaDB RG02 cases are **explicit isolated-environment tests** and fail at their guard when `RG02_ALLOW=1`, the isolated DB name and datadir are absent. They are not SQLite regressions and were not counted as PASS.
4. No test was deleted and Admission was not globally disabled to create a green result. The two legacy Client promotion compatibility cases explicitly exercise flag-off behavior, while a new flag-on case verifies rejection before Business Data write.

Therefore the full release suite is not green and G06 cannot close. Updating shared legacy fixtures to create valid eligibility, then rerunning the suite, is required. MariaDB RG02 should continue to run only in its approved isolated profile.

## Verification performed

- Release Hardening / Client Promotion / Product Organization focused regression: 15 passed, 73 assertions
- New PHP files: syntax PASS
- Deployment shell files: `bash -n` PASS
- GitHub Actions workflows: Symfony YAML parse PASS
- Artisan command discovery: all four `release:*` commands registered
- `npm ci`: PASS, 0 vulnerabilities
- `npm run build`: PASS; Vite manifest and assets present
- Sanitized R0 audit, exact Migration manifest, release verification and fail-closed workflow behavior: exercised against isolated SQLite fixtures

## Staging / rehearsal readiness

- The same RC artifact can be selected by build run ID and artifact SHA for synthetic Staging and Production.
- Pending example manifests fail closed until environment-specific IDs, counts, relations, routes, owners and checksums are approved.
- Restore / Migration rehearsal remains separate; no Production data is required by the synthetic verification fixtures.
- An end-to-end release-directory switch rehearsal still requires an isolated filesystem profile matching the R0-confirmed Production topology.

## R0 audit tooling

`release:audit-r0 --confirm-read-only=IR1-R0-READ-ONLY` collects only sanitized runtime metadata, DB engine/version, hashed DB identifier, Migration ledger/delta, table counts, active-owner counts, pending invitation count and schema metadata. It does not emit credentials, secret values or Business Data bodies. Production execution remains prohibited until Track A and a separate R0 authorization are complete.

## Gate status

- **G05｜Release / Rollback Rehearsal: CONDITIONAL** — hardening structure is implemented, but R0-confirmed paths, first symlink transition, shared state profile, approved manifests, synthetic Staging rehearsal and rollback drill are outstanding.
- **G06｜Test / Evidence: CONDITIONAL** — focused hardening tests and local build pass, but the isolated full suite remains 354 PASS / 172 FAIL. It must not be treated as green.
- **G12 / Production Release: NOT READY / NO-GO**.

## Production Evidence pending

- Production application commit / release marker and actual filesystem topology
- PHP Web / CLI and Laravel/runtime profile
- DB engine/version, charset/collation, ledger, schema, FK/index and exact pending Migration set
- Protected Production tenant/data IDs and relations for the verification manifest
- active owners, memberships, eligibility and pending invitations
- cron, queue, workers, external writers, session and cache actual state
- shared `.env`, storage, uploads, DB and backup ownership/location
- approved release/maintenance window and rollback decision boundaries

## G06 Full Suite Corrective Work (2026-09-26 JST)

This section appends the corrective result without replacing the historical `342 PASS / 174 FAIL` or the Track B baseline `354 PASS / 172 FAIL` evidence above.

### Classification of the 172 baseline failures

| Classification | Count | Corrective disposition |
|---|---:|---|
| A - Fixture defect | 153 | Legacy feature fixtures now establish an explicit `single` or `unstarted` Product Account Eligibility only where that test's actor requires it. The `single` helper also supplies the real Organization membership access epoch, so write tests reach their intended validation/authorization boundary. Negative Admission and Permission fixtures were not granted eligibility automatically. |
| B - Obsolete expectation | 3 | Two old journeys that expected an existing single-Organization Account to join/start another Organization now verify fail-closed rejection before Business Data write. One stale suspended-state UI copy assertion now verifies the current closed-state message. The tests were retained and assert identity, membership and data preservation. |
| C - Environment-specific | 16 | MariaDB RG02 cases are explicitly skipped by the normal SQLite profile with a reason. Their existing host, database-name, MariaDB 10.11, and isolated-datadir guards remain mandatory when `RG02_ALLOW=1`. Skips are not counted as passes. |
| D - Actual regression | 0 | No failure remained after the Contract-correct fixture, expectation and profile corrections. No Application, Permission, Tenant, Security, Migration or Data Contract change was required. |

The classification is exhaustive: 153 + 3 + 16 + 0 = 172.

### Fixture and obsolete-test changes

- Added explicit test helpers for `single` and `unstarted` Product Account Eligibility. They fail if a fixture attempts to overwrite an existing Product Organization contract or if Admission is not enabled.
- Updated only the legacy fixtures whose positive journey requires current Eligibility and Organization session context. No global seeding, middleware bypass, guard weakening or Admission-off override was added.
- Multi-actor tests explicitly restore the acting user's Organization ID and membership `access_epoch`, preventing a later fixture from contaminating the request session.
- Invitation and Owner Onboarding second-company cases now assert `product_organization` rejection, no new Organization/Membership, unchanged identity/permissions, and pending/issued lifecycle state.
- New-account invitation, onboarding and first-workspace fixtures use explicit `unstarted`, then exercise the real Admission transition.

### Corrective verification

Normal isolated SQLite full suite (`APP_ENV=testing`, root test `APP_URL`, in-memory SQLite, Admission enabled):

- PASS: 510
- FAIL: 0
- SKIPPED: 16 (MariaDB RG02 profile only)
- Assertions: 4,162
- Duration: 276.31 seconds

The normal suite result does not count the 16 skipped MariaDB cases as PASS.

MariaDB RG02:

- Current corrective run: not re-executed because the approved disposable MariaDB database/datadir profile is not currently provisioned; the prior P5 cleanup intentionally removed it.
- Reused formal Evidence: 16 cases PASS on MariaDB 10.11.19 / InnoDB in the RG02 Formal Close report.
- Normal-profile behavior verified: 16 reasoned SKIPs; `RG02_ALLOW=1` plus dedicated database and datadir remain required, and all engine/profile guards still fail closed.

Focused Release Hardening / Product Organization / Scope 8 / Scope 9 regression:

- 62 passed
- 370 assertions
- 0 failed / 0 skipped
- Includes RH-01-07 framework checks, Client Promotion with Admission enabled, Product Organization Foundation/Journey/Operational, Scope 8 Project/Action including AI corrective journey, and Scope 9 Action Execution.

`git diff --check` passed. Production, Track A, R0, Deploy, Migration, Backup and Account Separation were not accessed or executed.

### G06 re-evaluation

- **G06 - Test / Evidence: PASS.** The normal suite is meaningful against the current Closed Contracts, the only skips are clearly separated MariaDB-profile tests with existing formal PASS Evidence, and there are no unexplained failures or Actual Regressions.
- **G05 - Release / Rollback Rehearsal: CONDITIONAL** (unchanged).
- **G12 / Production Release: NOT READY / NO-GO** (unchanged).
