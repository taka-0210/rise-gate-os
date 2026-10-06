# Company OS - IR-1 G5-C Corrective1 POSIX Capability Rehearsal Ready v001

- Date: 2026-10-07 JST
- Gate: G5-C POSIX CAPABILITY REHEARSAL
- Frozen RC: `924af91188cc60d33ff87c91b94ecc1d539566e6`
- Preparation base HEAD: `b12ca1297da2b4cbc96ed468eb0ff2a49c1ca6a0`
- Helper generation: `g5c-new-target-posix-rehearsal-corrective-1`
- Prepared helper SHA-256: `b6df168e75510db30210bf61452dcf89d3b1526972bdcad9ff09598f26ca2edd`
- Disposition: **ONE HUMAN-APPROVED CORRECTIVE RETRY READY / NOT EXECUTED**
- G5 disposition: **OPEN**

## Human authority

Human + ChatGPT approved one, and only one, Corrective1 execution after
accepting the initial local STOP root cause
`LOCAL_STATE_ATOMIC_REPLACE_CONTRACT_INCOMPATIBLE_WITH_WINDOWS`.

The authorization is limited to the already-defined candidate-bound isolated
NewTarget rehearsal root. It does not authorize public-entry or target-topology
changes, Legacy changes, `.env`, shared storage, DB, Migration, DNS, SSL, or
Deploy operations. PASS or STOP exhausts the authorization and returns to
Human + ChatGPT review.

## Initial attempt immutable binding

The Corrective1 helper treats the first attempt as immutable input Evidence.
It requires exactly two entries and refuses SSH start if the shape, sanitized
state contract, or either hash differs.

| Initial Evidence | Required SHA-256 | Ready verification |
|---|---|---:|
| `execution-state.json` | `81a82f1fa237fc3012ae7a6fa60bd854b3100c42947df01f3f0d8b650b099b96` | PASS |
| `execution-state.json.tmp` | `e3defa570f9f361812522715e15b702be37b11532bbb1ff63bd9215ec4fabd09` | PASS |

Ready verification also confirmed:

- initial Evidence entry count: 2;
- initial `capability-rehearsal.json`: absent;
- NewTarget SSH process in initial attempt: not started;
- initial Production connection attempt: 0 effective;
- initial Production mutation: 0;
- initial Evidence writes by preparation: 0.

## Separate one-shot generation

Corrective1 writes only to:

`storage/app/release-audit/production-g5c-new-target-rehearsal-corrective-1-924af91188cc60d33ff87c91b94ecc1d539566e6/`

The root was absent at ready verification. The helper fails closed with
`G5C_ATTEMPT_ALREADY_RECORDED` if it exists. It never uses the initial Evidence
directory as the Corrective1 destination.

The new state and receipt explicitly record:

- `attempt=Corrective1`;
- `execution_kind=human_approved_corrective_retry`;
- `corrective_retry_number=1`;
- `blind_retry=false`;
- both initial Evidence hashes;
- `initial_attempt_evidence_immutable=true`;
- `retry_performed=true` for this approved corrective execution;
- `retry_available=false` after PASS or STOP.

## Production-free verification

| Verification | Result |
|---|---:|
| PowerShell AST parse | PASS |
| Corrective1 `-VerifyOnly` binding | PASS |
| initial Evidence hash and state contract | PASS |
| SSH / Production connection during preparation | 0 |
| Corrective Evidence root generated during preparation | no |
| state generation 1 initial `System.IO.File.Move` | PASS |
| state generations 2-3 `System.IO.File.Replace` | PASS |
| state `.tmp` residual | 0 |
| state `.previous` residual | 0 |
| remote rehearsal shell syntax | PASS |
| G5 focused regression | 15 tests / 246 assertions PASS |

Repository-wide verification completed with 763 passed, 17 skipped, and eight
pre-existing unrelated failures (one CompanyNavigation intended-URL failure and
seven G2 execution-Evidence presence failures). G5-C tests passed in both the
focused and repository-wide runs.

## Continuing boundaries

- Actual Corrective1 execution: not yet performed
- After execution: no retry, return to Human + ChatGPT
- `PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION=PENDING_G5_PUBLIC_ENTRY_GATE`
- G5 remains OPEN regardless of this readiness result
- G5-C PASS is not Deploy authorization
- public entry / topology / Legacy / `.env` / shared storage: NO-GO
- DB / Migration / DNS / SSL / Deploy: NO-GO
