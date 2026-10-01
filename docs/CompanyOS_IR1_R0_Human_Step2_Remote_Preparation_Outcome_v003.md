# Company OS | IR-1 R0 Human Step 2 Remote Preparation Outcome v003

Date: 2026-10-02 JST

## Disposition

- Corrective-2 Step 2: `STOP`
- Safe error: `STEP_2_REMOTE_PREPARATION_FAILED`
- Failure stage: `STEP_2_REMOTE_PREPARATION`
- Production connection attempted: `true`
- Retry performed: `false`
- R0 Audit: `HOLD`
- Production Deploy: `NO-GO`

## Sanitized Evidence

The `corrective-2` execution state records exactly one Step 2 attempt:

- helper SHA-256: `397aed77ee0e40c3c8d946ca7fb275128d8258ee071f6088b540ebb4279e9137`
- status: `STOP`
- remote exit code: `1`
- stderr bytes: `0`
- stderr SHA-256: `null`

The failure receipt independently records the same safe error and stage, `System.InvalidOperationException`, `production_connection_attempted=true`, and `raw_exception_stored=false`.

## Determination From Existing Evidence

| Question | Determination | Basis |
|---|---|---|
| SSH authentication | `ESTABLISHED` | OpenSSH authentication or transport failure uses the SSH failure path rather than a silent remote exit `1`; the command returned the remote shell exit status with zero stderr. |
| `$HOME` identity check | `PASS` | The script uses explicit exit `41` for an invalid canonical home. Recorded exit is `1`. |
| `.ir1-r0-audit` created by this attempt | `NO` | Exit `1` with zero stderr can occur at one of the three silent pre-mutation tests. Both `mkdir` operations are after those tests; a failed `mkdir` would emit stderr. |
| Candidate directory created by this attempt | `NO` | Same ordering evidence; candidate creation is after audit-root creation. |
| Current audit-root existence | `UNKNOWN` | One possible silent failure is the guard requiring the audit root to be absent. |
| Current candidate-directory existence | `UNKNOWN` | Existing Evidence contains no read-only state snapshot of the remote paths. |
| Partial state attributable to this attempt | `NONE ESTABLISHED` | The attempt stopped before its first mutation command. Pre-existing partial state remains possible and is not attributed to this attempt. |

The remaining cause set is bounded to:

1. legacy application root missing;
2. legacy public root missing; or
3. audit root already present before this attempt.

Existing sanitized Evidence cannot distinguish these three cases without guessing.

## Provider-free Corrective

A separate `InspectStep2RemoteStateOnly` mode has been added. It is not a Step 2 retry and does not create, delete, upload, chmod, chown, move, link, or edit any Production object.

The inspection:

- requires the exact stopped `corrective-2` state;
- writes a local one-attempt marker before connection;
- forbids inspection retry after PASS or STOP;
- uses the existing strict host-key, batch authentication, one-connection-attempt contract;
- returns only allowlisted status values;
- never outputs the home path, account identifier, credential, environment value, or raw stderr;
- classifies the legacy application root, legacy public root, audit root, and candidate directory;
- records a sanitized local JSON result bound to the exact helper hash;
- has `production_change_scope=none_read_only_state_inspection`.

## Automated Verification

- PowerShell AST parse: `PASS`
- Helper self-test: `PASS`
- Remote mutation-command guard: `PASS`
- Retry guard: `PASS`
- Secret-output guard: `PASS`
- Native stderr capture guard: `PASS`
- R0 regression: `8 passed / 111 assertions`
- Production connection during corrective verification: `0`
- Production mutation during corrective verification: `0`

## Exact Inspection Helper Identity

- helper SHA-256: `4b4f696ed97dbabaac24d189981dc64f0ca0d8a174cf436884c00ecc0a932bb4`
- candidate: `924af91188cc60d33ff87c91b94ecc1d539566e6`
- execution generation: `corrective-2`
- inspection ID: `step2-state-inspection-1`

## Recommended Decision

`READY FOR ONE SEPARATELY AUTHORIZED READ-ONLY STEP 2 STATE INSPECTION`

This inspection is indispensable only to resolve the remaining pre-existing remote-state Unknowns. It is not R0 Audit, Step 2 retry, cleanup, upload, Deploy, Migration, or Production correction. No Human command is issued until that single read-only inspection is explicitly approved.
