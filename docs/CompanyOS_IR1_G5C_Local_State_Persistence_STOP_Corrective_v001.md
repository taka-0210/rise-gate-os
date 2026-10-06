# Company OS - IR-1 G5-C Local State Persistence STOP / Corrective v001

- Date: 2026-10-07 JST
- Gate: G5-C POSIX CAPABILITY REHEARSAL
- Frozen RC: `924af91188cc60d33ff87c91b94ecc1d539566e6`
- Executed helper commit: `c20f657921f135c25a2f407a35deeb17da9e723b`
- Executed helper SHA-256: `84217d18251f3635414598428f38cda3f867fade5f50ccb941007bedbf965061`
- Corrective helper SHA-256: `7c6d5d74d77dd5768146ed57c3b3ca62bdc8e9f2ecfdcfd6341f8e0106a11fb0`
- Result: **LOCAL STOP BEFORE NEWTARGET SSH PROCESS START**
- Gate disposition: **G5 OPEN / RETRY NOT AUTHORIZED**

## Exact failure boundary

The Human executed the approved G5-C command once. The remote sanitized PASS /
STOP contract was never started. The local Windows PowerShell helper stopped at
line 78 in `Save-Json`:

`Move-Item -LiteralPath $temporaryPath -Destination $Path`

Line 78 was the atomic-save implementation for the one-shot incremental state.
The first `Save-State` moved the initial temporary file to
`execution-state.json`. The second `Save-State` wrote a new
`execution-state.json.tmp`, then attempted to move it onto the already-existing
destination. Windows PowerShell `Move-Item` did not replace that destination
and raised: `既に存在するファイルを作成することはできません。`

The outer catch updated the in-memory state to STOP and rewrote the same `.tmp`
file, but its own `Save-State` encountered the same destination collision. That
second persistence exception escaped before the helper could print its
sanitized STOP contract.

## Preserved local Evidence

Evidence directory:

`storage/app/release-audit/production-g5c-new-target-rehearsal-924af91188cc60d33ff87c91b94ecc1d539566e6/`

| File | File ID | SHA-256 | Generation |
|---|---|---|---|
| `execution-state.json` | `0x0000000000000000004200000008ad99` | `81a82f1fa237fc3012ae7a6fa60bd854b3100c42947df01f3f0d8b650b099b96` | first persisted state |
| `execution-state.json.tmp` | `0x0000000000000000001d00000008b0af` | `e3defa570f9f361812522715e15b702be37b11532bbb1ff63bd9215ec4fabd09` | later STOP state not promoted |

Both files were created at `2026-10-06T15:55:54.2946646Z`
(`2026-10-07T00:55:54.2946646+09:00`). The `.tmp` last-write time is
`2026-10-06T15:55:54.3360826Z`.

The destination generation records:

- `status=ATTEMPT_STARTED`
- `production_connection_attempted=false`
- `production_mutation_scope=none`
- `native_process_started=false`
- `remote_exit_code=null`
- `local_processing_substage=ATTEMPT_INITIALIZED`

The unpromoted `.tmp` generation records:

- `status=STOP`
- `native_process_started=false`
- `remote_exit_code=null`
- `ssh_authentication=unknown`
- `remote_shell=unknown`
- `remote_contract=unknown`
- `safe_error_code=UNEXPECTED_LOCAL_FAILURE`
- `local_processing_substage=REMOTE_PROCESS_START`

Its `production_connection_attempted=true` and
`production_mutation_scope=candidate_bound_isolated_rehearsal_possible` are
pre-start intent fields. The helper assigned them immediately before the
failing state save. The actual SSH invocation was the following source line and
was not reached.

No `capability-rehearsal.json` exists.

## Effective execution disposition

| Boundary | Evidence-based result |
|---|---|
| local native precondition processes | started; `ssh-keygen` checks and local `ssh -G` only |
| NewTarget SSH process | not started |
| NewTarget connection attempt | no |
| SSH authentication | not established |
| remote shell | not established |
| remote rehearsal contract | not started |
| remote script stdin | not sent |
| Production mutation | none |
| isolated rehearsal root created | no |
| remote cleanup | not required / not executed |
| residual created by this attempt | zero |
| `app.company-os.jp` mutation | none |
| target topology mutation | none |
| Legacy mutation | none |
| DB / Migration / DNS / SSL / Deploy | not attempted |

The effective connection result is derived from source ordering plus
`native_process_started=false`, null remote exit and absent stream evidence.
No new remote query was made for this analysis.

## Root Cause

**LOCAL_STATE_ATOMIC_REPLACE_CONTRACT_INCOMPATIBLE_WITH_WINDOWS**

The local state writer used a create/move operation for every generation. That
works only for the first generation. It did not implement atomic replacement
when the destination already existed. The connection-attempt flag was also set
before process start, so the unpromoted STOP state overstated the effective
connection boundary.

## Production-free source corrective

The corrective:

1. uses `System.IO.File.Move` only for the initial generation;
2. uses `System.IO.File.Replace` for later generations;
3. records a monotonic `local_state_generation`;
4. sets `production_connection_attempted=true` only after the remote native
   process actually starts;
5. prevents a secondary state-save failure from suppressing the sanitized STOP
   output;
6. adds `-VerifyPersistenceOnly`, which executes the real `Save-State` path for
   three local generations in an isolated OS temporary directory;
7. removes the fixture and verifies `.tmp` residual zero;
8. performs no SSH, HTTP, DB, Production filesystem, DNS, SSL, or Deploy action.

The existing destination and `.tmp` Evidence are not changed by the corrective
or its verification. The existing attempt directory still blocks another G5-C
execution.

## Automated verification

| Verification | Result |
|---|---:|
| corrective PowerShell AST parse | PASS |
| real helper `Save-State` generation 1 initial move | PASS |
| real helper `Save-State` generations 2-3 existing replace | PASS |
| final self-test state | `STOP`, generation 3 |
| self-test `.tmp` residual | 0 |
| self-test backup residual | 0 |
| Production / SSH / HTTP / DB connection | 0 |
| POSIX rehearsal execution | 0 |
| existing `execution-state.json` hash unchanged | PASS |
| existing `execution-state.json.tmp` hash unchanged | PASS |
| `capability-rehearsal.json` | absent |
| G5 focused regression | 14 tests / 222 assertions PASS |

The bounded corrective affects only local state persistence and its focused
test path. The repository-wide suite was not repeated; the immediately prior
result remains 761 passed, 17 skipped, and eight known unrelated failures.

## Continuing boundaries

- G5-C retry: not authorized
- cleanup-purpose Production operation: not authorized
- G5: OPEN
- `PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION=PENDING_G5_PUBLIC_ENTRY_GATE`
- Deploy / Migration / DNS / SSL: NO-GO

The next action after Production-free corrective verification is Human +
ChatGPT review. No G5-C execution command is issued by this corrective.
