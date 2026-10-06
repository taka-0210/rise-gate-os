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
   an isolated public-entry following `current`, mode 0600 and cleanup, then
   remove it.
3. Target skeleton build: separately authorized creation of the exact empty
   `company-os-app`, `company-os-app/releases` and `company-os-app/shared`
   directories.
4. Shared state: migrate `.env` without outputting values and seed storage
   through an authorized inventory/checksum procedure. Direct linking to the
   legacy storage is prohibited.
5. Public entry: create the exact symlink only after topology verification.
6. Hand off to G6 for DNS and SSL. G5 does not change DNS or certificates.

Each numbered operation is a separate **1 Step = 1 Command / PASS or STOP**
Human gate. A PASS or STOP is terminal; blind retry is prohibited.

### G5-C NewTarget binding

The approved G5-C rehearsal uses only
`Invoke-G5CNewTargetPosixRehearsal.ps1`. The older generic
`Invoke-G5TargetEnvironment.ps1 -Step Rehearse` path remains bound to the
legacy SSH alias and must not be used for the NewTarget gate.

G5-C is bound to the reconciled G5-B PASS receipt, the explicit
`sv17169.xserver.jp` / `xs377816` SSH identity, the Xserver-panel ED25519 Host
Key trust anchor and the candidate-specific rehearsal script. It may create
and remove only
`/home/xs377816/company-os.jp/.ir1-g5-capability-924af91188cc60d33ff87c91b94ecc1d539566e6`.

The helper records an attempt before starting SSH and refuses any second
attempt. PASS requires protected target topology and public-entry snapshots to
remain unchanged, cleanup to complete and residual entries to be zero.
`PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION=PENDING_G5_PUBLIC_ENTRY_GATE`
continues after G5-C.

The first Human execution attempt on 2026-10-07 stopped in the local
incremental-state persistence step before the NewTarget SSH process started.
Its `execution-state.json` and `execution-state.json.tmp` are immutable failure
Evidence and must not be removed, overwritten, or used to enable a retry. The
source corrective replaces an existing state file atomically with
`System.IO.File.Replace` and includes a Production-free three-generation
persistence verification mode.

Human + ChatGPT approved exactly one corrective execution on 2026-10-07. It
must be invoked with `-Attempt Corrective1`. Before SSH starts, the helper
verifies the exact hashes, two-entry shape and sanitized state contract of the
initial Evidence. It never writes into that initial directory. The corrective
attempt records only below the separate one-shot Evidence root
`production-g5c-new-target-rehearsal-corrective-1-924af91188cc60d33ff87c91b94ecc1d539566e6`.
If that root already exists, or any initial Evidence binding differs, the
helper stops before SSH. PASS or STOP exhausts this authorization; no further
retry is permitted. G5 remains OPEN for Human + ChatGPT review after execution,
and a G5-C PASS is not Deploy authorization.

The Corrective1 execution subsequently completed with G5-C Formal PASS. The
receipt proves POSIX symlink, same-filesystem atomic rename, mode 0600,
isolated public-entry following `current`, cleanup complete and residual zero.
This closes the filesystem capability gap only. It does not create the actual
target topology or authorize Deploy.

### Target Skeleton Build Human gate

The next separately authorized operation uses only
`Invoke-G5TargetSkeletonBuild.ps1`. It is bound to the G5-B reconciled receipt,
the G5-C Corrective1 PASS receipt/state, the NewTarget SSH and Host Key identity,
`target-skeleton-contract.json`, and `build-target-skeleton.sh`.

The exact creation set is limited to three empty directories owned by UID
`20046`, GID `1000`, with mode `0750`:

```text
/home/xs377816/company-os.jp/company-os-app/
  releases/
  shared/
```

The operation builds the empty structure under the exact candidate-bound
staging sibling and atomically renames it to `company-os-app`. Existing
topology or staging paths cause a pre-mutation STOP. The local helper records
one attempt before SSH and refuses any later invocation, whether the first
result is PASS or STOP. This is at-most-once fail-closed behavior; successful
execution is not replayed and blind retry is prohibited.

The build expressly excludes `shared/.env`, `shared/storage`, application
releases, `current`, `current.previous`, release-marker binding, Migration,
DNS, SSL and Deploy. It snapshots the live public-entry metadata before and
after and requires the exact pre-existing `.user.ini`, `default_page.png` and
`index.html` set to remain unchanged. Their content is not read.

Before atomic publish, failure cleanup uses `rmdir` only on the exact empty
staging directories. A post-publish failure may roll back only an exact empty
G5-created skeleton, again using `rmdir`. Recursive deletion is prohibited;
unknown or non-empty state is retained for Human review. Rollback after a PASS
is a separate Human gate and is not included in the build command.

PASS requires exact type, owner, group, mode and empty-entry verification,
atomic publish, staging residual zero, protected public-entry metadata
unchanged, and a sanitized local receipt. PASS or STOP returns to Human +
ChatGPT; neither result authorizes another attempt or a following operation.
`PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION=PENDING_G5_PUBLIC_ENTRY_GATE`
continues unchanged.

The one authorized Human execution subsequently completed with Target
Skeleton Build Formal PASS. The candidate-bound receipt and final state prove
the exact empty three-directory Skeleton, UID `20046`, GID `1000`, mode
`0750`, same-filesystem atomic publish, complete cleanup and zero staging
residual. The public entry and Legacy remained unchanged; `.env`, shared
storage, Application releases, `current`, `current.previous`, DB, Migration,
Release marker, DNS, SSL and Deploy were not attempted. The one-shot
authorization is exhausted and must not be replayed.

