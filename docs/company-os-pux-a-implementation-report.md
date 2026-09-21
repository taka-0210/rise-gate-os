# Company OS PUX-A Final Close Report

作成日: 2026-09-21 JST

対象: PUX-A｜利用会社資格・入口UX

状態: **Code Close**

## 1. Close summary

- PUX-A実装Commit: `e092e33b2a82294fa44e1fa7766b6b6d02b4e678`
- 基準HEAD: `1856a6daa9a25270dd4c409bb1ac3ad9312b66c0`
- Branch: `master`
- PUX-A-DC-01〜24: **Done 24 / Conditional 0 / Not Done 0**
- Full Test: **469 tests / 3,879 assertions / 失敗0**
- Frontend Build: **成功**（Vite 7.3.6 / 58 modules / 1.84s）
- Browser: **成功**（Desktop 1280px / mobile 390px / HTTP 5xx 0）
- PUX-A feature flag: **default false**
- 通常local DB: **Migration未適用（Pending）・DB hash不変**
- Production DB / Migration / Deploy: **未実施・未接続**
- PUX-B / Scope 8: **未着手**
- 未解決の実装重大問題: **0**

Code CloseはProduct有効化やProduction Releaseを意味しない。PUX-RG01〜04は別のRelease Gateとして残す。

## 2. できるようになったこと

1. Userごとの利用会社資格を`unstarted` / `single` / `legacy_multi` / `review_required`として、既存MembershipやPermissionと分離して保持できる。
2. 最初の正式利用成功時に、S4 Staff Invitation、S6 Owner Onboarding、System Admin既存Account管理、初回Bootstrapを共通admission境界へ通せる。
3. `single`確定後の別Organization開始を、業務Data作成前または同一transaction rollbackで拒否できる。
4. S4 / S6 / System Adminの同一User競合を、独立SQLite connectionで1社へ収束させ、敗者をfail-closedにできる。
5. 通常single UserはLogin後に自社Homeへ直行し、通常の会社切替を表示しない。
6. 既存legacy multiはcutoff Membership範囲内のactive会社だけを明示選択できる。
7. `review_required`または資格行欠落の既存Userは、承認済みadapterにより既存active Membershipだけを利用できる。新規参加・作成は引き続き拒否される。
8. 未開始、suspended、left、要確認の状態からAccount / Logoutへ到達できる。
9. Client会社化の新規入口をPUX-A有効時にUI / HTTP / Serviceで停止し、既存linkを保持できる。
10. dry-run inventoryと冪等applyで、対象集合・mode・checksum・private Audit Evidenceを作成できる。

## 3. KEEP / REFACTOR / ADD / DEPRECATE

### KEEP

- User / Organization / Organization Membership / Workspace Membershipの既存正本とID
- Organization Role、Position、Groupと既存Permission境界
- S2 credential generation、S5 membership status / access epoch
- S4 Invitationの本人確認、Token、Email Verification、Role非上書き、Group / standard Workspace
- S6の本人確認、同意、Token、Organization / Owner / standard Workspace、最小権限
- Cross-Organization ProjectとAI Key / context / category guard
- Account / Password / Email / Avatar、History relation

### REFACTOR

- Login、会社選択、System Admin退出を共通`ProductOrganizationResolver`へ統合
- S4 / S6 / System Admin / Bootstrap writerを共通`ProductOrganizationAdmission`境界へ接続
- stale company contextを、feature有効時の非safe requestでは409として拒否
- Operation Seederを隔離fixture用途へ限定

### ADD

- `product_account_eligibilities`
- `product_organization_compatibilities`
- Product資格Model / Admission / Resolver / Inventory / rejection型
- `product-organizations:inventory` dry-run / `--apply`
- PUX-A feature flagとfixture guard
- 独立connection concurrency worker、Feature / Operational / Browser acceptance tests
- P0 reportとPUX-A→PUX-B interface

### DEPRECATE

- PUX-A有効時のClientから新Organizationを作る通常UI / HTTP / Service入口

既に`linked_organization_id`があるClient、関連Project、Financial Permissionは削除・変更していない。feature無効時はS1〜S7 Closed Contractを維持する。

## 4. Phase record

### PUX-A-P0｜最新差分・環境監査

- 基準HEAD、正本hash、Branch、worktree、PHP / Laravel / SQLite / Node条件を固定した。
- runtime writer / caller、lock graph、通常local Migration残、C01〜03を監査した。
- fresh baseline 433 tests / 3,602 assertions / 失敗0を取得した。
- S6 adapterとPUX-C02互換adapterのユーザー承認をEvidence化した。
- 結果: C01 / C03非該当、C02は承認済みadapterで解消。P1へ進行。
- Evidence: `docs/company-os-pux-a-p0-audit.md`

