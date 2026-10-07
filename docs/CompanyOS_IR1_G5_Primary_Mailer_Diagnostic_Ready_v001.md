# Company OS IR1｜G5 Primary Production Mailer Diagnostic Ready v001

## Status

**PRODUCTION-FREE CONTRACT READY / ONE READ-ONLY HUMAN GATE NOT YET EXECUTED**

- G5: `OPEN`
- Deploy: `NO-GO`
- Production connection during preparation/verification: `0`
- Production mutation during preparation/verification: `0`
- Shared State initial attempt / Corrective-1 replay: `0`
- `PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION`:
  `PENDING_G5_PUBLIC_ENTRY_GATE`

## Binding Human Decision

`G5-SS-PD-RK11-PRIMARY-SAME`

- NewTarget `ACCOUNT_MAIL_MAILER` uses the exact same Production delivery
  Mailer as the Primary Production Mailer.
- No Account Mail-specific Mailer is introduced.
- Human does not inspect or type the Mailer name, credentials or `.env` value.
- A later Shared State corrective must derive the already validated Primary
  Mailer value in memory and assign the exact same value to
  `ACCOUNT_MAIL_MAILER`.

This preparation does not modify the 172-key allowlist or the existing
required contract. It does not create NewTarget Shared State.

## Immutable prerequisite Evidence

The completed Required Source Diagnostic established that `rk11` alone is
missing while the other 11 required stable IDs are present.

| Artifact | SHA-256 |
|---|---|
| Required diagnostic execution state | `570d8d67eb5a035b5c92ede17c5f42fd6fafa65e6de49d73385201bedaacae47` |
| Required diagnostic receipt | `9060f96a9e001499a32949b475d83b4d33f882c31f414a6578029ad0b7507bc7` |
| Required diagnostic contract | `b124b9f9688fdda86a089e80525bb608b955b7a30a2589c65d3c3303fa1a8f78` |

Repository-only mapping identifies `rk11` as `ACCOUNT_MAIL_MAILER`. Remote
Evidence continues to use stable IDs and does not expose environment key
names or values.

## Read-only diagnostic contract

The one Human Gate runs at most once and may perform:

1. One read-only SSH process against the exact Legacy Source.
2. Zero or one conditional read-only SSH process against the exact NewTarget,
   only if the Source result requires local sendmail or PHP process capability.
3. Local sanitized Evidence persistence under the exact candidate-bound audit
   directory.

It cannot perform Shared State creation, file transfer, Application placement,
DB connection, Migration, public-entry change, DNS/SSL change or Deploy.

### Secret and identity boundary

Remote stdout and local Evidence may contain only stable Mailer, Queue and
capability IDs plus readiness states. They must not contain:

- actual Primary Mailer name;
- environment key names or values;
- endpoint, username, password, token or provider credential;
- From email address/name values;
- raw `.env` or its hash;
- raw SSH stdout/stderr.

The helper stores only stdout/stderr SHA-256 and byte counts before parsing
the allowlisted sanitized status.

## Validation matrix

| Concern | Diagnostic decision |
|---|---|
| Delivery transport | `smtp`, `ses`, `postmark`, `resend`, exact default `sendmail`, or candidate-safe `roundrobin`; reject unsupported transports |
| Non-delivery transport | Reject `log` and Production `array` |
| Candidate `failover` | Reject because the frozen candidate includes `log` fallback |
| Transport inputs | Require endpoint/credential presence appropriate to the selected stable transport ID |
| From identity | Require valid non-placeholder address and non-placeholder name; values remain hidden |
| Account binding | Record `primary_exact` / `derived_in_memory`; no manual value |
| Queue configuration | Classify stable Queue ID and validate its configuration/credential presence |
| Queue worker | Classify `required`, `not_required` or `conditional` |
| Target capability | Conditionally check exact default sendmail executable and/or PHP `proc_open` only |

Presence validation does not contact the mail provider, send a message, open a
database connection or contact an external Queue service.

## Deferred operational dependencies

The following remain open even if this diagnostic passes:

- actual Queue worker process readiness;
- database jobs-table readiness where applicable;
- external Queue reachability where applicable;
- provider reachability and end-to-end delivery;
- Application release/config binding on NewTarget;
- usable backup and DB restore readiness;
- storage final delta;
- public-entry disposition;
- DNS/SSL and Deploy.

These cannot be closed before the relevant Application Release / DB / mail
delivery gates.

## Evidence Contract

A PASS or STOP creates a separate local Evidence generation:

```text
storage/app/release-audit/
  production-g5-primary-mailer-diagnostic-924af91188cc60d33ff87c91b94ecc1d539566e6/
    execution-state.json
    primary-mailer-diagnostic.json   # PASS only
```

The state is written before Production connection. Native-process start is the
boundary at which the relevant Source/Target connection flag becomes true.
Stream hash/count metadata is persisted before safe parsing. PASS or STOP
exhausts the gate and `retry_available=false` remains binding.

## Human Gate

This command is **prepared but not executed**. A separate explicit Human
approval is required. If approved, run exactly once:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File "C:\xampp\htdocs\rise-gate-os\deployment\g5-target-environment\Invoke-G5PrimaryMailerDiagnostic.ps1"
```

After PASS or STOP, do not rerun. Return the terminal's sanitized contract to
Human + ChatGPT. A PASS is not Shared State or Deploy authorization.

## Production-free verification

- Source diagnostic PHP syntax: PASS
- Target capability shell syntax: PASS
- Human helper `-VerifyOnly`: PASS
  - Production connection: `0`
  - Source connection: `0`
  - Target connection: `0`
  - Production mutation: `0`
  - Evidence generation: `0`
- Primary Mailer focused regression: 7 tests / 110 assertions PASS
- G5 target-environment regression: 43 tests / 737 assertions PASS
- Repository-wide regression baseline: 790 PASS / 17 skipped / 8 pre-existing
  unrelated FAIL
  - one Company Navigation intended-URL expectation failure;
  - seven G2 corrective execution tests expecting an already-recorded Human
    Evidence directory to be absent.

The unrelated failures do not touch the files or behavior in this contract
and do not authorize changing or deleting their existing state/Evidence.

## Continuing boundaries

- Shared State attempt / Corrective-1: retired; no replay
- Shared State creation: unauthorized
- Application placement: unauthorized
- Migration / DB operation: unauthorized
- Public entry change: unauthorized
- DNS / SSL change: unauthorized
- Deploy: unauthorized
- G5: `OPEN`
