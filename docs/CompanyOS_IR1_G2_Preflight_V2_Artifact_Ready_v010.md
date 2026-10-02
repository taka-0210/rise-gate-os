# Company OS｜IR-1 G2 Migration Safety Preflight v2 Artifact Ready v010

Date: 2026-10-02 JST

## Decision

**G2 PREFLIGHT V2 ARTIFACT READY / PRODUCTION PLACEMENT WAITING**

**G2 OPEN / Production Deploy・Migration NO-GO**

Production接続・配置・実行は本Corrective中0件。既存Corrective-1〜5のSTOP Evidenceは変更していない。

## 1. Architecture

旧Harnessの「PowerShell → SSH stdin → shell source + inline PHP heredoc → terminal JSON」構造を廃止した。v2はcandidate-bound standalone artifactであり、次の3層を分離する。

1. `launcher.sh`: POSIX shell。artifact hash、candidate、PHP CLIを検証し、layer別frameを出力する。
2. `auditor.php`: Laravel Application bootstrapを使わないstandalone PDO auditor。DB設定は既存`.env`からprocess内にだけ読み込み、値を出力しない。
3. `manifest.json` / `checksums.sha256`: source candidate、R0 bundle、expected file set、file hash、Safety Contract、Gate順序を固定する。

PHP sourceのinline heredoc転送、external `base64`、`bash -s`、PowerShell native argumentによるremote source生成は使用しない。

## 2. Safety Contract

- exact candidate: `924af91188cc60d33ff87c91b94ecc1d539566e6`
- Application bootstrap: なし
- DB statement class: `SELECT`のみ
- SQL上限: 24
- Production persistent DB write: 0
- DDL: 0
- Migration execution: 0
- execution中のremote file mutation: 0
- raw Secret / Credential / identifier / path / exception output: 0
- PHP stderr: raw出力せず、allowlist済みsafe errorへ正規化
- unsupported: migration runtime prediction / external writer full visibility

## 3. Artifact identity

- Artifact: `storage/app/release-audit/ir1-g2-preflight-v2-924af91188cc60d33ff87c91b94ecc1d539566e6.tar.gz`
- Size: 5,156 bytes
- SHA-256: `b055585e09d7ae00c65bcaacad213fc4c92d260d4f8e2125884fe72d3cfb6b99`
- deterministic rebuild: PASS（before / after hash一致）
- manifest SHA-256: `f0abf01a18ae1e2bd0b2fb79197eb42c8570e62794da570453f30e603cb89dfc`
- `auditor.php`: `2a60d8c0b104915de400f5dad56a99b749cfe0be4d990b6b4da6c31024d25148`
- `launcher.sh`: `4586bdb8d903b1889f80ebc49dc88af7d876a4fae0d829018f4b1c80ed7de2ee`
- bound R0 bundle SHA-256: `a2cc319f42a7b0b3f84afc3077aeda1af0aa95d40f96e56103b18ad31b448b0d`
- bound R0 manifest SHA-256: `a15502cb7e832ef44affecd346d582f4b8550967fb55a2cd5a327d23005b9fd7`
- symlink: 0（deterministic build source setはregular fileのみ）
- `.env` / Secret: artifact内に含めない

## 4. Layer別Evidence Contract

stdoutはschema-v2 NDJSON frameで、各frameに`layer / event / status / data`を持つ。

| Layer | incremental Evidence | STOP時に保持する境界 |
|---|---|---|
| shell | `shell_started`, `artifact_verified`, `php_discovered`, `shell_terminal` | artifact / candidate / PHP discovery / PHP exit |
| php | `contract_started`, `terminal` | Contract開始、DB connection state、last completed check、SQL safety |
| database | checkごとの`check_completed` | database identity、table metrics、row count、role distribution、collision、transaction、metadata lock |

terminal-only outputへ依存せず、PHP / DB前の停止でも直前までのsanitized frameを保持する。

## 5. Failure injection

Focused regression: **7 tests / 65 assertions PASS**。旧契約を含むcombined regression: **12 tests / 193 assertions PASS**。