### PUX-A-P1｜資格基盤・互換分類

- additive資格2表、DB整合制約、restrict FK、Model relationを追加した。
- active数だけに依存しないinventory、dry-run checksum、冪等apply、private Auditを追加した。
- Migration rollbackはEvidenceをdropしないno-opとした。
- 隔離cloneでMigration、dry-run、apply 2回、rollback、re-migrate、既存Data fingerprint不変を確認した。

### PUX-A-P2｜全入口を接続

- S4 prepare / accept、S6 prepare / complete、System Admin既存Account workspace管理、Bootstrapへ共通admissionを接続した。
- S6 completed retryを同一OrganizationへのNOOPへ収束させた。
- Client会社化の新規入口を停止し、Seederをfixture-onlyにした。
- 初回、同一社NOOP、別社拒否、rollback、Role非上書き、非active拒否をTestした。

### PUX-A-P3｜Login・Session・状態UI

- Login、companies index / switch、Header、company middleware、System Admin退出を共通Resolverへ接続した。
- single直行、互換multi明示選択、状態案内、Account / Logoutを実装した。
- 承認済みC02 adapterにより、review / missing行は既存active Membershipだけを保護した。
- feature無効時の既存Session自動補完を回帰検出後に復元した。

### PUX-A-P4｜検証

- PUX-A focused、独立connection concurrency、S1〜S7関連回帰、Full Testを実行した。
- Vite production buildを実行した。
- 隔離SQLite / local HTTP / ChromeでDesktopと390pxのBrowser acceptanceを実行した。
- 通常local DB hashとMigration Pendingを再確認した。

### PUX-A-P5｜Code Close / handoff

- DC-01〜24をEvidenceへ対応付けた。
- PUX-A→PUX-B interfaceを作成した。
- Release GateをCode Doneと分離した。
- PUX-B、Scope 8、Production作業へ進まず停止する。

## 5. Test / Build / Browser Evidence

| 区分 | Command / 条件 | 結果 |
| --- | --- | --- |
| P0 baseline | `php artisan test` | 433 tests / 3,602 assertions / 0 failures / 144.25s |
| PUX-A core | Foundation + Journey + Operational | 28 tests / 187 assertions / 0 failures / 2.33s |
| Concurrency | 独立SQLite 2接続 / barrier / 8組合せ | 8 tests / 90 assertions / 0 failures / 90.79s |
| 失敗回帰の再確認 | lifecycle 1件 + Project / AI / Client関連 | 25 tests / 149 assertions / 0 failures |
| Full Test | `php artisan test` | **469 tests / 3,879 assertions / 0 failures / 241.99s** |
| Format | Laravel Pint（PUX-A PHP全変更） | passed |
| Diff | `git diff --cached --check` | errorなし |
| Build | VS Code同梱Node v24.18.1 + `vite build` | exit 0 / Vite 7.3.6 / 58 modules / 1.84s |
| Browser | isolated SQLite + Laravel local server + Chrome headless | exit 0 / serverErrors `[]` |

Browserで確認した内容:

- single active UserがLogin後にCompany Homeへ直行する。
- single Userに「会社切替」が表示されず、`/companies`もHomeへ正規化される。
- review Userのactive 2社は明示選択画面へ進む。
- suspended Membershipは選択肢に表示されない。
- 選択した会社のHomeへ進む。
- unstarted Userが390pxで状態案内、Account、Logoutへ到達する。
- Desktop / 390pxとも横overflowなし。
- Laravel HTTP 5xxなし、server stderr 0 bytes。

Browser fixture、SQLite、server log、screenshotは明確なtemp prefixで作成し、目視・自動確認後に削除した。通常local DBは使用していない。

## 6. Migration / Data / rollback Evidence

### 隔離clone

- 元通常local DB: `database/database.sqlite`
- clone前の元DB size: 1,261,568 bytes
- clone前の元DB SHA-256: `A6A178A79F57B009D78418BE885EFBA50F17636F69162E68A76C1C6B28E69DC0`
- PUX-A Migrationをcloneだけへ適用し、Batch 50 / Ranを確認した。
- dry-run: User 3、`single` 2、`legacy_multi` 1、checksum `de69e4c721bbd717505d0c74409d85d2cbc6fba5d20263262b4d4689f6dbade2`
- apply 1回目: eligibility 3、compatibility 2、classification audit 3
- apply 2回目: 同数。追加Auditなし。冪等。
- users 3 / organizations 4 / organization_users 4 / workspaces 5 / workspace_members 5 / projects 17 / project_members 17 / business_domains 3 / ai_access_keys 2について、全column fingerprintが前後一致した。
- rollback stepでは資格2表と3資格行 / 2互換行 / 3 Auditを保持した。
- re-migrateは安全にRanへ戻った。
- cloneは検証後に削除した。

