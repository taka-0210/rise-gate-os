# Company OS｜IR-1 G2 Corrective-1 STOP / Corrective-2 v003

Date: 2026-10-02 JST

## Decision

**G2 OPEN / EXACT REMOTE SUBSTAGE NOT RECOVERABLE / CORRECTIVE-2 PREPARED**

**Production Deploy / Migration: NO-GO**

Corrective-1 read-only preflightは1回実行され、Fail Closedした。再実行、Migration、DDL、data mutationは行っていない。

## Corrective-1 retained Evidence

- Execution generation: `corrective-1`
- Helper SHA-256: `59de505cc3fe9af0ad4922ac20fe12a58c07d1cf0362e6683ea2144b66b3fd64`
- PHP SHA-256: `76dec6fc2b4cbc884b1a6c6a1e79142b79efb8725ddb021d0f88e0a9eef0a03c`
- State SHA-256: `81795b54e7419f8fb29e0d5de306d6768b76ab27da69badab7d6fcf3f2c69a55`
- Remote exit: `1`
- stderr: 808 bytes / SHA-256 `21b793d0614ffa599d49402fb7115e7ecec2576d1d3fa6eeb752f65fd61b3786`
- Raw stderr retained: false
- Sanitized Evidence file: absent
- `remote_safe_error_code`: null
- Production mutation: false
- Retry: false

## Exact evaluation

| Item | Evaluation |
|---|---|
| SSH / remote shell | **ESTABLISHED** |
| Remote script syntax | **PASS**（同一生成scriptのlocal Git Bash `-n`） |
| External `base64` dependency | **REMOVED / VERIFIED** |
| Laravel bootstrap | **NOT USED** |
| PHP contract started | **UNKNOWN** |
| DB connection | **UNKNOWN** |
| SQL statement count / class / rejected | **UNKNOWN** |
| Row count / role distribution | **NOT AVAILABLE AS EVIDENCE** |
| Schema collision | **NOT AVAILABLE AS EVIDENCE** |
| Transaction / metadata lock | **NOT AVAILABLE AS EVIDENCE** |
| Persistent DB / schema mutation | **0 / ESTABLISHED** |

Production mutation 0は、remote scriptにfile mutationがなく、PHP SQL guardがSELECT以外を実行前拒否し、Migration / DDL pathを持たないことから成立する。一方、Corrective-1のPHPが何件のSELECTを完了したかは保存Evidenceから復元できないため、0と推定してはならない。

## Evidence Contract defect

Corrective-1 Helperはremote resultを受け取った後、stderrが1 byteでも存在すると、stdoutのsanitized JSONを検証・保存する前に`G2_REMOTE_PREFLIGHT_FAILED`へ遷移した。

したがって、remote shellの`g2_shell_stop`またはPHPの`g2Stop`が安全なsubstage / DB connection / SQL progressをstdoutへ返していた可能性があるが、そのstdoutは保存されなかった。raw stdout/stderrを後から復元する手段もない。

今回のexact failure stageは、**SSH後のremote preflight内**までしか確定できない。Humanへ調査Commandを求めず、証拠がない状態でPHP開始・DB接続・SQL件数を断定しない。

## Production-free Corrective-2

- stdoutをstderr判定より先にallowlist schemaで検証。
- safe PASS / safe failureならsanitized stdoutを必ずlocal保存。
- safe failureではremote `safe_error_code`、DB connection、completed condition、SQL countを保持してSTOP。
- safe PASSでもstderrがあればEvidenceを保存した上で`G2_SAFE_PASS_WITH_STDERR`としてSTOPし、PASSへ昇格しない。
- rejected stdoutは保存しない。
- `corrective-2`専用Evidence rootへ分離。
- initial STOPとCorrective-1 STOPのexact state SHA-256へbinding。
- retry / overwrite禁止を維持。

Corrective-2 assets:

- Helper: `deployment/r0-audit/Invoke-G2MigrationSafetyPreflight.ps1`
- Helper SHA-256: `1b086b5c9414c469361382b4d76fb0d7475bef5f649833609b02d8a6e4edb56a`
- PHP SHA-256: `76dec6fc2b4cbc884b1a6c6a1e79142b79efb8725ddb021d0f88e0a9eef0a03c`

## Gate

G2 Acceptance Conditionは未成立。G2をCloseしない。

Production固有Migration Safety Evidenceを取得するには、**ONE SEPARATELY AUTHORIZED G2 CORRECTIVE-2 READ-ONLY PREFLIGHT**が必要である。承認前に実行しない。

G1から継続するusable backup / restore readiness、release marker binding、`.env` permission hardening、cron / external writerのBlockerを維持する。
