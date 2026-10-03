# Company OS - IR-1 G5 Target Environment Preparation Evidence v001

- Date: 2026-10-03 JST
- Prior gate: G4 FORMAL CLOSE / G5 TARGET ENVIRONMENT READY
- Frozen RC: 924af91188cc60d33ff87c91b94ecc1d539566e6
- Application Artifact SHA-256: 2de9b840627d0dbfd1beabaca7e8609dc9e16be2fd9c3021e3dfdc2c764cdb69
- G4 Package SHA-256: 5c99d35d03bcfd08bb83e0ad81cb90ed0cf4ef7e4eb4f363930126e7c4c9118a

## Decision

**G5 PRODUCTION-FREE PREPARATION COMPLETE / READ-ONLY TARGET DISCOVERY WAITING**

No Production connection, placement, directory creation, symlink change,
environment-file change, permission change, Deploy, Migration, DNS change, or
SSL change was performed.

## Exact G5 package identity

| Item | Evidence |
|---|---|
| Contract | company-os.ir1.g5-target-environment.v1 |
| G5 Package SHA-256 | f89a71cbfe453b2e9bd74d20fdf5e3bab9c2b98e28775ddf755922713ca8a232 |
| Package bytes | 5592 |
| Manifest SHA-256 | b54040df69fb1c4658ff73f4920f482087d56e9d7fc223208c098fa2dbe5f1e1 |
| Build Evidence SHA-256 | 2a6b10d48de195eaa9749be3980a1a18e862d244385701215ef33008846b5a19 |
| Deterministic rebuild | PASS |
| Candidate / G4 binding | PASS |

## Exact target topology

    $HOME/company-os.jp/company-os-app/
    |-- releases/<release-id>/
    |-- shared/.env
    |-- shared/storage/
    |-- current -> releases/<release-id>
    +-- current.previous -> releases/<previous-release-id>

    $HOME/company-os.jp/public_html/app.company-os.jp
    +-- public entry -> ../../company-os-app/current/public

The legacy os.rise-gate.com topology is outside every G5 target mutation
allowlist. The target uses its own release root, shared environment, shared
storage, and public entry.

## Prepared contracts

- Read-only target discovery checks path state, collision state, filesystem
  device, disk, PHP CLI, and required POSIX capabilities only.
- Isolated capability rehearsal uses one candidate-bound temporary directory
  and verifies symlinks, same-filesystem atomic rename, public-link resolution,
  mode 0600, and cleanup.
- Human operation is one step equals one command, PASS or STOP, with no
  automatic retry.
- G5 does not deploy the application, migrate the database, change DNS / SSL,
  or switch public traffic.
- Output excludes secrets, credentials, raw environment values, raw paths,
  user names, and raw exceptions.

## Permission hardening plan

- shared/.env target mode is 0600 and its owner must be the approved
  deployment/application account.
- Release code is immutable after placement; files are 0644 and only required
  directories/scripts are executable.
- shared/storage uses least privilege required by the PHP runtime; no
  inheritance from the legacy application is assumed.
- Permission changes require a separately authorized Production mutation gate.

## Rollback and cleanup boundary

- Discovery is read-only and needs no rollback.
- Capability rehearsal is confined to
  $HOME/company-os.jp/.ir1-g5-capability-<candidate>.
- Rehearsal must remove its temporary tree and prove zero remaining entries
  before PASS.
- It does not reference, rename, link, or remove any rise-gate.com or
  os.rise-gate.com path.
- Future release rollback is code-only: atomic current restoration from
  current.previous; additive DB schema remains.

## Automated verification

| Verification | Result |
|---|---:|
| G5 + G4 focused regression | 11 tests / 129 assertions PASS |
| Boundary simulation | 7 scenarios / 15 assertions PASS |
| Bash syntax | PASS |
| PHP / test syntax | PASS |
| Helper VerifyOnly | PASS |
| Package deterministic rebuild | PASS |
| Production connection | 0 |
| Production mutation | 0 |

## Continuing blockers

| Evidence gap | Status |
|---|---|
| usable backup | UNKNOWN |
| DB restore readiness | BLOCKER |
| Release marker to Application code binding | BLOCKER |
| .env permission 0604 hardening | BLOCKER |
| user cron | UNKNOWN |
| external writer Production enablement | UNKNOWN |
| active transaction / metadata lock | UNSUPPORTED |
| DB default collation / Application collation difference | OPEN RISK |
| POSIX symlink / same-filesystem atomic rename on exact target | UNKNOWN until G5 discovery/rehearsal |

No continuing blocker is treated as resolved by this package.

## Next separately authorized gate

**ONE G5 READ-ONLY TARGET DISCOVERY**

Acceptance boundary:

- SSH connection: maximum one
- Production file / directory / symlink / permission mutation: zero
- DB connection / SQL: zero
- Environment value read/output: zero
- Legacy application mutation: zero
- Output: sanitized PASS or STOP

A PASS may make the isolated capability rehearsal eligible. It does not
authorize that mutation, application placement, Deploy, Migration, DNS, or SSL.

## Recommended decision

**G5 remains OPEN. Authorize the separate read-only target discovery before
the first Production mutation gate. Production Deploy / Migration remain
NO-GO.**
