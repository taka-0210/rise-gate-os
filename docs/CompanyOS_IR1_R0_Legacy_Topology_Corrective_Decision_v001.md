# Company OS｜IR-1 R0 Legacy Topology Corrective Decision v001

Date: 2026-10-01 JST

Recommended decision: **READY FOR ONE READ-ONLY R0 AUDIT**

Production Deploy: **NO-GO**

Production execution performed by this Corrective: **0**

## 1｜Decision boundary

Human + ChatGPT confirmed the following Master v054 Production Architecture Decision:

- Service Site: `company-os.jp`
- Application Production URL: `app.company-os.jp`
- `os.rise-gate.com`: pre-migration Production environment
- the Release / Infrastructure phase changes the Application deployment target to `app.company-os.jp`
- the current `os.rise-gate.com` workflow is not changed before that migration phase

This package keeps three responsibilities separate:

1. R0 Audit reads the current `os.rise-gate.com` state.
2. IR-1 Production Architecture later constructs the immutable release topology.
3. Application Production URL Migration later changes `os.rise-gate.com` to `app.company-os.jp`.

R0 does not perform items 2 or 3.

## 2｜Human observation disposition

The following Xserver File Manager observations are auxiliary topology input and are not promoted to formal PASS Evidence:

- fixed Laravel candidate: `rise-gate.com/rise-gate-os`
- public bridge candidate: `rise-gate.com/public_html/os.rise-gate.com`
- public `index.php` references `dirname(__DIR__, 2).'/rise-gate-os'`
- parent marker candidate: `rise-gate.com/public_html/.rise-gate-deploy-revision`
- parent backup candidate: `rise-gate.com/public_html/_backup`
- temporary staging candidate: home `.rise-gate-os-deploy`

The legacy profile is required because these observations align with the retired fixed-root deployment, not with `current`, `current.previous` and shared-state symlinks.

## 3｜Corrective

The standalone Host audit now requires an explicit topology profile.

### `legacy-fixed-root`

- observes application, public bridge, embedded `.env` location, embedded storage, temporary staging and backup metadata separately
- validates that every supplied audit path is absolute and not a filesystem root
- reads at most 4 KiB from the legacy revision marker and accepts only one 40-character hexadecimal commit identity
- does not infer that a marker in the parent `public_html` belongs to the Company OS application
- hashes the public `index.php` and returns only boolean bridge checks, never its source or raw path
- records `current`, `current.previous` and separated shared state as `UNSUPPORTED`, not as false PASS
- records public backup exposure and restore readiness as `UNKNOWN` or `UNSUPPORTED`
- records Application Production URL Migration as `OUT_OF_SCOPE`

### `immutable-release`

The prior `current`, `current.previous`, shared root and `.release-manifest.json` observation remains available only when this profile is selected explicitly. The R0 Production procedure selects `legacy-fixed-root` for the current environment.

## 4｜Output safety

The legacy profile emits only:

- version and validated commit identity
- count, boolean, size and JST timestamp
- permissions, owner/group numeric metadata and disk totals
- SHA-256 path, symlink target and public-index references
- allowlisted safe status and reason values

It does not emit:

- `.env` values
- Credential, Secret or Authorization data
- raw filesystem paths or Xserver account identity
- public `index.php` source
- backup filenames or contents
- malformed marker contents
- raw exception messages

## 5｜Automated verification

### Focused R0 Corrective

- `6 passed`
- `63 assertions`
- valid legacy marker: sanitized commit Evidence
- malformed marker: `UNKNOWN` with raw value absent
- legacy bridge and derived application root: boolean Evidence
- file and symlink mutation: `0`
- raw path, `.env` canary and backup filename output: `0`
- Application URL migration: `OUT_OF_SCOPE`

### Release Hardening regression

- `4 passed`
- `29 assertions`

### Full Repository regression

- `677 passed`
- `1 failed`
- `17 skipped`
- `5,553 assertions`
- duration: `364.60s`

The one failure is the pre-existing out-of-scope `CompanyNavigationTest::regular login ignores a stale forbidden intended url`. The 17 skips are the existing MariaDB RG02 profile and the closed P1-I real Provider gate. No failure entered the R0 or Release boundary.

