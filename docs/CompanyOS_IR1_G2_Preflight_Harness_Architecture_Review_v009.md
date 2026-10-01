# Company OS｜IR-1 G2 Preflight Harness Architecture Review

Date: 2026-10-02 JST

## Recommended Decision

**DO NOT CREATE CORRECTIVE-6 ON THE SAME HARNESS**

**REDESIGN THE G2 PREFLIGHT EXECUTION METHOD**

**G2 OPEN / Production Deploy and Migration NO-GO**

次のProduction接続、Human Command、Migration、DDL、data mutationは承認されておらず、本Reviewでは実行・提示しない。

## Corrective-5 Immutable Evidence

- Candidate: `924af91188cc60d33ff87c91b94ecc1d539566e6`
- Generation: `corrective-5`
- Helper SHA-256: `c79500517d12e42b950bd8605c61d3d60f43a218bab57b81486a69e49eb8f035`
- PHP Contract SHA-256: `76dec6fc2b4cbc884b1a6c6a1e79142b79efb8725ddb021d0f88e0a9eef0a03c`
- Execution state SHA-256: `4ccb3a4b993879384cd999a1d2305df9f8eeeb494ba4af63a80121c85e244e1d`
- Status: `STOP`
- Safe error: `UNEXPECTED_LOCAL_FAILURE`
- Native argument contract: `validated`
- Remote exit: `1`
- stdout: null / `0 bytes`
- stderr SHA-256: `b495d3d1f2f4661949bb0cd30f8f86ffac9221714070eb5f5d116d7c9baeab00`
- stderr: `133 bytes`
- Local processing substage: `remote_stdout_validation`
- Local exception SHA-256: `95e3a4b2088c16d7dc329bae8a6c8dc6151d5bdabd8c25d9a1922a103d5cdaa3`
- Production mutation: `false`
- Retry: `false`

Raw stderr、raw exception、Secret、Credential、raw pathは保存・表示していない。

## Evidence-based Boundary Result

| Boundary | Result | Basis |
|---|---|---|
| Native process `Start()` | **ESTABLISHED** | process resultとしてremote exitとstderr hash/bytesを取得 |
| Native argument validation | **PASS** | stateのvalidated contract |
| SSH authentication for this attempt | **UNKNOWN** | SSH層のallowlisted markerなし |
| Remote shell for this attempt | **UNKNOWN** | remote stdout markerなし |
| Remote exit code | **1** | saved state |
| stdout | null / **0 bytes** | saved state |
| stderr | **133 bytes** / hashのみ | saved state |
| PHP Contract start | **UNKNOWN** | PHP開始markerなし |
| DB connection | **UNKNOWN** | sanitized PHP Evidenceなし |
| SQL statement count | **UNKNOWN** | sanitized PHP Evidenceなし |
| SQL rejected count | **UNKNOWN** | sanitized PHP Evidenceなし |
| Allowed SQL class | SELECT only | static Contract。今回の実行到達はUNKNOWN |
| Migration / DDL / data mutation | **0 / ESTABLISHED** | 実行pathに存在せず、非SELECT guardあり |
| Production persistent mutation | **0 / ESTABLISHED** | shell/PHP Contractとsaved state |

Remote Runtime DiagnosticのPASSは同一SSH/native transportが過去の別attemptで成立したことを示すが、Corrective-5 attemptのauthenticationやremote shell成立を代替証明しない。

## Exact Root Cause Assessment

### Established immediate stop

Native processは開始し、exit `1`、empty stdout、133-byte stderrを返した。Local Harnessは`remote_stdout_validation`でterminal sanitized Evidenceを取得できず、`UNEXPECTED_LOCAL_FAILURE`として停止した。

### Upstream remote cause

**UNKNOWN / NOT RECOVERABLE FROM CURRENT EVIDENCE CONTRACT**

Current EvidenceにはSSH、shell、PHP、DBのincremental markerがなく、stderr本文は安全上hash/byte countだけを保持する。そのため、authentication failure、remote shell pre-contract failure、PHP pre-contract failure等を区別できない。推測による補完はしない。

Local static auditではexact generated remote scriptについて以下を確認した。

- LF-only
- CR count 0
- heredoc delimiter count 1
- local Bash syntax check exit 0
- local Bash syntax stderr 0

したがってCorrective-3で見つかったCRLF/heredoc syntax defectの再発は否定できる。しかしProduction runtime failureのexact原因は確定できない。

### Local failure capture

Corrective-3とCorrective-5のlocal exception hashは同一で、両方ともempty stdout後の`remote_stdout_validation`で停止している。Validator parameterはempty inputを明示的に許可しておらず、empty-output pathのlocal regression coverageもない。これはHarness defectである。

