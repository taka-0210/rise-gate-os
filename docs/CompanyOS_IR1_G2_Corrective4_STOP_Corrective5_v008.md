# Company OS｜IR-1 G2 Corrective-4 STOP / Corrective-5

Date: 2026-10-02 JST

## Decision

**G2 OPEN**

**Production Deploy / Migration: NO-GO**

**Corrective-5 Prepared / Production Execution Not Authorized**

## Corrective-4 Immutable Evidence

- Candidate: `924af91188cc60d33ff87c91b94ecc1d539566e6`
- Generation: `corrective-4`
- Helper SHA-256: `734a386f6f7a48bc3653b81b65a4c6692e904a381bc9bd9af986012ef06155a8`
- DB read-only Contract SHA-256: `76dec6fc2b4cbc884b1a6c6a1e79142b79efb8725ddb021d0f88e0a9eef0a03c`
- Execution state SHA-256: `e81e8f59a2e8b8bd1fcd082ec20ffd24949535cca6dcba70498eedfa40d571a8`
- Status: `STOP`
- Safe error: `G2_NATIVE_ARGUMENT_REJECTED`
- Local processing substage: `remote_process_execution`
- Remote exit code: null
- stdout: `0 bytes`
- stderr: sanitized error code hash only / `27 bytes`
- Production mutation: `false`
- Retry: `false`

元のCorrective-4 STOP Evidenceは変更・削除しない。

## Exact STOP Analysis

Rejectされたargumentの分類は、Secret、Credential、identity、host、port、raw pathではなく、固定されたSSH remote command argumentである。

Corrective-4はremote commandとoptionを単一argumentとして保持していた。その固定argumentには意図したtoken separatorの空白が含まれる一方、native process guardは全argument内の空白を一律拒否する契約だった。このため、guard自身が固定remote commandを拒否した。

停止順序は次のとおりである。

1. Corrective-4 local Evidence root / stateを作成
2. `production_connection_attempted=true`を事前記録
3. remote process実行関数へ進入
4. native argument guardが固定remote command argumentを拒否
5. process objectの`Start()`到達前にSTOP

したがって保存stateの`production_connection_attempted=true`は当時の「接続工程へ進む意図」を示す事前フラグであり、実際のSSH process開始を示さない。コード順、remote exit null、stdout 0 bytesから、今回の実Production接続は0と確定する。

## Requested Boundary Results

| Item | Evidence-based result |
|---|---|
| Rejected argument classification | fixed SSH remote command argument |
| Reject reason | intentional token separator whitespaceをblanket guardが拒否 |
| Reject point | local native argument validation / process `Start()`前 |
| SSH process start | `0 / NOT STARTED` |
| PHP contract start | `0 / NOT STARTED` |
| DB connection | `0 / NOT ATTEMPTED` |
| SQL statement count | `0` |
| SQL rejected statement count | `0`（DB Contract未開始） |
| Migration / DDL / data mutation | `0` |
| Production persistent mutation | `0 / ESTABLISHED` |

## Corrective-5

Production通信なしで以下を実装した。

- fixed remote commandを安全な2 tokenへ分離
- 各native argumentの空白・empty拒否は維持
- native argument vectorをlocal self-testで検証
- argument検証完了後にのみProduction connection attempted flagを設定
- stateへ`native_argument_contract`を追加
- Corrective-5専用Evidence rootを使用し、過去Evidenceを上書きしない
- InitialからCorrective-4までのSTOP、Runtime Diagnostic PASS、R0 / G1 Evidenceへexact hash binding
- LF-only remote script / UTF-8 byte-stream / DB read-only Contractを維持
- retry、overwrite、Production mutationをfail closed

Corrective-5 identity:

- Helper: `deployment/r0-audit/Invoke-G2MigrationSafetyPreflight.ps1`
- Helper SHA-256: `c79500517d12e42b950bd8605c61d3d60f43a218bab57b81486a69e49eb8f035`
- DB read-only Contract SHA-256: `76dec6fc2b4cbc884b1a6c6a1e79142b79efb8725ddb021d0f88e0a9eef0a03c`
- Generation: `corrective-5`
- Evidence root: `production-g2-migration-preflight-corrective-5-924af91188cc60d33ff87c91b94ecc1d539566e6`

## Production-free Verification

- Helper `VerifyOnly`: PASS
- Corrective-5 local preconditions: PASS
- native argument tokenization: PASS
- argument validation before connection-attempt flag: PASS
- LF-only remote script: PASS
- UTF-8 byte-stream stdin/stdout transport: PASS
- DB read-only Contract unchanged: PASS
- Corrective-4 STOP exact binding: PASS
- Runtime Diagnostic PASS exact binding: PASS
- focused R0 / G1 / G2 regression: **15 tests / 341 assertions PASS**
- Production connection during analysis / Corrective / verification: `0`
- Production mutation during analysis / Corrective / verification: `0`

## Recommended Next Action

次のProduction read-only preflightは技術的に再実行可能な状態だが、Corrective-5としてHuman + ChatGPTの別承認が必要である。

Recommended Decision:

**READY FOR ONE SEPARATELY AUTHORIZED G2 CORRECTIVE-5 READ-ONLY PREFLIGHT**

承認前にCommandは提示せず、実行しない。PASS / STOP後は再実行せずHuman + ChatGPT Reviewへ戻る。