### rollback / cutback

- Migration `down()`は意図的にmetadataをdropしない。
- Code切戻しはfeature flagをfalseにして新規admissionを閉じ、既存認可利用を維持する。
- 旧Codeへ戻して2社目を再許可することは安全な切戻しではない。
- 資格の物理削除、既存Membershipの変換、User / Organization / Workspace / Projectの再作成は行わない。

### 通常local DB最終状態

- size: 1,261,568 bytes
- last write: `2026-09-21T14:48:13+09:00`
- SHA-256: `A6A178A79F57B009D78418BE885EFBA50F17636F69162E68A76C1C6B28E69DC0`
- PUX-A Migration `2026_09_21_000008_add_product_organization_eligibility`: **Pending**
- PUX-A作業前後でhash不変。通常local DBへのMigration / inventory applyは未実施。

## 7. Done conditions

| DC | 判定 | 実装 / Test / Evidence |
| --- | --- | --- |
| DC-01 | Done | P0固定点、全入口、lock graph、Migration残、C01〜03をP0 reportへ記録。 |
| DC-02 | Done | additive 2表、user unique、restrict FK、mode / organization整合trigger / check。Foundation schema / clone fingerprint。 |
| DC-03 | Done | Inventoryが全statusのMembership、Workspace / Project / S6 footprint、不整合を分類。0 / 1 / multi / invited / history / inconsistent fixtures。 |
| DC-04 | Done | dry-run read-only、apply 2回NOOP、既存9領域のfingerprint不変。review / missing既存認可adapter。 |
| DC-05 | Done | Journey single login / switch拒否、Browser single Home / Header。 |
| DC-06 | Done | Journey legacy multi / stopped isolation、Browser review multi明示選択。 |
| DC-07 | Done | Journey unstarted / suspended / left / missing / SA exit、Browser 390px Account / Logout。 |
| DC-08 | Done | Journey S4 atomic / same-org NOOP / second拒否 / prepare後再判定、OrganizationInvitation回帰。 |
| DC-09 | Done | Journey S6 rollback / retry / second拒否、OwnerOnboarding回帰。 |
| DC-10 | Done | Concurrency 8 tests: S4×S4、S6×S6、S4×S6両順、SA×S4、SA×S6、SA×SA、同一社retry。 |
| DC-11 | Done | Journey / OperationalでUI / POST / direct Service拒否、既存link保持。 |
| DC-12 | Done | JourneyでSA first bind、second / inactive / invited / suspended / left拒否。同一社既存管理はFull回帰合格。 |
| DC-13 | Done | Journey Bootstrap atomic single、既存User時の既存Bootstrap回帰。 |
| DC-14 | Done | P0 writer inventory、Seeder guard、Operational fixture test、直接Service負例。 |
| DC-15 | Done | Operationalでbinding不変、S2 / S5 Full回帰で停止・Identity変更を確認。 |
| DC-16 | Done | Operational stale POST 409 / safe GET、Account / lifecycle回帰。flag無効時のClosed Scope互換も確認。 |
| DC-17 | Done | ProjectMember、OrganizationMembershipLifecycle、AI Connection / Proposal full回帰。Product社への自動grantなし。 |
| DC-18 | Done | Account / Password / Email / Avatar / Organization / Invitation / Owner Onboarding / AI Key full回帰。 |
| DC-19 | Done | Foundation / Operationalでprivate Account Audit、reason / entry / public target / JST、一般UI非露出。 |
| DC-20 | Done | no-op rollback、clone rollback / re-migrate、default-off cutback、既存閲覧維持。 |
| DC-21 | Done | Code CloseとRG01 / RG02を明確に分離し、flag default false。Release完了とは記録していない。 |
| DC-22 | Done | 今回のFull Test 469 / 3,879、Build、Desktop / 390px Browser Evidence。 |
| DC-23 | Done | `docs/company-os-pux-a-to-pux-b-interface.md`を作成。PUX-Bコードなし。 |
| DC-24 | Done | DC-01〜23 Evidence、実装Commit、Final Close Report、Release Gate別記。実装重大問題0。 |

## 8. Permission / security conclusion

- Product資格はPermission grantではない。
- `review_required` / missing行adapterは既存active Membershipだけを返す。
- invited / suspended / leftは利用候補にならない。
- admissionはmissing / review / second Organization / cutoff外を拒否する。
- S4 / S6 / System Adminの最終checkは共通資格lock内で実行する。
- single suspended / left / global inactiveでもbindingは解放しない。
- Cross-Organization Projectの既存参加境界は維持し、他社Company Contextを付与しない。
- AuditにEmail、Token、Key、他社Membership一覧を複写しない。

## 9. Pending / Release Gates

### Code Close後に必要

