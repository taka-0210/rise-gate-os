# Company OS｜IR-1 Production Release｜G1 Evidence Gap Closure Preflight v001

## Decision

**G1 IN PROGRESS / ONE READ-ONLY PRODUCTION INSPECTION REQUIRED**

R0 Step 1-6 COMPLETE / Evidence Acquisition PASSを固定入力として、G1用の単発read-only helperを実装・検証した。Production connectionおよびProduction mutationはまだ0である。

## R0 Binding

- exact IR-1 candidate: `924af91188cc60d33ff87c91b94ecc1d539566e6`
- R0 execution state SHA-256: `159392dde20356febf20ea744953c7b901696e5c0f7dc5f45d1e2ae914f13b18`
- Application / DB Evidence SHA-256: `4cb2f91d7d12e1083e8edaee41a3a408d0b2577d46fa1c69ad7a978a83c84be9`
- Host Evidence SHA-256: `03f0a69dbeac35747082f06c34e2b492f03cd0bcc8ebd18dc251c81372d0a7ac`
- Step 6 helper SHA-256: `0c32ec4743201ba721cb93d74a6b1b479d6e53369f17ddc47575cc115af418cf`

## Current Gap Classification

| Evidence Gap | Current classification | Basis / next evidence |
|---|---|---|
| Production marker syntax and value | ESTABLISHED | R0 Host Evidenceで40桁SHA形式とsanitized valueを取得済み |
| Marker ↔ deployed Application binding | UNKNOWN | deployed release manifestまたはdeployed Git HEADとの一致を単発inspectionで確認する |
| Marker ↔ exact IR-1 candidate | ESTABLISHED: NOT EQUAL | markerとcandidateは異なる。これは現ProductionがIR-1未適用であることと整合するが、marker自体のApplication bindingは未成立 |
| Canonical backup path | UNKNOWN | File Managerの`public_html/_backup`観測とR0 Host Evidenceのunavailableが不一致。allowlist + bounded discoveryで再確認する |
| Backup inventory / DB backup candidate | UNKNOWN | 内容本文・ファイル名を出さず、件数・容量・期間・拡張子classのみ取得する |
| Usable backup | UNKNOWN | 存在確認だけではusableとしない。integrity検証が別途必要 |
| Restore readiness | UNKNOWN / later release BLOCKER | isolated restore rehearsal未実施。G2分析開始は妨げないがProduction Migration実行前に必須 |
| Scheduler process snapshot | ESTABLISHED | R0時点でscheduler process 0 |
| User crontab / scheduler trigger | UNKNOWN | `crontab -l`の成功可否とentry件数、schedule entry件数のみ取得する |
| External writer code paths | ESTABLISHED | mail jobs、notification delivery、OpenAI transport、scheduled commandsをRepositoryで確認済み |
| External writer Production enablement | UNKNOWN | `.env`値は読まず、cron/processと既存R0 driver Evidenceで確認可能な範囲に限定する |
| `.env` mode | ESTABLISHED: `0604` | R0 Host Evidence。others-readを許すためHardeningが必要 |
| `.env` target mode / ownership plan | BLOCKER before Production mutation | runtime/shared ownershipに応じて`0600`または最小権限group-readableをG4までに決定する。G1では変更しない |

## Read-only Inspection Contract

取得対象は以下に限定する。

- release manifest / deployed Git HEADが存在する場合のSHA identityとmarker一致判定
- exact candidateに固定した3 critical fileのSHA一致数とApplication fingerprint
- bounded `_backup` discoveryのcanonical class、irreversible path hash、mode、件数、容量、時刻範囲、DB/archive candidate件数
- crontab command/list状態とentry count、schedule count、legacy app binding count
- scheduler / queue process count
- `.env`のfile type、mode、Application rootとのowner/group一致

Raw path、cron本文、backup filename/content、`.env`値、Credential、Business Data、Personal Data、raw stdout/stderr、raw exceptionは保存・表示しない。

## Safety Evidence

- helper: `deployment/r0-audit/Invoke-G1EvidenceGapClosure.ps1`
- helper SHA-256: `6d9f89461e917b23b86120c18dc5391e43f33b3301b5980b0b70c0f52b6effa9`
- PowerShell parse: PASS
- helper self-verification: PASS
- R0 Evidence binding: PASS
- local SSH config / registered host-key preconditions: PASS
- remote mutation guard: PASS
- secret output guard: PASS
- retry guard: PASS
- focused regression: 3 tests / 53 assertions PASS
- Production connection attempted during implementation / verification: false
- Production mutation: false

SSHはBatchMode、strict host-key checking、password prompt 0、connection attempt 1、forwarding offで実行する。Remote scriptはread-only commandだけを持ち、upload、create、delete、rename、permission change、DB接続、backup作成、deploy、migrationを行わない。

## Gate Transition

単発inspectionがPASSまたはSTOPした後は再実行せず、Human + ChatGPT Reviewへ戻る。PASS Evidenceを評価して各gapをESTABLISHED / UNKNOWN / UNSUPPORTED / BLOCKERへ確定するまで、G1はCloseしない。

**G2 Migration Safetyは未開始。Production Deploy / MigrationはNO-GOを維持する。**
