# Company OS - IR-1 G5 Discovery Corrective-1 STOP / Corrective-2

- Date: 2026-10-03 JST
- Gate: G5 Target Environment Build
- Frozen RC: 924af91188cc60d33ff87c91b94ecc1d539566e6
- Attempt: ONE G5 CORRECTIVE-1 READ-ONLY TARGET DISCOVERY
- Result: STOP after the connection-attempt boundary

## Corrective-1 saved evidence evaluation

| Requested evidence | Established result |
|---|---|
| native process Start | UNKNOWN |
| SSH authentication | UNKNOWN |
| remote shell | UNKNOWN |
| remote exit code | UNKNOWN |
| stdout bytes / SHA-256 | UNKNOWN |
| stderr bytes / SHA-256 | UNKNOWN |
| local processing substage | after local preparation and before PASS receipt; exact substage UNKNOWN |
| remote discovery contract start | UNKNOWN |
| last established check | package/source binding, SSH config, known-host registration and remote script preparation completed |
| Production receipt | absent |
| Production mutation | 0 |
| Legacy os.rise-gate.com mutation | 0 |
| DB connection / SQL | 0 by exact discovery contract |

The empty local Evidence directory is not evidence that the native process did
or did not start. The Corrective-1 helper stored a receipt only after a complete
PASS and therefore discarded remote exit and stream metadata on every earlier
exception. The exact execution failure must remain UNKNOWN.

## Safety basis for zero mutation

The exact candidate-bound discovery script contains path-type, count, command,
PHP, filesystem-device and disk reads only. It contains no mkdir, removal,
rename, symlink, permission change, environment-value read, DB connection, SQL,
Deploy, or Migration operation. The legacy path is observed only by read-only
type checks. Therefore executing any portion of this contract cannot mutate the
target or legacy Production topology.

## Corrective-2 architecture

- Replaced the PowerShell native pipeline and temporary redirection boundary
  with System.Diagnostics.Process.
- Streams standard input as explicit UTF-8 bytes without BOM.
- Captures exit code, stdout, and stderr independently.
- Creates a one-shot Corrective-2 state before setting the Production
  connection-attempt boundary.
- Persists sanitized substages: REMOTE_PROCESS_START,
  NATIVE_PROCESS_STARTED, STANDARD_INPUT_CLOSED, REMOTE_RESULT_CAPTURED,
  REMOTE_STDOUT_VALIDATION, SANITIZED_RECEIPT_WRITE, and COMPLETE.
- Persists output byte counts and SHA-256 only; raw streams are not stored.
- Persists sanitized exception type and exception-message SHA-256 only.
- Retains safe remote STOP codes without storing raw output.
- Separates local precheck native processes from the remote SSH process.
- Forbids retry when a Corrective-2 state already exists.

## Production-free automated verification

| Verification | Result |
|---|---|
| PowerShell 5.1 AST parse | PASS |
| Real .NET native process start | PASS |
| UTF-8 stdin byte-stream | PASS |
| stdout / stderr separation | PASS |
| exit code capture | PASS |
| incremental state JSON round trip | PASS |
| temporary state cleanup | PASS |
| Human VerifyOnly execution path | validated_through_native_capture |
| G4 + G5 focused regression | 12 tests / 153 assertions PASS |
| Production connection | 0 |
| Production mutation | 0 |

## Identity preservation

- G5 Package SHA-256:
  f89a71cbfe453b2e9bd74d20fdf5e3bab9c2b98e28775ddf755922713ca8a232
- G5 Manifest SHA-256:
  b54040df69fb1c4658ff73f4920f482087d56e9d7fc223208c098fa2dbe5f1e1
- The immutable discovery script and target contract are unchanged.

## Decision

**G5 DISCOVERY CORRECTIVE-2 READY / SEPARATE AUTHORIZATION REQUIRED**

No retry or new Production connection was performed. POSIX capability
rehearsal, target mutation, Deploy, Migration, DNS, and SSL remain
unauthorized.