| Gate | 状態 | 通過条件 |
| --- | --- | --- |
| PUX-RG01 | 未実施 | 対象環境ごとの実Data dry-run、cutoff、分類、Evidence、不明件を人が確認してapplyする。 |
| PUX-RG02 | 未実施 | Productionと同じDB engine / version / isolationで独立接続競合を実測する。 |
| PUX-RG03 | 未実施 | PUX-B完了後、A/B統合のRead / Edit / Manage / 390px / Print / Permissionを実利用受入する。 |
| PUX-RG04 | 継続 | Mail、正式法務文書、Production DB、S7本番DB競合、Release Hardening等の既存条件。 |

### 運用上の注意

- RG01 / RG02通過前に`PRODUCT_ORGANIZATION_ADMISSION_ENABLED=true`へしない。
- 通常local / ProductionへMigrationを自動適用しない。
- inventoryは最初にdry-runし、checksumと対象集合を確認してから明示`--apply`する。
- Migration rollbackでmetadataが消えないのは仕様であり、障害ではない。
- 本番engineのCHECK制約、row lock、deadlock retryはRG02で最終確認する。

## 10. 主要ファイルと責務

### Foundation

- `database/migrations/2026_09_21_000008_add_product_organization_eligibility.php`: additive 2表、制約、Evidence保持rollback。
- `app/Models/ProductAccountEligibility.php`: User単位資格。
- `app/Models/ProductOrganizationCompatibility.php`: legacy cutoff Membership参照。
- `app/Services/ProductOrganization/ProductOrganizationAdmission.php`: lock、final check、binding、Audit、bounded retry。
- `app/Services/ProductOrganization/ProductOrganizationResolver.php`: Login / Session向け状態解決。
- `app/Services/ProductOrganization/ProductOrganizationInventory.php`: dry-run / classification / idempotent apply。
- `app/Console/Commands/InventoryProductOrganizations.php`: 運用CLI。
- `config/product_ux.php` / `.env.example`: default-off flagとfixture guard。

### Entry adapters

- `app/Services/Organization/OrganizationInvitationAcceptance.php`: S4 prepare / accept。
- `app/Services/Organization/OwnerOnboardingJourney.php`: S6 early check / final lock / completed retry。
- `app/Http/Controllers/SystemAdmin/MemberController.php`: 既存Account first bind / workspace管理。
- `app/Http/Controllers/Auth/RegisteredUserController.php`: 初回Bootstrap single。
- `app/Services/Company/PromoteClientToCompanyAccount.php`: Client新規会社化拒否。
- `database/seeders/RiseGateOsOperationSeeder.php`: 隔離fixture guard。

### Login / UI

- `app/Http/Controllers/Auth/AuthenticatedSessionController.php`: claim優先後のResolver。
- `app/Http/Controllers/CompanyController.php`: state page / explicit compatible switch。
- `app/Http/Middleware/EnsureCurrentCompany.php`: currentCompany / epoch / stale request。
- `app/Http/Controllers/SystemAdmin/AuthenticatedSessionController.php`: SA退出Resolver。
- `resources/views/companies/index.blade.php`: 選択・状態案内。
- `resources/views/clients/show.blade.php`: Client会社化UI停止と既存link表示。

### Evidence / Test

- `tests/Feature/ProductOrganizationFoundationTest.php`
- `tests/Feature/ProductOrganizationJourneyTest.php`
- `tests/Feature/ProductOrganizationOperationalTest.php`
- `tests/Feature/ProductOrganizationConcurrencyTest.php`
- `tests/Support/product_organization_concurrency_worker.php`
- `tests/Browser/product-organization-acceptance.mjs`
- `tests/Support/product_organization_browser_setup.php`
- `docs/company-os-pux-a-p0-audit.md`
- `docs/company-os-pux-a-to-pux-b-interface.md`

## 11. Guarantees and non-guarantees

### 今回保証したこと

- 実装上の単一利用会社binding、同一社NOOP、別社fail-closed
- 既存multi / review互換のactive Membership限定利用
- S1〜S7 Closed Contract回帰
- SQLite独立接続での競合収束
- additive schema、clone上のData / ID / Relation不変
- Desktop / 390pxの入口Journey

### 今回保証していないこと

- 実Data全Userの分類妥当性
- Production相当DB engineでのlock / isolation / retry
- Production Migration / Deploy
- PUX-A featureの環境有効化
- PUX-B Business Domain Read / Manage UX
- Scope 8

## 12. Final decision

PUX-Aは実装・Test・Evidenceの範囲でCode Closeする。通常local / ProductionのMigrationは行わず、featureはdefault falseのまま維持する。次工程は本Reportと実装Commitを起点にPUX-B P0またはRelease Gateを別途開始するものとし、この作業ではどちらにも着手しない。
