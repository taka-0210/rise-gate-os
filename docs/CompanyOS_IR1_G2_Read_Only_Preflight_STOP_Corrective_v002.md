# Company OS｜IR-1 G2 Read-only Migration Preflight STOP / Corrective v002

Date: 2026-10-02 JST

## Decision

**G2 REMAINS OPEN / CORRECTIVE PREPARED**

**Production Deploy / Migration: NO-GO**

承認済みG2 Application / DB Read-only Migration Preflightは1回だけ実行され、remote shell開始後にFail Closedした。再実行、Migration、DDL、data mutationは行っていない。

## Retained attempt Evidence

- Candidate: `924af91188cc60d33ff87c91b94ecc1d539566e6`
- Original helper SHA-256: `0e31b3e2d00eadf14ca7e278ea7ede21a91bed46c365257185634f8341fd51b3`
- Original PHP SHA-256: `878a0399c50b0841e1d9c45cfb6b0f2cae1a297adf3d3bb9046ce7ebc7027cca`
- Original execution state SHA-256: `918cec809034fc752906ed9e140da10c2255e36009e76765b624d6d2c8cd8808`
- Remote exit code: `127`
- stderr: 607 bytes / raw保存なし / SHA-256のみ
- Production connection attempted: true
- Production mutation: false
- Retry performed: false

元のSTOP Evidenceは変更・削除しない。

## What is established

| Question | Evaluation |
|---|---|
| SSH authentication / remote shell | **ESTABLISHED**。SSH transport failureの255ではなく、remote shell commandの127が返った。 |
| Laravel Application bootstrap | **NOT USED / NOT ATTEMPTED**。このpreflightはstandalone PDO contractでありLaravelをbootstrapしない。 |
| PHP sanitized audit contract entry | **NOT ESTABLISHED**。sanitized JSON outputが保存される地点へ到達していない。 |
| DB connection | **NOT ESTABLISHED / NOT ATTEMPTEDと評価**。PHP contract前のremote shell停止であり、PDO接続成立Evidenceはない。 |
| SQL execution | **0と評価**。PHP audit contractへ入っていないためSELECT実行Evidenceはない。 |
| Persistent DB / schema mutation | **0**。remote shellとPHP sourceの双方にwrite / DDL / Migration pathがなく、PHP audit自体も未到達。 |
| Row count / role distribution | **NOT ACQUIRED** |
| Schema collision | **NOT ACQUIRED** |
| Transaction / metadata lock | **NOT ACQUIRED** |
| Partial business Evidence adoption | **NOT PERMITTED**。採用可能なのはSSH remote shell成立、exit 127、mutation 0、query 0というtransport/safety Evidenceだけ。 |

## Root cause

Failure classは、PHP/DBより前の**remote shell command availability boundary**である。

R0で使用実績のある`sha256sum`、`find`、`wc`、`env`、PHP discoveryに対して、今回新たに追加された外部commandはpayload decode用の`base64`である。remote exit 127との整合から、`base64` command unavailableが最有力原因である。

ただし、元Helperはraw stderrを保存せず、安全なremote substageも出力しなかった。そのため、`base64` unavailableをFormal Established Root Causeへは昇格せず、**MOST LIKELY / original Evidence contractでは区別不能**として保持する。

## Provider-free / Production-free Corrective

- 外部`base64` command依存を除去。
- PHP sourceはshell here-documentからPHP stdinへ直接供給。
- remote shellの各preconditionをallowlistされた`safe_error_code`へ分離。
- PHP failure Evidenceへ以下を追加。
  - DB connection: not attempted / established
  - last completed condition
  - completed condition list
  - SQL statement count / SELECT count / rejected count
  - persistent write / DDL / Migration = false
- sanitized STOP JSONもlocal Evidenceとして保存可能にした。
- 元attempt stateのexact SHA-256とexit 127へCorrective generationをbinding。
- Correctiveは`-Corrective1`でのみ別Evidence rootへ記録し、元attemptをretryまたは上書きしない。

Corrective assets:

- `deployment/r0-audit/g2-migration-preflight.php`
- PHP SHA-256: `76dec6fc2b4cbc884b1a6c6a1e79142b79efb8725ddb021d0f88e0a9eef0a03c`
- `deployment/r0-audit/Invoke-G2MigrationSafetyPreflight.ps1`
- Helper SHA-256: `59de505cc3fe9af0ad4922ac20fe12a58c07d1cf0362e6683ea2144b66b3fd64`

## Automated Verification

- PHP syntax: PASS
- PowerShell AST parse: PASS
- Helper VerifyOnly: PASS
- Corrective-1 original STOP binding: PASS
- Corrective-1 local preconditions: PASS
- Sanitized zero-query failure fixture: PASS
- Focused R0 / G1 / G2 regression: 14 tests / 284 assertions PASS
- Verification中Production connection: 0
- Verification中Production mutation: 0

## Remaining Gate

G2 Acceptance Conditionは未成立であり、G2をCloseしない。Production固有のrow volume、role distribution、schema collision、transaction / lock Evidenceを得るには、Human + ChatGPTによる**ONE SEPARATELY AUTHORIZED G2 CORRECTIVE-1 READ-ONLY PREFLIGHT**が必要である。

その承認まではCorrective helperを実行しない。Production Deploy / MigrationはNO-GOを維持する。
