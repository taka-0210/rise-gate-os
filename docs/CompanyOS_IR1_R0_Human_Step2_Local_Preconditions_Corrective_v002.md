# Company OS | IR-1 R0 Human Step 2 Local Preconditions Corrective v002

Date: 2026-10-02 JST

## Disposition

- Corrective Step 2 attempt: `STOP / LOCAL_PRECONDITIONS`
- Production connection attempted: `false`
- SSH authentication: `0`
- Remote command: `0`
- Audit directory creation: `0`
- Production DB connection: `0`
- R0 Audit: `HOLD`
- Production Deploy: `NO-GO`

## Preserved Evidence

The sanitized receipt records:

- `safe_error_code=UNEXPECTED_LOCAL_FAILURE`
- `failure_stage=LOCAL_PRECONDITIONS`
- `exception_type=System.Management.Automation.RemoteException`
- `production_connection_attempted=false`
- `raw_exception_stored=false`

The `corrective-1` execution root and `execution-state.json` are absent. The helper sets the production-connection marker only after local preconditions and attempt-state persistence. Therefore the stopped attempt did not reach SSH, a remote command, or Production mutation.

## Root Cause

`Invoke-CapturedProcess` inherited `ErrorActionPreference=Stop`. In Windows PowerShell 5.1, stderr from a native child process can be promoted to a terminating `RemoteException` before the helper receives the child exit code and normalizes the captured stderr. This bypassed the intended local precondition classification.

The historical raw stderr was intentionally not persisted. Therefore the exact emitting command among the two local-only OpenSSH inspections, `ssh -G` and `ssh-keygen -F`, remains `UNKNOWN` and is not inferred. Both inspections pass in the current local-only verification.

## Corrective

- Native stdout and stderr capture now temporarily uses `ErrorActionPreference=Continue` inside the process boundary and restores the caller setting in `finally`.
- Child exit code remains authoritative; raw stderr remains non-displayable and non-persistent except for the existing SHA-256 evidence field on a stopped attempt.
- Local preconditions now expose granular sanitized stages for bundle integrity, OpenSSH discovery, SSH config, known_hosts lookup, and host-key contract.
- A local-only `VerifyLocalPreconditionsOnly` mode checks the entire local boundary without creating attempt state or opening a Production connection.
- Unexpected local `RemoteException` is normalized to `LOCAL_NATIVE_PROCESS_CAPTURE_FAILURE` rather than a generic raw failure.
- A native-stderr synthetic regression proves stderr cannot escape the capture boundary.
- The separately reviewable execution generation is `corrective-2`; no Human command is authorized or issued by this corrective.

## Automated Verification

- PowerShell AST parse: `PASS`
- Helper self-test: `PASS`
- Native stderr capture regression: `PASS`
- Local preconditions: `PASS`
- Bundle identity and SHA-256: `PASS`
- SSH config and identity contract: `PASS`
- known_hosts three-key-type contract: `PASS`
- Production connection during verification: `0`
- Production change during verification: `0`
- Focused helper test: `1 passed / 36 assertions`
- R0 regression: `8 passed / 106 assertions`
- Full Laravel regression: `678 passed / 5589 assertions`, `17 skipped`, `1 unrelated existing failure`

The unrelated failure is `CompanyNavigationTest::regular login ignores a stale forbidden intended url`. It reproduces in the isolated test class and no application/login/navigation file is part of this corrective delta.

## Exact Helper Identity

- SHA-256: `397aed77ee0e40c3c8d946ca7fb275128d8258ee071f6088b540ebb4279e9137`
- Candidate binding: `924af91188cc60d33ff87c91b94ecc1d539566e6`
- Execution generation: `corrective-2`

## Recommended Decision

`READY FOR ONE SEPARATELY AUTHORIZED CORRECTIVE-2 STEP 2 ATTEMPT`

R0 Audit and Production Deploy remain on HOLD. A new Human command must not be issued until Human + ChatGPT approve that single attempt.