| Failure mode | v2 Result |
|---|---|
| Corrective-1: external `base64` availability | dependency自体を廃止 |
| Corrective-2: stderr先行判定でsafe stdout破棄 | stdout frameをdirect stream、raw stderrは非出力 |
| Corrective-3: empty terminal-only stdout | shell / PHP / DB incremental frameへ変更 |
| Corrective-4: CRLF / heredoc boundary | LF-only standalone files、heredoc 0 |
| Corrective-5: native argument tokenization | remote source/native SSH argv生成をartifact executionから除去 |
| artifact tamper | hash verificationでshell layer STOP |
| PHP unavailable | `G2_V2_PHP_UNAVAILABLE`、DB未到達 |
| candidate / runtime input mismatch | `contract_started`後にsafe STOP、SQL 0 |
| DB unavailable | `environment_loaded`まで保持、DB `not_attempted`、SQL 0 / reject 0 |

## 6. End-to-end isolated verification

Exact artifactを展開し、exact R0 bundleの`vendor`と接続して、synthetic schemaを持つloopback専用MariaDBで実測した。

- MariaDB: `10.11.19-MariaDB`
- retained server binary SHA-256: `a96d7b256e215ae8e0970249189d72abef44940b12fe2d0aa68cc2b6d3babcf0`
- source package formal Evidence SHA-256: `398ea30e5036010bbebe01d2b1804280424dcc2626e36d8e95155c04d25a0490`
- POSIX shell: PASS
- incremental frames: 16
- completed checks: 10
- SQL: 8件、すべてSELECT
- rejected statements: 0
- schema collision: 0（synthetic 83相当pre-migration boundary）
- persistent DB write / DDL / Migration: 0
- Production connection / mutation: 0
- sanitized Evidence: `storage/app/release-audit/g2-preflight-v2-isolated-verification.json`

隔離server / schema / `.env`はTEMP内のみで作成し、終了後削除した。通常local DBおよびProduction DBは使用していない。

### Repository regression note

- G2 combined focused regression: **12 tests / 193 assertions PASS**
- Full Laravel suite: **693 PASS / 17 SKIPPED / 1 FAIL / 5,896 assertions**
- FAILは既存`CompanyNavigationTest::test_regular_login_ignores_a_stale_forbidden_intended_url`で、単独再実行でも同じredirect差分を再現した。
- v2 deltaはdeployment standalone artifact、専用test、Evidence文書のみで、login / navigation / middleware / routeへ変更0。よって本FAILをv2 artifactのSafety failureとは扱わないが、full suite PASSとは記録しない。

## 7. Human Operation

Human前提は継続して **非エンジニア / 1 Step = 1 Command / PASS or STOP** とする。複数行PowerShell、continuation prompt、Humanによる技術判断は使用しない。

Gateは混在させない。

1. **Artifact Review（現在）**: identity / hash / Safety EvidenceをHuman + ChatGPTが確認する。Production mutation 0。
2. **Production Placement（別承認）**: exact archiveを既存非公開candidate directory内の新規v2専用pathへ1回だけ配置・hash検証・展開する。既存file時はoverwriteせずSTOP。Application / DB実行なし。
3. **Read-only Execution（さらに別承認）**: 配置済みhashを再検証後、standalone launcherを最大1回実行する。DB SELECTのみ。Migration / DDL / data mutationなし。

各GateのHuman commandは、そのGateが承認された後に検証済みhelperを介する1行だけを提示する。現時点ではPlacement commandもExecution commandも提示・実行しない。

## 8. Production mutation boundary

| Phase | Production connection | Production mutation |
|---|---:|---:|
| Architecture / implementation / build / tests | 0 | 0 |
| Isolated MariaDB / POSIX E2E | 0 | 0 |
| Artifact Review | 0 | 0 |
| Placement（未承認） | 1 planned | private audit artifactの新規配置・展開のみ |
| Read-only Execution（未承認） | 1 planned | 0（DB SELECT / stdout Evidenceのみ） |
| Production Migration / Deploy | 未承認 | 禁止継続 |

## Remaining Gate

v2 artifactはProductionへ未配置であり、Production固有row volume / role distribution / collision / transaction / lock Evidenceはまだ未取得。したがってG2はOPENのままであり、`G2 CLOSE CANDIDATE / G3 READY`ではない。

G1から継続するusable backup / restore readiness、release marker ↔ application binding、`.env` permission hardening、cron / external writerのBlocking Dependencyも消去していない。
