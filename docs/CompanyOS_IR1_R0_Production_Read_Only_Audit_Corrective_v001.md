# Company OS｜IR-1 R0 Production Read-only Audit Corrective v001

Execution note: the later Human-confirmed legacy fixed-root topology and Master v054 Production URL boundary are incorporated in `CompanyOS_IR1_R0_Legacy_Topology_Corrective_Decision_v001.md`. Use that package and its updated procedure for any future R0 approval; do not execute the earlier placeholder host command.

Date: 2026-10-01 JST

Status: Provider-free Corrective complete / Production execution not authorized

Recommended decision: **READY FOR ONE READ-ONLY R0 AUDIT**
Production Deploy: **NO-GO maintained**

## 1｜Safety boundary observed

- Xserver connection: `0`
- Production DB connection: `0`
- Production Command execution: `0`
- Deploy: `0`
- Production Migration: `0`
- Production `.env` change: `0`
- Production symlink change: `0`
- DNS change: `0`

All runtime verification used local isolated SQLite and temporary filesystem fixtures only.

## 2｜Corrective result

1. Raw Organization, database and application path identifiers are replaced with APP_KEY-backed HMAC-SHA256 references.
2. Raw exception messages are never emitted. Failures use allowlisted `safe_error_code`, `failure_stage` and `evidence_completeness`.
3. A pre-execution SQL guard rejects every statement that is not classified as `SELECT`, `SHOW`, `DESCRIBE` or `PRAGMA`.
4. MySQL / MariaDB CHECK constraints are collected from `information_schema` with read-only SQL.
5. Bundle identity, manifest hash, source tree hash, vendor tree hash, critical file hashes and exact Migration set are verified before DB inspection.
6. Application / DB inspection and Host / Filesystem / Process inspection are separate executables.
7. Production `.env` is not included, copied or linked. The future approved operation loads it into process memory only and forces framework diagnostics to stderr.
8. The standalone Host audit does not bootstrap Laravel or connect to the DB.
9. Queue, mail and HTTP Provider fakes verify that the Application audit dispatches or sends nothing.
10. The builder uses `git archive` of the exact IR-1 candidate and overlays only nine allowlisted corrective files.

## 3｜Exact Audit Bundle identity

- Exact source candidate: `924af91188cc60d33ff87c91b94ecc1d539566e6`
- Bundle ID: `5ae58cd65bad5c72a2e42012b3d4119a7086e1e81b53d70ac536eaead9a1f1ab`
- Archive SHA-256: `6943fab18a3789cd80c89ed65c324e059ba430dc56323e2854f02c7d8145b59f`
- Bundle manifest SHA-256: `abf35e323850e3d0fee79d89ddfc4774bc35fa49f7d9520b545ed5860d4f2d04`
- Candidate source tree SHA-256: `bb32cad208d4055555b94a2a2639a541f7f829d1b60a9d2cc8e67ce60d92d282`
- Locked vendor tree SHA-256: `b94dfd1a98f98c28d0bfcbfa19dac63256e7a59993b62709fe857625912e3c10`
- Corrective overlay SHA-256: `8cc9956213309ecfb47874490390a611deb911ceccf69c37a49b67525481df71`
- Migration manifest SHA-256: `b83438cba2b7c0a485d57496ee81d3714223e218ad8d7c33bfc658eee3004c13`
- Candidate Migration count: `94`
- Corrective overlay files: `9`
- Critical files verified at runtime: `14`
- Output schema version: `2`

Local artifact:

`storage/app/release-audit/ir1-r0-audit-bundle-924af91188cc60d33ff87c91b94ecc1d539566e6.tar.gz`

The same bundle was built twice independently with the same archive SHA-256.

## 4｜Responsibility-separated R0 package

### Application / DB audit

Entry:

`deployment/r0-audit/r0-artisan.php release:audit-r0`

Responsibilities:

- exact Bundle integrity
- Laravel and PHP version
- HMAC-masked application path and DB identity
- runtime cache / session / queue / filesystem configuration
- DB engine / version / configured charset and collation
- Migration ledger / exact pending / ledger-only Migration
- Business table counts
- HMAC-masked active owner presence
- pending invitation count
- table / column / index / FK / CHECK inventory
- executed SQL statement class and count

### Host / Filesystem / Process audit

Entry:

`deployment/r0-audit/r0-host-audit.php`

Responsibilities:

- PHP CLI version and loaded extensions
- `current` / `current.previous` / shared / backup path existence
- symlink state and hashed targets
- readability / writability / owner UID / group GID / permissions
- disk total and free bytes
- allowlisted release marker fields, if present
- queue worker and scheduler process counts when `/proc` is readable
- scheduler entry count when the user crontab is readable
- bounded backup inventory without filenames or data bodies

## 5｜Evidence capability matrix

