# Company OS PUX-A RG02 Verification Report

- Status: Done
- Verified at: 2026-09-23 JST
- Baseline HEAD: `e564b58670ecea9cfca0608e6deb17a9eb961e84`
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

論理Caseは16件すべてPASS。case bundleは隔離run内JSONLへ、secretを除外して保存した。既存SQLite Test件数をRG02実績へ流用していない。

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
