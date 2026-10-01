# Company OS｜IR-1 G2 Corrective-3 STOP Analysis

Date: 2026-10-02 JST

## Decision

**G2 OPEN**

**Production Deploy / Migration: NO-GO**

Corrective-3の元Evidenceは変更・削除しない。再実行、Migration、DDL、data mutation、Production調査Commandは実施しない。

## Immutable Evidence

- Candidate: `924af91188cc60d33ff87c91b94ecc1d539566e6`
- Generation: `corrective-3`
- Helper SHA-256: `c9c873929fb8533118d428751aa4987aa72921e3dabe45175a5c53158b34abdc`
- PHP contract SHA-256: `76dec6fc2b4cbc884b1a6c6a1e79142b79efb8725ddb021d0f88e0a9eef0a03c`
- Execution state SHA-256: `83469c014b729870a9331894a8fd8eef25994103fa9246357afab077f0a61b70`
- Status: `STOP`
- Safe error: `UNEXPECTED_LOCAL_FAILURE`
- Remote exit: `1`
- stdout SHA-256: null
- stdout bytes: `0`
- stderr SHA-256: `ab02fc1d5da09b4c62c0840b134d9be70f8840f35d2f5582d9f1a9732da5a97c`
- stderr bytes: `808`
- Local processing substage: `remote_stdout_validation`
- Local exception SHA-256: `95e3a4b2088c16d7dc329bae8a6c8dc6151d5bdabd8c25d9a1922a103d5cdaa3`
- Sanitized remote Evidence file: absent
- Production mutation: false
- Retry: false

## Root Cause

### Established direct stop

Remote processはexit `1`と808-byte stderrを返したが、stdoutは完全に空だった。Helperはremote resultの取得とhash/byte count保存を完了し、`remote_stdout_validation`へ進んだ後にlocal例外でSTOPした。

したがって直接停止要因は、

**EMPTY REMOTE STDOUT → LOCAL STDOUT CONTRACT VALIDATION FAILURE**

である。

### Remote-side cause boundary

Remote shellの`g2_shell_stop`とPHPの`g2Stop`は、正常なfail-closed経路ではsanitized JSONをstdoutへ出力する。今回stdoutが0 bytesであるため、これらの観測可能なfailure contractは成立していない。

Remote側のfailureは、sanitized JSONを出す前のshell/interpreter境界、またはfailure contract自体が作動できない境界に限定される。ただしraw stderrは安全設計上保存されず、Corrective-3にはremote pre-PHP markerやstderr分類contractがないため、shellとPHP interpreterのどちらか、およびexact command/checkは区別不能である。

## Requested Evidence Evaluation

| Item | Evidence-based result |
|---|---|
| stdout SHA-256 / bytes | null / `0` |
| Local processing substage | `remote_stdout_validation` |
| Local exception hash | `95e3a4b2088c16d7dc329bae8a6c8dc6151d5bdabd8c25d9a1922a103d5cdaa3` |
| Exact remote substage | **UNKNOWN** |
| PHP contract start | **UNKNOWN** |
| DB connection | **UNKNOWN** |
| SQL statement count | **UNKNOWN** |
| SQL statement class | Execution EvidenceはUNKNOWN。Contract上の許可classはSELECTのみ |
| Rejected statement count | **UNKNOWN** |
| Last completed check | **UNKNOWN** |
| Row count / role distribution | **NOT ACQUIRED** |
| Schema collision | **NOT ACQUIRED** |
| Transaction / metadata lock | **NOT ACQUIRED** |
| Production persistent mutation | **0 / ESTABLISHED** |
| Migration / DDL / data mutation | **0 / ESTABLISHED** |

## Why UNKNOWN remains after Corrective-3

Corrective-3は、remote stdoutが存在する場合のsafe PASS / safe failure内容とlocal processing failureを保存するCorrectiveだった。今回はremote stdout自体が0 bytesであり、保存対象となるsanitized partial Evidenceが存在しなかった。

stderrはhash/byte countだけを保持し、raw内容を保存しないSafety Contractである。またremote scriptは、PHP起動前後をstdoutへ独立markerとして出す仕組みを持たない。このため次の状態はEvidenceなしに判別できない。

- PHP interpreterが開始したか
- PHP contract本体が開始したか
- DB接続へ到達したか
- SELECTが何件実行されたか
- 最後に完了したread-only check

## Mutation Safety

Production persistent mutation 0は成立する。Remote shellはread-only path/check/hash処理のみで、PHP contractは固定SELECTを唯一のDB execution pathとし、非SELECTを実行前拒否する。Migration、DDL、data mutation、permission変更、backup変更のpathは存在しない。

## Current Gate

Corrective-3 STOPだけではG2 Acceptance Conditionは成立しない。次のCorrectiveやProduction read-only operationは本Analysisとは分離し、Human + ChatGPTの別Reviewと承認を必要とする。