ただし、保存済みhashとProduction-free replay hashは一致しなかったため、Corrective-5のexact local exception本文までは確定扱いにしない。

## Why Corrective-1 through 5 Did Not Catch It

1. Testはstatic token、validator fixture、minimal local PHP stdinを中心とし、composed remote script全体をProduction-equivalent POSIX runtimeでend-to-end実行していなかった。
2. Runtime Diagnosticは意図的にSSH/PHP/minimal stdin/stdoutだけを対象とし、filesystem checks、heredoc、autoload、`.env`、DB Contractを含まなかった。
3. Current protocolは最後の単一JSONに依存し、それ以前の正常到達点を保存しない。
4. `production_connection_attempted`は粗いbooleanで、process start、authentication、shell startを区別しない。
5. stderrは安全にhash化されたが、allowlisted remote failureへの正規化層がなく、診断可能性を失った。
6. Windows `ProcessStartInfo.Arguments`、SSH argv、Bash stdin、heredoc、PHP stdin、JSON validationを一つのHelperが同時に担っている。
7. 世代ごとのSTOP bindingが増え、Harness correctnessよりEvidence generation orchestrationが複雑化した。

## Remote Runtime Diagnosticとの差分

| Runtime Diagnostic | G2 Preflight |
|---|---|
| 短いLF-only shell | 約16 KBのshell + embedded PHP |
| heredocなし | heredocあり |
| filesystem/application path確認なし | Bundle / manifest / `.env` path確認あり |
| minimal PHPのみ | Composer autoload / Dotenv / PDOあり |
| DB接続0 | Production DB read-only接続を行う |
| fixed single result | 複数check後のterminal result |
| failure surfaceが小さい | SSH / shell / filesystem / PHP / DBが結合 |

Diagnostic PASSはtransportの最小成立を証明したが、G2 Harness全体の成立は証明していなかった。

## Structural Review

Current Harnessには構造的問題がある。

- inline remote programとDB audit sourceを一つのstdinへ多重化
- terminal JSONが出ないfailureを層別できない
- native / SSH / shell / PHP / DBの責任境界が未分離
- stderr safetyとdiagnostic completenessを両立するnormalization layerがない
- exact composed artifactのoffline実行試験がない
- retry世代追加が原因除去より先行しやすい

よって同方式へCorrective-6を追加することはRecommendedではない。

## Recommended G2 Preflight v2 Architecture

### 1. Exact audit artifact

G2専用のcandidate-bound audit artifactをProduction-freeで構築する。

- standalone launcher
- standalone PHP read-only auditor
- manifest / source candidate / file SHA-256 / output schema
- LF-only、symlink 0、Secret 0
- 既存R0 Bundleとは別identityで保持

### 2. No embedded source transport

PHP sourceをSSH stdin heredocで毎回組み立てない。事前検証済みartifact内のfileをhash検証後に実行する。配置はProduction mutationを伴うため、将来の別Human Gateが必要である。

### 3. Framed sanitized protocol

単一terminal JSONだけでなく、allowlisted incremental frameを使用する。

- remote shell established
- artifact / filesystem verified
- PHP interpreter started
- PHP Contract started
- DB connection not attempted / established
- last completed read-only check
- SQL SELECT count / rejected count
- terminal PASS / STOP

各frameは固定schema、連番、candidate binding、Secret/raw dataなしとし、local側は検証済みframeだけを保存する。

### 4. Layer-specific exit contract

SSH、shell preflight、PHP bootstrap、DB read-only auditを別failure classへ正規化する。Raw stderrは保存・表示せず、hash/byte countに加えてremote wrapperがallowlisted safe errorをstdout frameへ変換する。

### 5. Production-equivalent automated verification

次回Production接続前に、exact artifactを用いて以下を自動検証する。

- POSIX shellでのexact launcher実行
- MariaDB 10.11 isolated DB
- success / authentication-like failure / shell failure / PHP startup failure / empty stdout / stderr-only / DB unavailable
- partial frame preservation
- SELECT-only instrumentation
- no file mutation / no queue / no mail / no provider / no Secret output
- overwrite / retry prohibition

### 6. Separate Human Gates

v2は少なくとも以下を分離する。

1. Production-free artifact review
2. separately authorized artifact placement and integrity verification
3. separately authorized one read-only execution

Human Operationは1 Step = 1 Command / PASS or STOPを維持する。

## Current Gate

**G2 HARNESS REDESIGN REQUIRED**

次の作業はProduction-freeのG2 Preflight v2設計・実装・Automated Verificationである。完了後にHuman + ChatGPT Reviewへ戻し、それまではProduction接続やCommandを提示しない。
