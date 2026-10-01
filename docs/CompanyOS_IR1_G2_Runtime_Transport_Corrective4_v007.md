# Company OS｜IR-1 G2 Runtime Transport Corrective-4

Date: 2026-10-02 JST

## Recommended Decision

**READY FOR ONE SEPARATELY AUTHORIZED G2 CORRECTIVE-4 READ-ONLY PREFLIGHT**

**G2 OPEN / Production Deploy and Migration NO-GO**

このEvidence Packageは次回Production preflightの実行承認ではない。Human + ChatGPTによる別承認までは、Production接続およびG2 preflightを実行しない。

## Established Runtime Boundary

承認済みDB-free Remote Runtime Diagnosticは1回のみ実行され、以下をすべてPASSした。

- SSH remote shell
- PHP CLI discovery
- PHP interpreter start
- UTF-8 stdin経由の最小PHP実行
- 固定sanitized stdout
- remote exit code capture

Diagnostic Evidence identity:

- Candidate: `924af91188cc60d33ff87c91b94ecc1d539566e6`
- Diagnostic helper SHA-256: `77a4720ec377afaee740f74708eb76b46e598cf4416118b0435c28413f349882`
- Diagnostic execution state SHA-256: `af10dfc9166c63e200e340f92409648c56ef7ece449f18675826d9aa61441501`
- Diagnostic sanitized Evidence SHA-256: `dfb4629417fce372a2678b5554bf72809b80ab5c9559660d1d15198e405d748d`
- Production mutation: `false`
- DB / SQL / `.env` / Application bootstrap: `0`

## Root Cause and Corrective

Corrective-3までのG2 remote scriptは大部分がLFである一方、PHP source末尾とheredoc終端にWindows由来のCRLFを含んでいた。Bash heredocの終端一致を壊し得るこのtransport defectは、Corrective-3のremote exit `1`、stdout `0 bytes`、stderr `808 bytes`と整合する。

Corrective-4では、成立済みRuntime Diagnosticと同じtransport boundaryをG2 preflightへ適用した。

- remote script全体をLF-onlyへ正規化
- heredoc delimiterをLFのみで厳密に終端
- PowerShell pipelineを使わず、UTF-8 BOMなしのbyte streamをnative process stdinへ直接送信
- 成立済みPHP CLI discovery / interpreter / stdin / stdout経路を再利用
- DB read-only PHP Contract本体は変更しない
- Corrective-4専用Evidence rootを使用し、過去世代を上書きしない
- Initial / Corrective-1 / Corrective-2 / Corrective-3 STOP EvidenceとRuntime Diagnostic PASS Evidenceへexact SHA-256 binding
- retry、Evidence overwrite、Production mutationはfail closed

## Exact Corrective Identity

- Helper: `deployment/r0-audit/Invoke-G2MigrationSafetyPreflight.ps1`
- Helper SHA-256: `734a386f6f7a48bc3653b81b65a4c6692e904a381bc9bd9af986012ef06155a8`
- DB read-only Contract: `deployment/r0-audit/g2-migration-preflight.php`
- DB read-only Contract SHA-256: `76dec6fc2b4cbc884b1a6c6a1e79142b79efb8725ddb021d0f88e0a9eef0a03c`
- Corrective generation: `corrective-4`
- Corrective-4 Evidence root: `production-g2-migration-preflight-corrective-4-924af91188cc60d33ff87c91b94ecc1d539566e6`

## Safety Contract Maintained

- Production DB statement allowlist: SELECT only
- non-SELECT / mutation statement: rejected before execution
- Migration / DDL / data mutation: prohibited
- persistent DB write: prohibited
- permission / backup / symlink / `.env` mutation: prohibited
- Secret / Credential / raw ID / raw path / raw exception output: prohibited
- retry: prohibited
- existing Evidence overwrite: prohibited
- next Human operation, if separately approved: one command / PASS or STOP

## Production-free Verification

- PowerShell execution / parse through `VerifyOnly`: PASS
- remote script LF-only: PASS
- exact heredoc LF termination: PASS
- UTF-8 byte-stream PHP stdin/stdout transport: PASS
- Runtime Diagnostic PASS Evidence binding: PASS
- Initial through Corrective-3 immutable STOP Evidence binding: PASS
- R0 / G1 Evidence binding: PASS
- Corrective-4 local preconditions: PASS
- focused R0 / G1 / G2 regression: **15 tests / 331 assertions PASS**
- full Laravel regression: **686 tests PASS / 17 skipped / 1 unrelated existing failure**
- unrelated failure: `CompanyNavigationTest`のstale intended URL期待値（対象file単独でも再現し、今回のG2 helper / test / Evidence差分とは非交差）
- Production connection during Corrective / verification: `0`
- Production mutation during Corrective / verification: `0`

## Remaining Boundary

Corrective-4はtransport/runtime defectをProduction-freeで修正・検証したが、Production固有Migration Safetyのrow count、role distribution、schema collision、transaction / metadata lock条件はまだ取得していない。これらは次の、別承認されたCorrective-4 read-only preflight最大1回でのみ確定できる。

G1から継続する以下のBlockerは解消扱いにしない。

- usable backup / restore readiness
- Release markerとApplication codeのbinding
- `.env` permission hardening
- 必要なcron / external writer確認

## Gate

Recommended next actionは、Human + ChatGPTが別途承認する **ONE G2 CORRECTIVE-4 READ-ONLY PREFLIGHT** である。承認前にCommandは提示せず、実行もしない。

PASS時はG2 Acceptance Conditionを正式評価する。STOP時は再実行せず、Corrective-4のsanitized Evidenceから原因を評価する。