### Final built Bundle verification

- Host audit: `PASS`
- topology profile: `legacy-fixed-root`
- public bridge contract: `true`
- derived application root match: `true`
- marker format: `SUPPORTED`
- marker Application binding: `UNKNOWN`
- immutable current link: `UNSUPPORTED`
- URL migration: `OUT_OF_SCOPE`
- public backup exposure: `UNKNOWN`
- raw secret output: `0`
- raw path output: `0`
- Application / DB audit: `PASS`
- Bundle integrity: `PASS`
- SQL safety: `45 SELECT / 0 rejected`

Bundle inventory:

- `.env`: `0`
- Git metadata: `0`
- candidate Migration: `94`
- later AI Common / Realtime / Management Design Migration: `0`

## 6｜Exact Audit Bundle

- source commit: `924af91188cc60d33ff87c91b94ecc1d539566e6`
- Bundle ID: `5ba3c0fd459cabe885249d85dd13ffafbe087693435a5e24a483f5ad4a24a4c0`
- archive SHA-256: `a2cc319f42a7b0b3f84afc3077aeda1af0aa95d40f96e56103b18ad31b448b0d`
- Bundle Manifest SHA-256: `a15502cb7e832ef44affecd346d582f4b8550967fb55a2cd5a327d23005b9fd7`
- Migration Manifest SHA-256: `b83438cba2b7c0a485d57496ee81d3714223e218ad8d7c33bfc658eee3004c13`
- source tree SHA-256: `1aa568892afa67e4b83ea1f63d675237b9490382a2144b171329eb8c95f9aae8`
- vendor tree SHA-256: `b94dfd1a98f98c28d0bfcbfa19dac63256e7a59993b62709fe857625912e3c10`
- audit overlay SHA-256: `02f659f429596184a586b570978a603f6c4fa1a6d39f4e3e791f436dfc8d1090`

## 7｜R0 capability disposition

| Evidence | Disposition |
|---|---|
| Current fixed Laravel root / public bridge | SUPPORTED by legacy profile |
| Embedded `.env` / storage location metadata | SUPPORTED without reading content |
| Legacy revision marker format / commit value | SUPPORTED if valid |
| Marker binding to `os.rise-gate.com` | UNKNOWN |
| Temporary staging metadata | SUPPORTED |
| Backup inventory | SUPPORTED if path supplied |
| Backup public exposure | UNSUPPORTED / UNKNOWN |
| Restore readiness | UNSUPPORTED |
| `current` / `current.previous` in current legacy state | UNSUPPORTED |
| Future immutable topology readiness | OUT OF SCOPE for R0 |
| `app.company-os.jp` DNS / vhost / TLS / deploy | OUT OF SCOPE for R0 |
| External writers | UNSUPPORTED |

## 8｜Expected Production load

The isolated Application audit executed 45 read-only SQL statements. Production expectation remains approximately 40 to 50 SELECT or metadata statements; exact counts on large InnoDB tables can dominate latency. The Host audit scans metadata and at most 10,000 backup file entries without opening backup contents.

## 9｜Next Human Gate

No Production command is authorized by this document.

The next Human + ChatGPT review may approve exactly one R0 placement and execution using the updated procedure. The Audit Bundle must be placed in a new non-public audit-only directory, never in `public_html`, `_backup`, the legacy application root, a future immutable release root or shared state.

After R0 Evidence is obtained, a separate Release Plan must define:

1. immutable releases, shared state and first `current` transition
2. `app.company-os.jp` vhost, DNS, certificate and base-URL readiness
3. data / upload / session / cache / queue / scheduler continuity
4. verification, maintenance, rollback and `os.rise-gate.com` disposition

That later plan must not assume that the legacy fixed root is the final Production deployment target.

## 10｜Current gate

- R0 Audit Package: **READY FOR ONE READ-ONLY R0 AUDIT**
- R0 Production execution: **HUMAN APPROVAL REQUIRED**
- IR-1 Production Deploy: **NO-GO**
- DNS / Domain / `.env` / symlink / Migration / Deploy change: **0**
