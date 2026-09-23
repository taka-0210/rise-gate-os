# Company OS PUX-A RG02 Verification Report

- Status: Formal Closed
- Verified at: 2026-09-23 JST
- Formal Close reviewed at: 2026-09-23 JST
- Baseline HEAD: `e564b58670ecea9cfca0608e6deb17a9eb961e84`
- Verified implementation commit: `11e0c71d3565fa2956d223ea5ae2fcdc277437b3`
- Scope: MariaDB固有Deltaのみ。S1-S7 / PUX-A / PUX-BのClosed Contractは維持。

## P0 / Environment

- PHP 8.2.12、Laravel 12.63.0、`pdo_mysql`、`proc_open`を使用。
- 公式MariaDB Windows ZIP 10.11.19を専用processとしてloopback `127.0.0.1:13319`だけで起動。
- archive SHA-256: `398ea30e5036010bbebe01d2b1804280424dcc2626e36d8e95155c04d25a0490`。公式checksumと一致。
- run ID: `rg02-20260923-071207-afff2de1`。run固有schema、datadir、setup / worker / observer資格を使用。
- XAMPP既存MariaDB `3306`、通常local SQLite、Productionには接続していない。
- 外部Mail/APIは使用せず、Mail / Session / Cacheはarray、Queueはsync、架空`example.test` fixtureのみ。

## Tested profile

- MariaDB `10.11.19-MariaDB` / InnoDB / utf8mb4 / utf8mb4_unicode_ci
- Isolation: `REPEATABLE-READ`
- SQL mode: `ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`
- Session timezone: `+09:00`
- Normal lock wait timeout: 10秒、`innodb_rollback_on_timeout=0`
- D04 fault profileだけsession timeoutを1秒へ限定し、application全transaction rollbackを検証。
- Windowsの`mariadb-install-db.exe`はUAC制約で非対話実行できなかったため、専用datadirのsystem tableはXAMPP同梱clean templateをclean shutdown後に使用した。10.11.19 engine / app schema / InnoDB transactionで全caseを実測し、未使用のEvent Schedulerはdisabled。Production profile一致の代替にはしていない。

## P1-P4 / Result

| Case | Result | Evidence |
|---|---|---|
| C01-C07 | PASS | S4 / S6 / SAの全7組合せが異なる会社では1成功・1業務拒否へ収束。binding / active Membership / Auditは各1件。 |
| C08 | PASS | 同一会社の並行requestと後続retryは同一bindingへ収束。Organization増加0、binding Audit 1件。 |
| I01 | PASS | 実S4 accept x 実S6 completeを両先行順で実行。claim、本人確認、同意、標準WS、Auditを含め原子的に収束。 |
| I02 | PASS | 正式SA HTTP route / middleware / 認証 x 実S4 acceptを両先行順で実行。他社grantなし。 |
| I03 | PASS | 同一S6の並行completeは同一結果。audit failpointでは全業務行をrollbackし、後続retryで1組だけ完成。 |
| I04 | PASS | 同一User / WorkspaceへのSA HTTP並行追加は1行へ収束し、既存roleを変更しない。後続retryも1062にしない。 |
| D01 | PASS | 実Migration、InnoDB、charset/collation、strict mode、unique / FK / CHECK、4byte文字を実DMLで確認。 |
| D02 | PASS | eligibility rowの実lock待機と、別User / 別Organizationがglobal lockを受けないことを独立connectionで確認。 |
| D03 | PASS | 逆順row lockで実1213 deadlockを発生。application victimがbounded retryで回復し、成功Audit重複なし。 |
| D04 | PASS | 部分DML後の実1205を発生。outer transaction全体がrollbackされ、有限retry後にmarker / binding / Audit各1件。 |

論理Caseは16件すべてPASS。case bundleは隔離run内JSONLへsecretを除外して生成・確認し、隔離環境とともにcleanupした。永続Evidenceは本Report、Commit済みの専用Harness、Git差分である。既存SQLite Test件数をRG02実績へ流用していない。

## Resolved MariaDB delta

1. MariaDBの識別子64文字上限で、既存Migrationの自動FK / index名が失敗した。列、参照先、削除動作は変えず短い明示名へ変更した。
2. SA Workspace追加の事前unique確認と`attach`間に競合窓があり、実MariaDBで1062になった。`WorkspaceMember::firstOrCreate`へ変更し、並行requestを1行へ収束させた。既存roleは上書きしない。
3. Harnessの本人確認fixtureとWindows timer幅は、業務実装と分離して修正した。

## Verification plan / Evidence reuse

