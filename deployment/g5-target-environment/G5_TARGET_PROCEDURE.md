# IR-1 G5 Target Environment / app.company-os.jp

## Boundary

G5 constructs a new target below `$HOME/company-os.jp/`. It never updates,
renames, links, copies into or deletes from the legacy
`$HOME/rise-gate.com/` prefix.

The exact target is:

```text
$HOME/company-os.jp/
  company-os-app/
    releases/
    shared/.env
    shared/storage/
    current
    current.previous
  public_html/
    app.company-os.jp -> $HOME/company-os.jp/company-os-app/current/public
```

The service-site document root `company-os.jp/public_html/` is protected.
Only its exact child `app.company-os.jp` may become the application public
entry after a separate Human authorization.

## G5 sequence

1. Read-only target discovery: inspect path types/counts, required commands,
   PHP version, device identity and disk without writing.
2. Isolated capability rehearsal: create one exact temporary directory below
   the target domain root, test POSIX symlink, same-filesystem atomic rename,
   public-entry following `current`, mode 0600 and cleanup, then remove it.
3. Target skeleton build: separately authorized creation of
   `company-os-app/releases` and `company-os-app/shared/storage`.
4. Shared state: migrate `.env` without outputting values and seed storage
   through an authorized inventory/checksum procedure. Direct linking to the
   legacy storage is prohibited.
5. Public entry: create the exact symlink only after topology verification.
6. Hand off to G6 for DNS and SSL. G5 does not change DNS or certificates.

Each numbered operation is a separate **1 Step = 1 Command / PASS or STOP**
Human gate. A PASS or STOP is terminal; blind retry is prohibited.

## Permission plan

- `shared/.env`: target `0600`; no group/other permission.
- `shared/storage`: owner writable. Exact directory/file modes are fixed only
  after the PHP runtime owner is established. World write is prohibited.
- Releases: immutable after verification; runtime write is limited to shared
  storage.
- No permission is changed during read-only discovery.

The current legacy `.env` mode `0604` remains a blocker until an authorized
hardening operation proves that the PHP runtime can read the hardened file.

## Rollback and cleanup

- Read-only discovery has nothing to roll back.
- Capability rehearsal removes only its exact temporary root and verifies zero
  residual entries.
- Before public binding, cleanup may remove only G5-created empty paths.
- Shared `.env` and storage are never deleted by automated rollback.
- After switch, rollback is the G4 code-only rollback; additive DB schema is
  retained.
- The legacy `os.rise-gate.com` application remains available and unchanged
  until a later explicit disposition decision.

## Continuing blockers

Usable backup, DB restore readiness, legacy marker/application binding,
`.env` hardening, user cron, external writer enablement, active
transaction/metadata lock visibility, DB/Application collation difference and
POSIX symlink/atomic rename remain open until separate Evidence closes them.
