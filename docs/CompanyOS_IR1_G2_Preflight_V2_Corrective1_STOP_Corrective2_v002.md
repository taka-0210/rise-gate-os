# IR-1 G2 Preflight v2 Corrective-1 STOP / Corrective-2

## Decision

- G2: OPEN
- v2 Artifact: FORMAL PASS maintained
- v2 Placement: FORMAL PASS maintained
- Corrective-1 execution: STOP / immutable Evidence retained
- Production Deploy / Migration: NO-GO

Recommended next decision:

`ONE SEPARATELY AUTHORIZED G2 PREFLIGHT V2 CORRECTIVE-2 READ-ONLY EXECUTION REQUIRED`

No Production execution is authorized by this document.

## Corrective-1 Evidence evaluation

- native process start: ESTABLISHED
- SSH authentication: ESTABLISHED
- remote shell: ESTABLISHED
- remote exit code: 1
- stdout bytes: 1572
- stdout SHA-256: `40d06af80553d6fac20a934f527814b0a6d9df63c2654e087e1fe14661c2dc80`
- stderr bytes: 0
- stderr SHA-256: not applicable
- frame count: 6
- completed database checks: 0
- database connection: not attempted
- SQL statements: 0
- rejected SQL statements: 0
- remote file mutation: 0
- persistent DB write: 0
- DDL: 0
- Migration: 0
- data mutation: 0

Frame progression:

1. shell / shell_started / PASS
2. shell / artifact_verified / PASS
3. shell / php_discovered / PASS
4. php / contract_started / PASS
5. php / terminal / STOP
6. shell / terminal / STOP

The last successful frame was `php / contract_started / PASS`. `environment_loaded` was not reached.

`local_processing_substage=REMOTE_PROCESS_START` is the local invocation label retained after the native process returned. It is not the remote failure reason. Remote incremental Evidence establishes that the process, SSH authentication, remote shell, artifact verification and PHP interpreter start all completed.

## Root Cause

Exact observed runtime boundary:

`PHP contract started -> exact bundle autoload/environment bootstrap -> STOP before environment_loaded`

Architecture Root Cause:

`UNBOUND_PHP_RUNTIME_SELECTION`

The already-passed R0 Application Audit, R0 Host Audit and DB-free Runtime Diagnostic select PHP in this order:

`php8.3 -> php8.2 -> php`

Those paths established PHP 8.3.33 with the required extensions. The v2 Production launcher instead selected the generic `php` alias. The isolated v2 E2E did not exercise that Production default because it supplied an explicit PHP binary override.

The exact bundle and Production environment had already completed the same autoload and environment load under the R0 PHP 8.3 selection. Therefore the remaining changed boundary is the v2 generic interpreter selection.

The lower-level runtime failure subtype, such as incompatible version or extension set, is intentionally unobserved because Corrective-1 suppressed stderr and normalized the Throwable. It is not inferred beyond the proven runtime-selection boundary.

## Corrective-2

- retain the exact placed Artifact without modification
- retain Placement FORMAL PASS without replacement
- use the launcher's existing `G2_V2_PHP_BIN` override contract
- bind the remote process environment to `php8.3`
- close Corrective-1 generation permanently
- use an isolated Corrective-2 local Evidence root
- bind the exact Corrective-1 execution state and incremental frames by SHA-256
- preserve SSH maximum 1, retry 0 and remote file mutation 0
- preserve SELECT-only / SQL maximum 24 / DDL 0 / Migration 0 / data mutation 0

## Exact identities

- v2 Artifact SHA-256: `b055585e09d7ae00c65bcaacad213fc4c92d260d4f8e2125884fe72d3cfb6b99`
- Placement state SHA-256: `6b9af42c593ad52697cf3223cf3f644aec22f77321f7a1416c2890f9fc90a3b4`
- Corrective-1 state SHA-256: `d1e5a8cc0019790542ec9efc1638117a450b506971c2f144b6737bb7a212cb2e`
- Corrective-1 frames SHA-256: `40d06af80553d6fac20a934f527814b0a6d9df63c2654e087e1fe14661c2dc80`
- Corrective-1 sanitized binding SHA-256: `e544962e18b9e8b9d6d3026a944d3ce5ab6a340e674d670fe91db13725e44c4b`
- Corrective-2 Helper SHA-256: `2ee5d4ab6838aef7ed3cf55b5897baf98eaf73223827a87cc994a0964df14829`

## Production-free verification

- PowerShell parse: PASS
- Corrective-2 VerifyOnly: PASS
- state factory evaluation: PASS
- initial STOP directory retained empty: PASS
- Corrective-1 state and frames retained exact: PASS
- Corrective-2 attempt directory absent: PASS
- G2 regression: 22 tests / 323 assertions PASS
- Production connection during analysis/corrective/verification: 0
- Production mutation during analysis/corrective/verification: 0

## Remaining boundary

Corrective-2 is prepared but not authorized for Production execution. A separate Human + ChatGPT Gate is required. PASS would still require integration with the isolated Migration Evidence before G2 close; STOP remains terminal with no retry.
