# Company OS｜IR-1 G2 Corrective-2 STOP / Corrective-3

Date: 2026-10-02 JST

## Decision

**G2 OPEN / Corrective-3 Prepared**

**Production Deploy / Migration: NO-GO**

Corrective-2の元Evidenceは変更・削除せず保持する。Corrective-3はProduction通信0件で準備しただけであり、Production read-only再実行は未承認・未実施である。

## Corrective-2 retained Evidence

- Candidate: `924af91188cc60d33ff87c91b94ecc1d539566e6`
- Execution generation: `corrective-2`
- Helper SHA-256: `1b086b5c9414c469361382b4d76fb0d7475bef5f649833609b02d8a6e4edb56a`
- PHP contract SHA-256: `76dec6fc2b4cbc884b1a6c6a1e79142b79efb8725ddb021d0f88e0a9eef0a03c`
- State SHA-256: `d2223bc7edb8db419fe275e086b0ba1589090e7873c8b279b1166dc75f871068`
- Status: `STOP`
- Local safe error: `UNEXPECTED_LOCAL_FAILURE`
- Failure stage: `PRODUCTION_READ_ONLY_G2_PREFLIGHT`
- Production connection attempted: true
- Remote exit: `1`
- stderr: 808 bytes / SHA-256 `c2f97e693d5910e0fb3ceb53ba220271b4bb822054863d0febb86272b9b58c86`
- Remote stdout contract status: `not_inspected`
- Sanitized remote Evidence file: absent
- Production mutation: false
- Retry: false

## Evidence-based evaluation

| Item | Evaluation |
|---|---|
| Exact remote substage | **UNKNOWN** |
| PHP contract start | **UNKNOWN** |
| DB connection | **UNKNOWN** |
| SQL statement count | **UNKNOWN** |
| SQL statement class | Executable contract is SELECT-only; executed class/count is **UNKNOWN** |
| Rejected statement count | **UNKNOWN** |
| Last completed preflight check | **UNKNOWN** |
| Organization-user row count | **NOT ACQUIRED** |
| Legacy role distribution | **NOT ACQUIRED** |
| Column/table collision | **NOT ACQUIRED** |
| Active transaction snapshot | **NOT ACQUIRED** |
| Pending metadata lock snapshot | **NOT ACQUIRED** |
| Persistent DB/schema mutation | **0 / ESTABLISHED** |
| Migration / DDL / data mutation | **0 / ESTABLISHED** |

Persistent mutation 0は、remote shellにProduction file mutation pathがなく、PHP contractが固定SELECTを唯一のDB execution pathとし、非SELECTを実行前拒否し、Migration / DDL pathを持たないことから成立する。実行されたSELECT件数やDB接続状態は保存Evidenceなしに推定しない。

## Local failure boundary

Corrective-2 receiptはremote exit、stderr hash/bytesの保存までは完了している一方、`remote_stdout_contract_status`は`not_inspected`のままである。

したがってlocal failure boundaryは、remote process result取得後の**remote stdout contract validation / sanitized Evidence write開始前後**に限定される。Corrective-2はlocal processing substage、stdout hash/bytes、local exception hashを保存していなかったため、validator内部とEvidence writeのどちらで例外化したか、およびremote exact substageは元Evidenceから復元不能である。

## Production-free Corrective-3

- Corrective-2 STOP state SHA-256へexact binding。
- `corrective-3`専用Evidence rootを使用し、過去attemptを上書きしない。
- PASS / INCONCLUSIVE validator全体をexception-safe化し、不完全・scalar・malformed JSONをthrowせずrejectする。
- safe failure / malformed Evidenceのlocal validator regressionを追加。
- remote result受領直後にstdout SHA-256 / byte countを保存。
- local processing substageを保存。
- local exception SHA-256をstderr Evidenceと独立保存。
- safe failureではremote safe error、failure stage、DB connection、last completed condition、SQL total/rejected countをstateへ先行保存。
- retry / overwrite禁止、Secret・raw stdout・raw stderr・raw exception非保存を維持。

Corrective-3 helper SHA-256:

`c9c873929fb8533118d428751aa4987aa72921e3dabe45175a5c53158b34abdc`

## Automated Verification

- PowerShell parse: PASS
- PHP syntax: PASS
- Corrective-3 `VerifyOnly`: PASS
- Evidence validator regression: PASS
- Corrective-3 local preconditions: PASS
- Focused R0 / G1 / G2 regression: **14 tests / 298 assertions PASS**
- Production connection during corrective/verification: 0
- Production mutation during corrective/verification: 0

## Next Gate

G2 Acceptance Conditionは未成立である。次に必要なのはHuman + ChatGPTによる別承認の、

**ONE SEPARATELY AUTHORIZED G2 CORRECTIVE-3 READ-ONLY PREFLIGHT**

である。承認まではCommandを提示・実行しない。G1継続Blocker、Production Deploy / Migration NO-GOを維持する。
