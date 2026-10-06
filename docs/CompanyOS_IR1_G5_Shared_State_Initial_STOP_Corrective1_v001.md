# Company OS｜IR-1 G5 Shared State Initial STOP / Corrective-1 v001

Date: 2026-10-07 JST
Candidate: `924af91188cc60d33ff87c91b94ecc1d539566e6`
Gate: G5 Shared State
Status: **G5 OPEN / Corrective-1 Production-free verification**

## 1. Scope and authority

The authorized initial Shared State command ran once and ended with
`REMOTE_STATUS_MISSING` at `SOURCE_READ_ONLY_INVENTORY`. Human performed no
additional operation. The initial Evidence is retained byte-for-byte. This
Corrective does not authorize a retry, Production cleanup, Shared State
creation, Application placement, Migration, Deploy, public-entry change, DNS
or SSL change.

## 2. Retained initial Evidence

- Directory:
  `storage/app/release-audit/production-g5-shared-state-924af91188cc60d33ff87c91b94ecc1d539566e6`
- Exact entry: `execution-state.json` only
- Entry size: 1,425 bytes
- SHA-256:
  `bb98d39709625d619d0365b57850613580237be837dcc85a863d00adfbcb248d`
- Helper generation: `g5-shared-state-v1`
- Contract SHA-256:
  `cc11ad5a67e0f3f869da15091d91740f9e4d584d90995bd86d38ae22d7a282d1`
- Status / safe error: `STOP / REMOTE_STATUS_MISSING`
- Local substage: `SOURCE_INVENTORY_PROCESS_PENDING`
- Local state generation: `4`

## 3. Exact failure boundary

| Question | Evidence result |
|---|---|
| Source native process Start | **YES, twice.** `remote_process_count=2`; the helper increments this only after native process Start succeeds. |
| Legacy preflight authentication / remote shell | **ESTABLISHED.** Inventory is invoked only after the first SSH process returns a parsed `G5_SHARED_SOURCE_PREFLIGHT=PASS`. |
| Inventory authentication / remote shell | **UNKNOWN.** Its native process started, but the initial Evidence did not persist its exit or streams. |
| Inventory remote exit code | **UNKNOWN / not persisted.** |
| Inventory stdout metadata | **UNKNOWN / not persisted.** No recognized `G5_SHARED_SOURCE_INVENTORY` status existed in the captured stdout when parsed, but bytes/hash were not saved. |
| Inventory stderr metadata/content | **UNKNOWN / not persisted or stored.** |
| Source inventory contract | The **local inventory invocation began**. Whether the remote projector began is **UNKNOWN**. |
| Remote status generation | **UNKNOWN.** The helper only proves that the captured stdout lacked the expected status at parse time. |
| `.env` values read by inventory | **UNKNOWN.** Source preflight checks metadata/readability without reading values; inventory execution itself is not proven. |
| Storage inventory began | **UNKNOWN.** It occurs inside the same projector after environment parsing. |
| NewTarget connection | **0 / not attempted.** `target_connection_attempted=false`. |
| Production mutation | **0.** Source operations were read-only; Target prepare never began and `production_mutation_scope=false`. |
| Cleanup / rollback | `not_required / not_required`. |

## 4. Secret and residue disposition

The retained Evidence directory contains only the sanitized state JSON.
`raw_output_stored=false`, `secret_values_output=false`, and
`transient_config_residual_count=0`. The helper had no local persistence path
for source `.env` content or remote raw streams. Therefore no secret value is
present in the retained local Evidence or terminal contract. Whether the
failed remote inventory process read `.env` values remains **UNKNOWN** and is
not inferred from the absence of local residue.

## 5. Root Cause boundary

The initial helper sent the PHP projector on standard input while invoking:

```text
php -- inspect-source <arguments>
```

Production-free execution of the initial projector through this exact CLI
shape reproduces a fatal output boundary: PHP runs the stdin projector with
the expected `argv`, but the old projector writes via the `STDOUT` constant,
which is unavailable during stdin script execution. It therefore cannot emit
the expected sanitized status. This is classified as:

`PROJECTOR_STDOUT_CONSTANT_INCOMPATIBLE_WITH_STDIN_EXECUTION`

The actual Production inventory exit/stderr remains **UNKNOWN**, because the
initial helper did not persist stream metadata before parsing stdout. The
second defect is therefore an Evidence Contract deficiency, not permission to
reconstruct unavailable observations.

## 6. Corrective-1

Corrective-1 keeps the verified PHP stdin/argv invocation unchanged and emits
sanitized output through `echo`, with no `STDOUT` constant dependency. It also
records, before any safe output parse:

- exit code;
- stdout SHA-256 and byte count;
- stderr SHA-256 and byte count;
- `raw_output_stored=false`.

Raw remote output and secret values remain excluded. Corrective-1 is bound to
the immutable initial state hash, uses a separate Evidence generation, and
fails closed if either the initial Evidence or implementation binding drifts.

Corrective contract:

- `deployment/g5-target-environment/shared-state-corrective1-contract.json`
- SHA-256:
  `1637b67d6acc95c50e2433d9e48cb21328522d357ae7468c0bb1b973fa443064`

## 7. Production-free verification

- Initial Evidence exact entry/hash/state assertions: PASS
- Initial projector stdin output failure reproduction: PASS
- Corrective projector with the same stdin/argv path: PASS
- Fixture secret non-disclosure: PASS
- Initial remote exit/stderr/content overclaim prevention (`UNKNOWN` retained): PASS
- Corrective Evidence Contract and implementation binding: PASS
- Helper persistence-only path: PASS, Production connection 0
- Helper verify-only path: PASS, Production connection 0
- Retired initial execution path fail-closed: PASS, Production connection 0
- Corrective Evidence directory creation during verification: 0
- G5 focused regression: **31 tests / 512 assertions PASS**
- Full Laravel suite: **779 passed / 17 skipped / 8 failed**. The failures
  are outside this Corrective: one pre-existing Company Navigation stale
  intended-URL assertion and seven G2 tests whose setup requires a previously
  executed Corrective-2 Evidence directory to be absent. G5 Shared State is
  fully PASS inside the same full run.

## 8. Gate disposition

- G5: **OPEN**
- Initial retry: **not available**
- Corrective-1 execution: **not authorized**
- `PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION`:
  `PENDING_G5_PUBLIC_ENTRY_GATE`
- Deploy authorized: **false**
- Next action: Human + ChatGPT review. A new one-shot Human Gate requires
  explicit approval before any Production connection.
