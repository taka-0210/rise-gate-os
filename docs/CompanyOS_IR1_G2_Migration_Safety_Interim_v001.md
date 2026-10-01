# Company OS｜IR-1 Production Release｜G2 Migration Safety Interim v001

Date: 2026-10-02 JST

## Current decision

**G2 IN PROGRESS / ONE READ-ONLY PRODUCTION PREFLIGHT REQUIRED**

**Production Deploy / Migration: NO-GO**

G1 PASS後、Production通信0件でexact pending 11 Migrationのstatic auditとisolated MariaDB verificationを実施した。Production固有のrow volume・role distribution・lock snapshotが必要な地点まで自走を完了した。

## Exact binding

- Candidate: `924af91188cc60d33ff87c91b94ecc1d539566e6`
- Production ledger: 83
- Candidate repository migrations: 94
- Exact pending: 11
- Ledger-only: 0
- Candidate Laravel: 12.63.0
- Candidate pending filesとcurrent worktree pending filesの差分: 0

Pending set:

1. `2026_09_19_000001_add_scope_one_contract_to_ai_proposals`
2. `2026_09_19_000001_add_scope_two_account_security`
3. `2026_09_20_000002_add_scope_three_organization_foundation`
4. `2026_09_20_000003_add_scope_four_staff_invitation`
5. `2026_09_20_000004_add_scope_five_membership_lifecycle`
6. `2026_09_20_000005_add_scope_six_owner_onboarding`
7. `2026_09_21_000006_create_scope_seven_business_domains`
8. `2026_09_21_000007_add_direction_and_display_order_to_business_domains`
9. `2026_09_21_000008_add_product_organization_eligibility`
10. `2026_09_24_000001_add_scope_eight_project_action_foundation`
11. `2026_09_25_000001_add_scope_nine_action_execution_foundation`

## Static audit

- Data backfillはScope 3の`organization_users`に限定され、`update()`は4箇所。
- legacy `owner` → `owner`、`admin` → `admin`、`member/viewer` → `member`。
- `membership_status` null → `active`。
- delete data statement: 0。
- Product eligibility migrationはMariaDB CHECKを1件作成する。
- Product eligibilityの`down()`は設計上no-opで、classification/binding evidenceを保持する。
- 他のmigrationの`down()`は新規table/columnをdropするため、traffic開始後のlive DB rollback手段としては使用しない。

## Isolated MariaDB verification

Official MariaDB 10.11.19 Windows archiveをSHA-256 `398ea30e5036010bbebe01d2b1804280424dcc2626e36d8e95155c04d25a0490`へ固定し、専用loopback `127.0.0.1:13371`、専用datadir、event scheduler off、binary logなしで検証した。

Production ledgerと同じ83 migrationを適用したsynthetic baselineを作成し、個人情報・Business Dataを使わず、legacy role 4種と主要FK経路を含むfixtureを投入した。

結果:

- Pending 11 apply: **PASS**（18.207秒、isolated synthetic条件）
- Migration ledger: 83 → 94
- Pending after apply: 0
- Legacy fixture preservation: PASS
- Role mapping: PASS
- FK rows after apply: 258
- CHECK rows after apply: 36
- Index metadata rows after apply: 584
- Product eligibility invalid combination rejection: PASS
- Schema inventory SHA-256: `2561cb784e987a75b6da5d0a85e9bce66b55b58f7ed9880dd816013eda1f30b8`
- Legacy data SHA-256: `f057b747e8253e9120752d945f9bea6121cf6754750aa86dc10526be5f45d7b2`

Rollback / reapply:

- `rollback --step=11`: command completion PASS、ledger 94 → 83
- Legacy data hash preserved: PASS
- Product eligibility 2 tablesはintentional no-opにより保持
- Reapply 11: PASS
- Reapply後schema/data hash: first applyと一致

これはsynthetic baselineでのtechnical compatibilityを証明する。Production runtime予測またはProduction Migration承認ではない。

## Rollback safety conclusion

Productionでの推奨rollbackは、Application release rollbackとadditive schema compatibilityを基本とし、DB rollbackはverified backupからのrestoreが必要な場合だけに限定する。新しいtraffic/dataが入った後に`migrate:rollback --step=11`を実行すると、10 migrationの新規dataをdropし得るため、通常のProduction rollback手順には採用しない。

G1でusable DB backup / restore readinessが未成立である以上、Production Migrationは引き続きBLOCKEDである。

## Required Production read-only preflight

次の1回でのみ、以下のsanitized aggregate Evidenceを取得する必要がある。

- altered tableのapproximate row count / data bytes / index bytes
- `organization_users` exact row count
- legacy role distribution（owner/admin/member/viewer/other-or-nullの件数のみ）
- expected-new columns / tablesのcollision count
- active transaction snapshot
- pending metadata lock snapshot（権限不足時はUNSUPPORTEDを保持）

取得しないもの:

- raw ID
- Personal Data / Business Data本文
- `.env`値、Credential、DB接続情報
- row本文
- raw exception

Safety contract:

- standalone PDO、Laravel bootstrapなし
- SELECTのみ、上限24
- persistent DB write 0、DDL 0、Migration 0
- SSH connection 1、retry 0
- remote file create/upload/change/delete 0
- local sanitized Evidenceのみ保存
- PASS / STOP後の再実行不可

Prepared assets:

- PHP audit: `deployment/r0-audit/g2-migration-preflight.php`
- PHP SHA-256: `878a0399c50b0841e1d9c45cfb6b0f2cae1a297adf3d3bb9046ce7ebc7027cca`
- One-command helper: `deployment/r0-audit/Invoke-G2MigrationSafetyPreflight.ps1`
- Helper SHA-256: `0e31b3e2d00eadf14ca7e278ea7ede21a91bed46c365257185634f8341fd51b3`
- Helper VerifyOnly: PASS
- Local preconditions: PASS
- Production connection during implementation / automated verification: 0
- Production mutation: 0

## Gate transition

Human + ChatGPTが1回のG2 Production read-only preflightを別途承認するまで実行しない。取得後、table volume・role anomalies・schema collision・lock状態をisolated resultと統合し、G2 CLOSE CANDIDATEまたはCorrective Requiredを判定する。