Formal PASS Evidence:

- `target-skeleton-build.json` SHA-256:
  `51fb756414218c7b4a742c03620d197783d3191cbb0ef5f803a74ac3e91c2057`
- `execution-state.json` SHA-256:
  `54bb6edcc1e5d23fc2066f30b8cfa40d713da8168dbf10ec22e6c03349c9a0d3`
- Production-free formal reconciliation: 86 assertions PASS

The next sequence item is Shared State. Its Production-free preparation bound
the source/target inventory, runtime owner and readability, exact storage seed
and final-delta procedure, permissions, backup/restore dependency, cleanup
and Evidence Contract. No Shared State mutation was authorized by the
Skeleton PASS alone.

### Shared State Human gate

The initially authorized `Invoke-G5SharedState.ps1` command ran once and
stopped at `SOURCE_READ_ONLY_INVENTORY` with `REMOTE_STATUS_MISSING`. The
retained state SHA-256 is
`bb98d39709625d619d0365b57850613580237be837dcc85a863d00adfbcb248d`.
It proves two Source native process starts, the successful read-only preflight,
zero Target connections and zero Production mutation. The inventory process
exit code, stdout/stderr metadata, remote projector start, `.env` value read
and storage inventory start are UNKNOWN because the initial Evidence Contract
did not persist them. The initial attempt must not be replayed.

Production-free execution of the initial projector through the exact
`php -- inspect-source ...` stdin/argv shape reproduces the implementation
mismatch: PHP executes the stdin script, but the initial projector's `STDOUT`
constant output fails in stdin execution before a sanitized status can be
emitted. Corrective-1 keeps the same stdin/argv shape and uses `echo`; it also
saves exit code plus stdout/stderr hash and byte counts before safe-output
parsing. Actual initial remote exit/stderr content remains UNKNOWN because it
was not persisted. Raw streams and secret values remain unstored.
Corrective-1 is bound to the immutable initial Evidence and a separate
Evidence directory. Its Production-free verification is PASS, but no
Corrective-1 Production execution or retry is authorized.

The frozen source remains the Legacy application's
existing `.env` and `storage/app`, but the existing `.env` is **not** copied as
the published NewTarget configuration. The helper derives the exact 172-key
allowlist from the frozen candidate contract, omits unknown Legacy keys,
requires the candidate's security-critical keys and reconstructs a sorted
target payload with explicit NewTarget values for `APP_URL`, production mode,
debug off, JST, secure session cookie and fixture seed off.

Secret bytes never enter Repository, audit Evidence, terminal output or a
local persistent file. OpenSSH `scp -3` relays the exact source `.env` and
`storage/app` over authenticated encrypted channels into the candidate-bound
NewTarget staging root. The raw source `.env` is mode `0600` in staging and is
removed before publish. Only hashes, byte/count totals, modes and disposition
fields are retained as Evidence.

The exact publish set is:

```text
/home/xs377816/company-os.jp/company-os-app/shared/.env      regular 0600 20046:1000
/home/xs377816/company-os.jp/company-os-app/shared/storage/ directory 0750 20046:1000
```

Seeded storage files are normalized to `0640`; directories are `0750`.
`storage/app` is manifest-matched by relative type/path, size and SHA-256.
Required runtime directories are created empty, and PHP CLI running as UID
`20046` must prove `.env` readability and storage write/delete capability.
Web-runtime proof remains deferred until a release is bound.

This is an initial storage seed only. A separately authorized final delta is
required before any public-entry or traffic binding. The operation does not
connect to the database and does not prove backup usability or DB restore
readiness. `usable_backup=unknown` and `db_restore_readiness=blocker` remain
open.

Before publish, failure cleanup may remove only the exact candidate-bound
staging root after its marker, owner and mode are validated. Once either
published `.env` or storage exists, automated deletion is prohibited; state
is retained for a separate Human rollback gate. Legacy, the three existing
public-entry files, releases, `current`, `current.previous`, Migration,
Release marker, DNS, SSL and Deploy are excluded.

The helper records a separate Corrective generation before connecting and
refuses replay. PASS or STOP exhausts any future explicit one-shot
authorization. It maintains
`PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION=PENDING_G5_PUBLIC_ENTRY_GATE`
and returns to Human + ChatGPT without starting Application placement or any
later gate.

## Permission plan

- `shared/.env`: target `0600`; no group/other permission.
- `shared/storage`: directories `0750`, files `0640`, owned by
  `20046:1000`; PHP CLI owner-read/write probes are mandatory. World write is
  prohibited.
- Releases: immutable after verification; runtime write is limited to shared
  storage.
- No permission is changed during read-only discovery.

The current Legacy `.env` mode `0604` is not changed by this gate. The
NewTarget published `.env` is independently reconstructed as `0600`; no
Legacy permission hardening is implied.

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
user cron, external writer enablement, active
transaction/metadata lock visibility and DB/Application collation difference
remain open until separate Evidence closes them. POSIX symlink and atomic
rename capability are closed by G5-C Formal PASS, and exact empty Skeleton
construction is closed by Target Skeleton Build Formal PASS. Shared State
initial attempt is STOP; Corrective-1 is Production-free verified but requires
a new explicit Human Gate. No storage seed has been published. Any future
initial seed will not close the required final delta, usable backup or DB
restore blocker. Application placement, `current` / `current.previous`,
public-entry disposition and binding remain separate gates. DNS and SSL remain
G6-only; Migration and Deploy remain unapproved.
