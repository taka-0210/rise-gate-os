# IR-1 G2 Preflight v2 Execution Initial STOP / Corrective-1

## Decision

- G2 status: OPEN
- v2 Artifact: FORMAL PASS維持
- v2 Placement: FORMAL PASS維持
- Execution Gate: OPEN
- Production Deploy / Migration: NO-GO

## Initial STOP reconstruction

Human receipt:

- `safe_error_code=UNEXPECTED_LOCAL_FAILURE`
- reported `failure_stage=LOCAL_OPENSSH_CONTRACT`
- `production_connection_attempted=false`
- `database_connection=not_attempted`
- SQL counts: `unknown`

Local Evidence:

- initial execution directory is present and empty
- directory entry count is 0
- execution state / remote Evidence is absent
- exact initial Helper SHA-256 is `ff8f25efd6705d0e640c980074ca415f2146499aa88f120c99b05e5130d13d28`
- local-only OpenSSH contract reproduction: PASS

## Root Cause

`Get-LocalPreconditions` completed, including the OpenSSH config, identity, automatic-command and known-host checks. The Helper then created the local execution Evidence directory. State construction failed before `execution-state.json` could be written because Boolean values in the PowerShell state object used the bareword `false` instead of `$false`.

Therefore the reported `LOCAL_OPENSSH_CONTRACT` was stale stage metadata, not the actual failure location. The exact actual substage is:

`LOCAL_ATTEMPT_STATE_INITIALIZATION / STATE_OBJECT_INITIALIZATION`

Placement succeeded because its state object used valid PowerShell Boolean values and its OpenSSH process wrapper had already completed the same local SSH config and known-host contract. Execution added a new state initializer after that shared boundary; the prior VerifyOnly path returned before evaluating it.

## Safety conclusion

- SSH authentication / remote shell: not attempted
- PHP contract: not started
- DB connection: not attempted
- SQL: 0 established; count remains unknown only because no runtime Evidence contract started
- remote file mutation: 0
- persistent DB write: 0
- DDL: 0
- Migration: 0
- data mutation: 0

The empty initial directory is preserved without deletion or modification as the initial STOP marker.

The first local reconstruction artifact is also preserved byte-for-byte under its original hash. A separate deterministic key/value Evidence artifact is the canonical sanitized corrective binding; the Helper verifies both hashes and does not rewrite either file.

## Corrective-1

- original execution generation is permanently closed
- initial STOP is bound by a sanitized exact-hash Evidence file
- next execution generation uses a separate `corrective-1` Evidence root
- state construction is centralized in `New-ExecutionState`
- VerifyOnly evaluates the same state constructor used by an actual attempt
- all Boolean values are native PowerShell `$false`
- local failure stage and sanitized substage are recorded separately
- unexpected local exceptions store only type and SHA-256 of the message
- Production connection, retry and overwrite remain prohibited during corrective verification

## Production-free verification scope

- PowerShell parse
- exact initial STOP Evidence hash
- local OpenSSH contract only (`ssh -G` / `ssh-keygen -F`)
- corrective VerifyOnly
- PASS / STOP Evidence fixtures
- focused PHPUnit regression
- original initial directory remains empty
- corrective execution directory remains absent

No Production connection or mutation is authorized by this document.

## Verification result

- corrected Helper SHA-256: `f08c703290defbfdc74a7e4f21cd7b6877704e795a639f6254d226c12bf19d8d`
- PowerShell parse: PASS
- local OpenSSH contract reproduction: PASS
- Corrective-1 VerifyOnly: PASS
- focused G2 regression: 21 tests / 308 assertions PASS
- full Laravel regression: 702 passed / 17 skipped / 1 unrelated existing failure
- unrelated failure: `CompanyNavigationTest` stale intended URL expectation
- Production connection during analysis/corrective/verification: 0
- Production mutation during analysis/corrective/verification: 0