- Reuse: PUX-A Code Close 469 tests / 3,879 assertions、SQLite concurrency 8 tests / 80 assertions、S1-S7 Close Report。
- New MariaDB Evidence: C01-C07 7 tests / 119 assertions、C08 1 test、D01 1 test / 17 assertions、D02 / D04 / I01-I04 6 tests / 111 assertions、D03 focused PASS。
- Related Regression: Product Organization、Invitation、Membership Lifecycle、System Admin Memberの78 tests / 768 assertions、失敗0。
- Full Test / Frontend Build / Browser / Printは、変更がMigration識別名、server-side concurrency、専用HarnessだけでPresentation assetへ影響しないため未実施。
- `git diff --check`、PHP syntax、対象MigrationのSQLite migration regressionを実施。

## Done conditions

| DC | Result |
|---|---|
| DC01 Source / HEAD / Evidence / Delta特定 | Done |
| DC02 MariaDB 10.11専用環境、guard、副作用遮断、profile Evidence | Done |
| DC03 C01-C08 | Done |
| DC04 I01-I04と必須variant | Done |
| DC05 D01 engine / charset / unique / FK / CHECK | Done |
| DC06 D02-D04 lock / 1213 / 1205 / recovery | Done |
| DC07 Atomicity / idempotency / Audit重複防止 | Done |
| DC08 harness異常と業務拒否を分離、未検証0 | Done |
| DC09 cleanup、通常local無変更、Admission false、Production未接続 | Done |
| DC10 Tested ProfileとRelease条件を分離 | Done |

## P5 / Cleanup and release boundary

- 専用schema / credentialをdropし、専用MariaDB processを正常停止後、datadir / downloaded archive / probe / pointerを削除済み。13319 listenerなし、既存3306 listenerは維持。
- `.env` SHA-256は前後とも`6BDD52E83267CDAF4B9E9ABA1EF10D5D74589C144B53F9B43307522AD3AA5409`。
- 通常SQLite SHA-256は前後とも`FCA514AD0A0B73F69E8583DA1C04FDBCEA94FE65ADE6BA41BAB5FC7F8EA172AF`。
- 通常localはSQLite接続、Admissionは`false`のまま。Production未接続・未変更、Deployなし。
- RG02 DoneはAdmission有効化の承認ではない。RG03 / RG04、Mail / 法務、監視、rollback手順等の別Release条件は未達のまま。
- Account分離、HOW、Scope 8へは進んでいない。

## Formal Close Review

- 判定: **RG02 Formal Close可**。RG02-DC01〜10はすべてDoneで、未検証case、未説明のSQL例外、未解決のMariaDB固有不具合はない。
- Done Review: C01〜C08で共通Admissionと同一社retry、I01〜I04でS4 / S6 / SA実経路、D01〜D04でMigration制約、row lock、1213 deadlock、1205 timeout、rollback、bounded retryを確認済み。原子性、idempotency、duplicate防止、Audit整合も対応caseで確認済み。
- 変更分類: FK / index名短縮は列、参照先、制約、削除動作を変えないMariaDB互換修正。`WorkspaceMember::firstOrCreate`は同一User / Workspaceの同時追加を1行へ収束させ、既存roleを上書きしない冪等化。RG02 Test / Supportは隔離検証専用。いずれもPUX-A Closed ContractをMariaDB上で成立させる実装・Evidence修正であり、Product / Permission / Tenant / Security / Data Contractの変更ではない。
- Environment limitation: Event Schedulerは検証対象のMigration、Admission、transaction、row lock、deadlock / timeout / retry経路から使用されない。10.11.19 engine / InnoDB / app schemaで対象Riskを実測済みのため、Event Scheduler disabledとsystem table templateの差はRG02 CloseのBlockerではない。Production完全一致Evidenceには扱わない。
- Additional verification: 新たな実装差分やRiskを検出していないため、Full Test / Build / Browser / MariaDB再構築 / 16case再実行は行っていない。既存EvidenceとCommit `11e0c71d3565fa2956d223ea5ae2fcdc277437b3`のVerified Deltaを再利用した。
- Cleanup review: Formal Close確認時にTEMP直下のRG02専用0-byte同期marker 25件の残存を検出し、対象prefix、解決済みabsolute path、TEMP直下、0 byteを確認して除去した。残存0件。Repository Data、通常local DB、Productionは変更していない。
- Master Update: 不要。今回はProduction Architecture、Product Contract、正式仕様の変更ではなく、確定済みMariaDB 10.11.x構成に対する実証と互換修正である。
- Release boundary: RG02 Formal CloseはAdmission有効化の承認ではない。RG03 / RG04、Mail / 法務、監視、rollback運用、対象環境の最終一致は別途のRelease判断とする。