| Evidence | Status | Notes |
|---|---|---|
| Audit Bundle source identity | SUPPORTED | Fixed to `924af911...` |
| Bundle / critical-file integrity | SUPPORTED | Runtime fail closed |
| Production release marker | SUPPORTED IF PRESENT | Missing marker remains UNKNOWN |
| `current` / `current.previous` | SUPPORTED | No link mutation |
| Shared path topology | SUPPORTED | Raw path not emitted |
| PHP CLI version / extensions | SUPPORTED | Standalone Host audit |
| Disk / ownership / permissions | SUPPORTED | Read-only filesystem metadata |
| DB engine / version | SUPPORTED | Application audit |
| DB charset / collation | SUPPORTED | Config plus table metadata |
| Migration ledger / exact pending | SUPPORTED | Candidate set fixed at 94 |
| Tables / columns / Index / FK | SUPPORTED | Metadata SELECT only |
| CHECK constraints | SUPPORTED | MySQL / MariaDB metadata SELECT |
| Protected Organization / Owner existence | SUPPORTED | HMAC reference and counts only |
| Session / cache / queue driver | SUPPORTED | Config state, not functional health |
| Queue worker process | SUPPORTED IF VISIBLE | UNKNOWN if `/proc` is unavailable |
| Cron / scheduler entry | SUPPORTED IF AVAILABLE | Current user crontab only |
| Backup inventory | SUPPORTED IF PATH SUPPLIED | No filenames or contents emitted |
| Restore readiness | UNSUPPORTED | Requires a separate restore rehearsal |
| External writers | UNSUPPORTED | Requires Human / operational inventory |

`UNSUPPORTED` and `UNKNOWN` must not be inferred as PASS.

## 6｜Automated verification

Focused R0 / Release tests:

- `9 passed`
- `73 assertions`

Verified contracts:

- mutation SQL rejected before execution
- observed R0 SQL classes are read-only
- raw Organization identifier absent
- DB / application path use HMAC references
- raw exception and secret canaries absent
- queue dispatch `0`
- mail send `0`
- HTTP Provider call `0`
- Host audit file mutation `0`
- Host audit symlink mutation `0`
- exact candidate binding
- candidate Migration count `94`
- post-IR1 Migration contamination `0`
- Bundle source / vendor / manifest integrity PASS
- `.env` included in Bundle `0`
- process-memory `.env` bootstrap PASS

Final isolated Bundle execution:

- status: `PASS`
- Bundle integrity: `PASS`
- SQL safety: `PASS`
- SQL statements: `45`
- rejected statements: `0`
- pending Migration after isolated candidate setup: `0`
- raw Organization ID key: `0`

Full Repository regression:

- `676 passed`
- `1 failed`
- `17 skipped`
- `5534 assertions`

The single failure is the pre-existing out-of-scope `CompanyNavigationTest::regular login ignores a stale forbidden intended url`. R0 focused tests and Release Hardening tests are green, and no failure entered the changed R0 boundary.

## 7｜Expected load

The isolated candidate audit executed 45 SQL statements. Production is expected to execute approximately 40–60 SELECT or metadata statements, including up to 17 table counts. Large InnoDB tables can make exact `COUNT(*)` the dominant load. Run once, in a low-traffic window, and do not retry automatically.

The Host audit reads process metadata, filesystem metadata and at most 10,000 backup file entries. It does not open backup contents.

## 8｜Future Human operation — not yet authorized

1. Verify the archive SHA-256 against this document.
2. Place and extract it into a new non-public audit-only directory without touching `current`, `current.previous`, shared storage or `.env`.
3. Execute the Application / DB audit once with the approved Production `.env` supplied through `IR1_R0_ENV_FILE`.
4. Execute the Host audit once with the approved topology paths.
5. Capture stdout only. Do not combine stderr with Evidence.
6. Do not retry a FAIL / INCONCLUSIVE result without Human + ChatGPT review.

Exact commands are documented in `deployment/r0-audit/R0_AUDIT_PROCEDURE.md`. Its path placeholders must be replaced only after Human confirms the sanitized Xserver topology.

## 9｜Remaining risk

- Audit Bundle placement / extraction is itself a Production filesystem change and still requires explicit Human approval.
- Shared-hosting restrictions may hide `/proc` or user crontab state; those fields will remain UNKNOWN.
- A missing legacy release marker will leave current release identity UNKNOWN rather than guessed.
- CHECK metadata visibility depends on the DB account's information-schema permissions.
- Exact table counts can be slow on large InnoDB tables.
- Backup existence does not prove restore readiness.
- External writers cannot be proven from Application or process snapshots alone.

## 10｜Recommended decision

**READY FOR ONE READ-ONLY R0 AUDIT**

This recommendation authorizes no Production action by itself. Production placement, extraction and the single R0 execution remain a separate Human Gate. Production Deploy and Migration remain NO-GO.
