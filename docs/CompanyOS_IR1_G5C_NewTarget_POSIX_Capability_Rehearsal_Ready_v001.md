# Company OS - IR-1 G5-C NewTarget POSIX Capability Rehearsal Ready v001

- Date: 2026-10-07 JST
- Gate: G5-C POSIX CAPABILITY REHEARSAL
- Frozen RC: `924af91188cc60d33ff87c91b94ecc1d539566e6`
- State: **HUMAN ONE-SHOT EXECUTION READY / NOT EXECUTED**
- Production Deploy / Migration / DNS change / SSL change: **NO-GO maintained**

## Approved boundary

Human approved at most one execution against the candidate-bound isolated
rehearsal root on the NewTarget. The rehearsal may create temporary fixtures
only below:

`/home/xs377816/company-os.jp/.ir1-g5-capability-924af91188cc60d33ff87c91b94ecc1d539566e6`

It verifies:

- POSIX symlink creation and resolution;
- same-filesystem atomic rename;
- mode `0700`, `0750`, and `0600` behavior;
- cleanup completion;
- residual entry count zero.

It does not create the target application topology. It does not change the
current public entry, Legacy, `.env`, shared storage, database, DNS, SSL, or a
deployment.

## Immutable input bindings

| Input | Binding |
|---|---|
| G5-B reconciled receipt | `ec24b30e62c34564aaaaccfa928edbac98f95e7e4748594422b641f4bbbae7c3` |
| G5-C remote script | `8d8ea110d0d7069af519f8c9cab8240891a050e7f366a620f5efc9fe86d852f3` |
| G5-C local helper | `84217d18251f3635414598428f38cda3f867fade5f50ccb941007bedbf965061` |
| SSH host | `sv17169.xserver.jp` |
| SSH user / port | `xs377816` / `10022` |
| Identity fingerprint | `SHA256:GvM1nK35B8W444sHzoURREhsjSFmY5JTOfxqXG1IT9g` |
| ED25519 Host Key fingerprint | `SHA256:JW8I6QkDccWlz2UNvbmnKlZzVn9Dc3GL7JLAmUjSLt8` |
| candidate known_hosts | `ba34cd1acb3c594d282a6c78a4352971f9f4831097c30f4423f6a326ceaa8983` |

The helper does not use `company-os-production` or any legacy SSH alias.

## One-shot and protected-boundary controls

- A local execution-state receipt is written before SSH starts.
- Any dedicated or earlier G5-C attempt evidence causes a pre-connection STOP.
- SSH uses BatchMode, strict Host Key verification, ED25519 only, explicit
  host/user/port/key/known_hosts, one connection attempt and no forwarding.
- A pre-existing rehearsal root is not removed.
- Cleanup removes only a root created by the current invocation.
- Exit and signal handlers attempt cleanup of that exact created root.
- The actual HOME must equal `/home/xs377816`.
- `company-os-app` must be absent before and after.
- The `app.company-os.jp` directory identity, exact entry count and immediate
  child metadata snapshot must be unchanged before and after.
- PASS requires `cleanup_state=complete` and `residual_entry_count=0`.
- PASS and STOP are both terminal. Retry is unavailable.

## Production-free preparation verification

| Verification | Result |
|---|---:|
| G5-C helper PowerShell AST parse | PASS |
| G5-C helper `-VerifyOnly` | PASS |
| remote script Git Bash syntax parse | PASS |
| G5 focused regression | 13 tests / 203 assertions PASS |
| Repository-wide regression | 761 passed / 17 skipped / 8 known unrelated failures / 6,583 assertions |
| Production / SSH / HTTP / DB connection during preparation | 0 |
| Production filesystem mutation during preparation | 0 |
| G5-C rehearsal execution | 0 |

The repository-wide suite was rerun after this G5-C preparation. The eight
failures are unchanged in class from G5-B:

- seven `Ir1G2PreflightV2ExecutionTest` cases require the already-preserved G2
  Corrective-2 one-shot Evidence directory to be absent;
- one pre-existing `CompanyNavigationTest` redirect expectation remains
  reproducibly different.

No G5-C test failed. The two additional passing tests are the new G5-C binding
and protected-boundary checks.

## Human execution gate

The approved operation is one PowerShell command invoking
`Invoke-G5CNewTargetPosixRehearsal.ps1` with no retry. After PASS or STOP, the
operator returns the output to Human + ChatGPT review. G5-C PASS does not
authorize deployment.

## Continuing boundary

`PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION=PENDING_G5_PUBLIC_ENTRY_GATE`

The three pre-existing public-entry files remain untouched. Their disposition
is outside this rehearsal and requires a later explicit Human gate.
