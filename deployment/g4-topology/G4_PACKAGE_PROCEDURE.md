# IR-1 G4 Immutable Topology Package

## Identity

- Contract: `company-os.ir1.g4-immutable-topology.v1`
- Release ID: `ir1-924af91188cc60d33ff87c91b94ecc1d539566e6`
- Frozen source commit: `924af91188cc60d33ff87c91b94ecc1d539566e6`
- Frozen application artifact SHA-256: `2de9b840627d0dbfd1beabaca7e8609dc9e16be2fd9c3021e3dfdc2c764cdb69`

## Topology contract

```text
<topology-root>/
  releases/<release-id>/
  shared/.env
  shared/storage/
  current             -> releases/<active-release-id>
  current.previous    -> releases/<previous-release-id>

<public-entry>         -> <topology-root>/current/public
```

The public entry remains stable. A release switch replaces `current` by an
atomic same-filesystem symlink rename. `current.previous` records the code
rollback target. A code rollback never rolls back the database; additive
schema is retained.

## Separated operations

Each operation is a separate fail-closed command and must receive separate
Human authorization.

1. `verify-topology.sh`: read-only identity and topology verification.
2. `install-release.sh`: verify the exact artifact and create one new,
   immutable release directory. It does not change `current`.
3. Production migration: deliberately outside this G4 package and not
   authorized by G4.
4. `switch-release.sh`: atomically update `current.previous`, then
   `current`, after both old and new releases verify.
5. `verify-topology.sh`: read-only post-switch verification.
6. `rollback-release.sh`: code-only rollback to `current.previous`.

## Fail-closed boundaries

- Absolute allowlisted paths only; root and shell metacharacters are rejected.
- Package checksums, manifest, contract, frozen source and artifact are bound
  before any operation.
- Existing release or staging paths are never overwritten.
- Shared `.env` and `storage` must already exist and are never printed.
- Public entry must be a symlink to `current/public`.
- Blind retry is prohibited after PASS or STOP.
- Output is sanitized and contains no credential, environment value, raw user
  data or business data.

## G5 boundary

G4 produces and verifies the operator package only. G5 must establish and
inspect the target filesystem, same-filesystem rename behavior, POSIX symlink
support, ownership, permissions, usable backup, restore readiness and the
`app.company-os.jp` public-entry boundary before any Production mutation.
No command in this document authorizes deploy, migration, DNS, SSL, `.env`,
permission or symlink changes.
