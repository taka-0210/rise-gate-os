# Company OS｜IR-1 G5 Shared State Required Source Diagnostic Ready v001

Date: 2026-10-07 JST

Candidate: `924af91188cc60d33ff87c91b94ecc1d539566e6`

Gate: G5 Shared State

Status: **G5 OPEN / Production-free diagnostic design ready**

## 1. Confirmed boundary

Corrective-1 is an immutable Formal STOP. Its source inventory emitted the
sanitized remote status `REQUIRED_SOURCE_KEY_MISSING` after Source `.env`
read/parse and before storage inventory. NewTarget connection, Shared State
creation and Production mutation were all zero.

Bound Evidence:

- Initial STOP state SHA-256:
  `bb98d39709625d619d0365b57850613580237be837dcc85a863d00adfbcb248d`
- Corrective-1 STOP state SHA-256:
  `403fdcbcc4b9d9cdae9e9d1ec99b07be48ce502ef9601ec7accaa31ec3a08331`
- Corrective-1 source inventory: exit `1`, stdout `132` bytes,
  stderr `0` bytes, raw streams not stored
- Corrective-1 Source connection: yes
- Corrective-1 NewTarget connection: no
- Corrective-1 Production mutation: no
- Secret/raw output/transient residual: zero

Neither Shared State attempt is reusable. This design is a distinct read-only
diagnostic gate, not a retry.

## 2. Key-name and value safety

Key names already exist in the versioned application/configuration contract,
so the repository-only reconciliation map may name them. The execution safety
boundary is stricter:

- remote stdout: stable diagnostic IDs only;
- terminal: stable diagnostic IDs only;
- audit Evidence: stable diagnostic IDs only;
- Secret values: never output or stored;
- raw `.env`: never output or stored;
- raw `.env` hash: not output or stored;
- storage paths/manifest: not inspected;
- NewTarget: not connected.

The stable IDs are `rk01`–`rk12`. Each receives only one state:
`missing`, `empty`, `null_equivalent`, or `present`.

## 3. Production-free reconciliation matrix

This matrix classifies the **contract meaning**. It does not claim which ID
failed on Production; that remains UNKNOWN until the separate diagnostic runs.

| ID | Contract item | NewTarget requirement | Legacy/new assessment | Safe construction assessment |
|---|---|---|---|---|
| `rk01` | Application encryption key | Core security and encrypted-data continuity | Existing continuity value | Preserve exact Source or Human-supplied secret. A newly generated value is unsafe for continuity. |
| `rk02` | Database driver | Required after database topology decision | Existing candidate key | Candidate `sqlite` default is not New Production authority. Human must bind the intended driver. |
| `rk03` | Database host | Required for MySQL/MariaDB | Existing candidate key | `127.0.0.1` cannot be inferred safely across a server boundary. Human topology decision required. |
| `rk04` | Database port | Required for MySQL/MariaDB | Existing candidate key | `3306` is conditionally derivable only after driver/topology confirmation. |
| `rk05` | Database name | Required after database topology decision | Existing candidate key | Human/restore contract value; candidate default is unsafe. |
| `rk06` | Database user | Required after database topology decision | Existing candidate key | Human credential source; no safe automatic derivation. |
| `rk07` | Database password | Required when the selected database requires it | Existing candidate key | Human secret source; no safe default/generation in this gate. |
| `rk08` | Primary mailer | Production operational choice | Existing candidate key | `log` boots but does not deliver. Human must select a configured delivery mailer. |
| `rk09` | Mail sender address | Required when mail is enabled | Existing candidate key | Human-controlled sender identity; example default is unsafe. |
| `rk10` | Mail sender name | Required when mail is enabled | Existing candidate key | `Company OS` is a safe candidate derivation only after Human confirmation. |
| `rk11` | Account mailer | Required for invitation/account delivery | Candidate capability key; Source presence unknown | Human must select a configured delivery mailer; it may equal the primary mailer only by explicit decision. |
| `rk12` | AI provider credential | Feature-required, not core boot-required | Candidate capability key; Source presence unknown | Human secret if AI is enabled. If intentionally disabled, requiredness needs a separate contract decision; no automatic relaxation. |

Conclusions:

1. The 172-key allowlist is a valid frozen-candidate union and remains exact.
2. The existing 12-item required list remains unchanged for diagnosis.
3. “Required on Source” and “required for NewTarget runtime” are not uniform:
   core continuity, target-specific topology, production operations and
   optional capability must be reconciled separately.
4. Missing/empty/null does not itself authorize a default, generated value,
   derivation or required-list relaxation.
5. Present does not prove a Source value is correct for NewTarget.

## 4. Diagnostic contract

Files:

- `deployment/g5-target-environment/shared-state-required-diagnostic-contract.json`
- `deployment/g5-target-environment/diagnose-required-source.php`
- `deployment/g5-target-environment/Invoke-G5SharedStateRequiredDiagnostic.ps1`

The helper verifies before native process start:

- frozen candidate;
- initial and Corrective-1 STOP state hashes and sanitized dispositions;
- base/Corrective/diagnostic contract hashes;
- unchanged 172-key allowlist hash;
- Legacy Source SSH identity and ED25519 Host Key;
- stable ID set and secret/key-name exclusion contract;
- absence of prior diagnostic Evidence.

The one remote process runs PHP from stdin against the exact Source `.env`.
It verifies exact Source HOME/UID/GID and path, parses values in remote process
memory, and outputs only stable ID states and counts. It performs no write,
storage inventory, database access or NewTarget connection.

The helper saves exit code, stdout/stderr SHA-256 and byte counts before safe
parsing. Raw streams remain unstored. PASS/STOP creates a one-shot Evidence
generation and permanently disables replay.

## 5. Reconciliation after diagnostic

For each non-present stable ID, Human + ChatGPT will use the repository-only
map to classify one of:

- preserve exact existing continuity value;
- Human must supply/confirm a NewTarget value;
- conditional default is safe only after a named topology decision;
- derived value is safe only after Human acceptance;
- capability is intentionally disabled and requiredness needs a separate
  Product/operations contract decision.

No classification changes the base required contract automatically.

## 6. Production-free verification and gate state

- PHP syntax: PASS
- Fixture diagnostic for missing/empty/null/present: PASS
- Secret/key-name/raw-env non-disclosure: PASS
- Remote program read-only static boundary: PASS
- Human helper VerifyOnly path: PASS, Production connection 0
- Diagnostic focused tests: **5 tests / 109 assertions PASS**
- G5 focused regression: **36 tests / 623 assertions PASS**
- Full Laravel suite: **784 passed / 17 skipped / 8 failed**. The failures are
  outside this diagnostic: one existing Company Navigation stale intended-URL
  assertion and seven G2 tests whose setup requires an already-executed
  Corrective-2 Evidence directory to be absent. G5 and all new diagnostic
  tests PASS in the same full run.
- Production SSH / HTTP / DB connection during preparation and verification:
  **0**
- Production mutation: **0**

The Production-free checks are complete. The only eligible next action is a
separate **ONE G5 REQUIRED SOURCE READ-ONLY DIAGNOSTIC** Human Gate.

## 7. Continuing boundaries

- G5: **OPEN**
- Existing Shared State retry: **not available**
- Shared State creation: **not authorized**
- NewTarget change/connection by this diagnostic: **not authorized**
- Application placement / Migration / Deploy: **not authorized**
- public entry / DNS / SSL change: **not authorized**
- `PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION`:
  `PENDING_G5_PUBLIC_ENTRY_GATE`
- usable backup: `unknown`
- DB restore readiness: `blocker`
- storage final delta: still required
