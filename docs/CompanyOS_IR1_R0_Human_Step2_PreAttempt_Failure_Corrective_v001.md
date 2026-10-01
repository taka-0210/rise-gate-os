# Company OS | IR-1 R0 Human Step 2 Pre-attempt Failure Corrective

Date: 2026-10-02 JST

## Disposition

- R0 Human Step 2: `STOP / PRE_ATTEMPT_LOCAL_FAILURE`
- Production SSH connection: `0`
- Remote command: `0`
- Production audit directory creation: `0`
- Production DB connection: `0`
- R0 Audit: `HOLD`
- Production Deploy: `NO-GO`

## Evidence

The Human-visible result was `UNEXPECTED_LOCAL_FAILURE`. The deterministic local execution root and `execution-state.json` were both absent after the attempt. In the helper order, the attempt marker is persisted before any Production SSH invocation. Therefore this attempt could not have reached SSH, a remote command, or remote directory creation.

The original exception detail is `UNKNOWN`: the first helper version did not retain a sanitized pre-attempt failure stage or exception receipt. No raw exception is inferred or reconstructed.

## Corrective

- Added explicit local and remote `failure_stage` markers.
- Added `production_connection_attempted` to STOP output.
- Added a sanitized local failure receipt containing only an allowlisted exception type and SHA-256 of the exception message.
- Raw exception text, account home path, credentials and secrets are not stored.
- Added a new `corrective-1` execution generation so a separately approved corrective attempt cannot be mistaken for an automatic retry of the stopped attempt.
- Preserved one Human command per Step, strict host-key checking, batch-only authentication, one connection attempt and state-machine retry prevention.

## Provider-free Verification

- PowerShell AST parse: PASS
- `VerifyOnly`: PASS
- Retry guard synthetic test: PASS
- Step sequence guard synthetic test: PASS
- Secret-output guard synthetic test: PASS
- Step 2 write-scope guard synthetic test: PASS
- Sanitized failure receipt synthetic test: PASS
- Raw secret/home persistence: 0
- Production connection during corrective verification: 0

## Recommended Decision

`READY FOR ONE SEPARATELY AUTHORIZED CORRECTIVE STEP 2 ATTEMPT`

No additional Human command is issued by this report. R0 Audit and Production Deploy remain on HOLD.
