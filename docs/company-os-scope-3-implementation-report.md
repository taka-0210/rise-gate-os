# Company OS Ver.1 Scope 3 Implementation / Final Close Report

- Scope: Organization所属・権限の最小基盤
- Repository: `C:\xampp\htdocs\rise-gate-os`
- Branch: `master`
- P0基準HEAD: `2abb4b2f9bd24c5d9868c5a22abd0611b80b659a`
- Scope 1基準Commit: `15751f290f366aace117cfd976190d581c1b2cae`（P0基準HEADに包含）
- Scope 2実装Commit: `7ee3c28b6dc3c32f0d2e9233cfe3d38aec5b93fa`（P0基準HEADに包含）
- Scope 3実装Commit: `abf1493639ec8eeae336dea8fb863309519bb94b`
- Timezone: `Asia/Tokyo`
- Production deploy: 未実施
- Scope 4: 未着手

## 1. Close判定

Scope 3のコード実装、Migration、Test、ローカルDB適用、Browser受入は完了した。S3-DC-01〜20は **Done 20 / Conditional 0 / Not Done 0** と判定する。

確定Decisionは次のとおり実装した。

- **S3-DE-01**: Organization RoleはOwner / Admin / Member。Role変更はOwnerだけが行い、最後のactive Ownerを0人にする変更を禁止する。PositionはOrganization単位の職務・肩書きであり、Permissionを発生させない。
- **S3-DE-02**: Groupは平坦、複数所属可、Groupなし可。独自Manager Roleを持たず、Owner / Adminが管理する。Group MembershipからResource Permissionを付与せず、GroupとWorkspaceは別概念とする。

Scope 1 / Scope 2はClosedのまま維持した。新Organization Role、Position、Groupから既存Project / Workspace / Financial / AI権限を自動付与せず、旧`role`、`company_role`、`permissions`を保持して互換境界を分離した。

## 2. Scope 3でできるようになったこと

### Organization Membership

- `organization_users`に`organization_role`、`position`、`membership_status`を追加した。
- Organization RoleをOwner / Admin / Memberへ固定した。
- Membership Statusはinvited / active / suspended / leftを保持できる。
- active以外のMembershipは対象OrganizationのWeb / Workspace / AI経路へ入れない。
- 他Organizationのactive Membershipと、Organization非依存の本人Accountは維持する。
- 本人Account画面で自分のOrganization Role、Position、Group、Statusを閲覧できる。

### Role / Position管理

- OwnerだけがOrganization Roleを変更できる。
- Owner / AdminがPositionを変更できる。
- Memberは管理画面へ入れず、直接POSTしても拒否される。
- 別OrganizationのMembership IDは存在有無を漏らさず404境界で拒否する。
- active Userかつactive Membershipである最後のOwnerを降格できない。
- Organization単位のlockと最新Membership再読込により、staleな二つの変更意図でも最後のOwnerを残す。
- Position変更は既存Role / Permission / Workspace / Project / Financial / AIアクセスを変更しない。

### Group管理

- Owner / AdminがGroupの作成、名称変更、空GroupのArchive、Member追加・解除を行える。
- GroupはOrganization内だけで有効で、別OrganizationのGroup / Membershipを関連付けられない。
- 一人が複数Groupへ所属でき、Groupなしも許容する。
- 同一追加・解除の再試行はidempotentに扱う。
- Memberが残るGroupはArchiveできず、空にした後だけArchiveできる。
- Archiveは履歴を残し、物理削除しない。
- Group所属だけではResource Permission、Scope 1 Apply権限、Workspace Membershipを付与しない。

### Audit / Transaction

- Role、Position、Group、Group Membershipの変更をOrganization Auditへ記録する。
- actor、subject、group、event、outcome、before / after、時刻を保持する。
- password / token / secret / credential / authorizationに該当するkeyを再帰的に除外する。
- 正常な業務変更とAudit記録を同一Transactionに置き、Audit失敗時は業務変更もrollbackする。
- 拒否されたRole / Position操作もsanitizedな`rejected` Eventとして記録する。

## 3. KEEP / REFACTOR / ADD / DEPRECATE

| 分類 | 内容 |
|---|---|
| KEEP | User / Organization / Workspace / ProjectのID・Relation、旧`organization_users.role`、`company_role`、`permissions`、既存Financial / Project / Workspace / AI権限、Scope 1 / 2全体 |
| REFACTOR | Organization選択、Workspaceアクセス、AI Key / Proposal Contextの入口にactive Membership確認を追加。User作成・会社化・System Admin writerで新Membership fieldを明示 |
| ADD | Organization Role、Position、Membership Status、Group / Group Membership、Organization Audit、管理Service / UI / Routes、Account read-only表示、受入Tests |
| DEPRECATE | なし。旧Role体系は互換層として保持し、Scope 3では削除・Renameしない |
| RENAME | なし |

## 4. Phase記録

### S3-P0｜Repository・Data・互換境界の固定

#### できたこと

- Repository / Branch / HEAD / origin、AGENTS、開発skill、正本・Evidence資料を確認した。
- Scope 1 / Scope 2 CommitがP0 HEADへ包含されることを確認した。
- DB / Migration / Role値 / writer / Policy / Middleware / Organization選択 / Web / API / AI Key / Job経路を実コードから確認した。
- ローカルDBを匿名集計し、User 3件、Organization 4件、Organization Membership 4件、Workspace 5件、Workspace Membership 5件、Project 17件、Project Membership 17件を確認した。
- Organization Membershipの重複・孤児、Workspace Membershipの重複・孤児、Project Membershipの重複・孤児はいずれも0件だった。
- 4 Organizationはいずれもlegacy ownerを1人持ち、未知のlegacy Roleは実Data上0件だった。

#### Test / 検証

- P0 fresh baseline: **359 tests / 2621 assertions**、成功。
- 過去ReportのTest件数を現在結果として流用していない。
- P0基準worktreeはclean、P0 HEADは`2abb4b2...`だった。

#### 互換判断

- legacy `role` / `company_role`はFinancialその他の既存実効権限に使用中のため、物理統合しない。
- 新`organization_role`を独立fieldにし、既知値だけ`owner→owner`、`admin→admin`、`member/viewer→member`とbackfillする。
- 未知値は`NULL`のままfail-closedとし、自動で管理権限を与えない。
- `company_role`をPositionへ変換しない。

### S3-P1｜Contract・Migration・権限Service

#### できたこと / 変更

- S3-DE-01 / S3-DE-02を実装Contractへ固定した。
- additiveかつ再実行耐性を持つScope 3 Migrationを追加した。
- Organization Role / Position / Status、Group、AuditのModel / Relationを追加した。
- `OrganizationAccess`、`OrganizationAdministration`、`OrganizationAudit`へ認可・更新・監査責務を分離した。
- Organization row lock、actor / target / groupの最新再取得、Transaction、最後のOwner invariantを実装した。

#### Test / Evidence

- `OrganizationFoundationMigrationTest`: schema、backfill、未知Role、legacy field不変、再実行を検証。
- `OrganizationManagementTest`: Owner / Admin / Member matrix、最後のOwner、Position、Group、Audit、Scope 1非grantを検証。

### S3-P2｜Web / Account / Tenant境界

#### できたこと / 変更

- `/company/organization`配下へ管理画面と8 Routeを追加した。
- Company Home / NavigationからOwner / Adminだけに管理導線を表示した。
- `/account`へ本人Membershipのread-only表示を追加した。
- Login後Organization決定、Company / Workspace Middleware、CompanyAccess、AI Key / AI Project Context / AI Proposal Authorizationへactive Membership条件を追加した。
- Profile RequestにRole / Position / Statusを混入しても更新しない境界を維持した。

#### Test / Evidence

- `OrganizationMembershipBoundaryTest`: invited / suspended / left、古いSession、Organization切替、Workspace、AI Key、他Organization、orgless Accountを検証。
- `SystemAdminMemberTest`: 別Workspace追加が既存Organization Roleをdowngradeしないことを検証。
- `WorkspaceFoundationTest`、`ClientCompanyAccountPromotionTest`: writerが新Contractを満たすことを検証。

### S3-P3｜Group / Audit / Atomicity

#### できたこと / 変更

- Group CRUD、複数所属、なし、idempotent add / remove、空Group Archiveを実装した。
- Group / WorkspaceとResource Permissionを分離した。
- Audit sanitize、成功 / 拒否履歴、Audit失敗時のatomic rollbackを実装した。

#### Test / Evidence

- Cross-Organization Group / Membershipの404拒否。
- Group追加後もWorkspace / Project / Financial permissionとScope 1 Applyが増えないことを検証。
- Audit writerを故障させたTestで、Position変更とAuditの双方が残らないことを検証。

### S3-P4｜受入・回帰・Migration rehearsal

#### Test / 検証結果

- Final full suite: **372 tests / 2792 assertions**、123.88秒、全件成功。
- P0 baselineとの差: **+13 tests / +171 assertions**。
- Scope 3変更PHPのPint: 成功。
- `git diff --check`: 成功（既存CRLF→LF warningのみ）。
- Route一覧、PHP syntax、Blade描画: 成功。
- Frontend build: portable Node.js v24.21.0 / npm 11.19.0、Vite 7.3.6、58 modules、成功。
- 実Browser: Chrome headless、1440x1000 / 390x844でLogin、Organization管理、Accountを操作・画像確認。
- 390pxでhorizontal overflowなし。Role / Group操作と本人Membership表示を確認した。
- Browser用server / DB / screenshot / Node / `node_modules` / build生成物は検証後削除した。

#### Migration Clone / Local Evidence

- 適用前に`storage/app/backups/database-before-scope3-20260920-060742.sqlite`へローカルDB backupを保存した。
- 隔離Cloneでup、再実行、downを検証し、cloneは検証後削除した。
- legacy Organization Membership hash: `55dc656c7bc9d820892bb4a787c8ce2ff97ee789964f143c456f0c2124c5b7b7`（前後一致）。
- Workspace Membership hash: `d944770397ef116343895b57d6547435d41af4696ec04c26a74754ad7c5aeb09`（前後一致）。
- Project Membership hash: `da41757a02dd82c015220d522279ee8c019c5e7294c8c81298a793303661ce9e`（前後一致）。
- 件数3 / 4 / 4 / 5 / 5 / 17 / 17とID / Relationを保持し、重複・孤児は0件のままだった。
- 安全性確認後、通常の`artisan migrate`でローカルDBへBatch 44として適用した。Production DBへは適用していない。

### S3-P5｜Close資料・引渡し

- Scope、Decision、Phase、DC、保証、非保証、Migration / rollback、Pendingを本Reportへ固定した。
- 実装Commit `abf1493...`を記録した。
- Production deployとScope 4実装は行っていない。

## 5. S3-DC-01〜20 最終判定

| DC | 判定 | 実装 / Test / Evidence |
|---|---|---|
| S3-DC-01 | Done | P0 HEAD / Branch / origin / AGENTS / Scope 1・2包含、DB集計、writer、fresh 359 testsを記録 |
| S3-DC-02 | Done | S3-DE-01 / S3-DE-02を本ReportとModel定数、Service認可、UI文言へ固定 |
| S3-DC-03 | Done | legacy `role` / `company_role` / `permissions`を保持。既知Roleだけ新fieldへmappingし、未知値はNULLでfail-closed |
| S3-DC-04 | Done | additive / resumable Migration、backup、Clone up / rerun / down、hash・件数・ID・Relation不変 |
| S3-DC-05 | Done | `organization_role`と`position`を別field・別更新Actionへ分離。Position非grant Test |
| S3-DC-06 | Done | OwnerのみRole変更、Owner / AdminのPosition / Group管理、Member / direct POST / cross-org拒否Tests |
| S3-DC-07 | Done | active Owner invariant、Organization lock、fresh再読込、staleな二変更意図でもOwner 1人を保持するTest |
| S3-DC-08 | Done | Group create / rename / add / remove、複数所属 / なし、重複追加・解除再試行のidempotency Tests |
| S3-DC-09 | Done | Group / MembershipのOrganization整合、cross-org 404、Workspace / Project / Financial / Scope 1 Apply非grant Tests |
| S3-DC-10 | Done | Memberが残るGroupのArchive拒否、空GroupのArchive成功、Audit / archived row保持Tests |
| S3-DC-11 | Done | invited / active / suspended / left定数・保存・入口gate。状態変更UIはScope外として未追加 |
| S3-DC-12 | Done | 古いSessionでも各Request時にDB status / roleを再評価。最新row lockと再認可、Scope 2 credential-session維持 |
| S3-DC-13 | Done | mutation＋Audit同一Transaction、Organization serialization、idempotent retry、Audit故障rollback Test |
| S3-DC-14 | Done | Audit key sanitizer、成功 / 拒否履歴、秘密値非保存、cross-org存在非開示Tests |
| S3-DC-15 | Done | Scope 2 Account回帰、orgless Account、本人Membership表示、Profileへのrole / position / status混入無効 |
| S3-DC-16 | Done | Scope 1回帰、Organization Role / GroupだけではAI Proposal Apply権限を得ないTest |
| S3-DC-17 | Done | Chrome 1440x1000 / 390x844操作、Role / Group / Account確認、mobile overflowなし |
| S3-DC-18 | Done | GroupとWorkspaceの別Model / Relation / UI説明、複数Org / Workspaceとorgless経路Tests |
| S3-DC-19 | Done | focused / full 372 tests、Pint、diff check、syntax、Route、Vite build成功 |
| S3-DC-20 | Done | 本Final Close ReportへCommit、Evidence、保証 / 非保証、Pending、切戻しを記録 |

## 6. Test一覧と最終結果

### Scope 3主要Test

- `tests/Feature/OrganizationFoundationMigrationTest.php`
  - additive schema、legacy mapping、未知Role、既存field不変、再実行。
- `tests/Feature/OrganizationManagementTest.php`
  - Role matrix、最後のOwner、stale intent、Position分離、Group lifecycle、cross-org、idempotency、Audit、rollback、Scope 1非grant。
- `tests/Feature/OrganizationMembershipBoundaryTest.php`
  - Membership Status、Web / Workspace / AI Key、他Organization保持、古いSession、Account表示、Profile injection。
- `tests/Feature/SystemAdminMemberTest.php`
  - 既存writer、新Membership field、複数Workspace追加時のOrganization Role不変。
- `tests/Feature/WorkspaceFoundationTest.php`
  - Organization / Workspace bootstrap writerのContract回帰。
- `tests/Feature/ClientCompanyAccountPromotionTest.php`
  - Client会社化writerのContract回帰。

### Final result

- `php artisan test`: **372 passed / 2792 assertions**。
- Scope 3開始前Baselineとの差: **+13 tests / +171 assertions**。
- `npm run build`: 成功。
- Production deploy: 未実施。

## 7. Data / Migration / Rollback / 切戻し

### Upで追加・更新するもの

- `organization_users.organization_role` nullable string(20)。
- `organization_users.position` nullable string(100)。
- `organization_users.membership_status` string(20)、default active。
- Organization / Role / Status index。
- `organization_groups`。
- `organization_group_memberships`。
- `organization_audit_events`。
- 既存legacy Roleの既知値だけを新`organization_role`へbackfillする。

既存User、Organization、Workspace、ProjectのID、legacy `role`、`company_role`、`permissions`、Membership relation、Scope 1 / 2履歴は削除・Renameしない。

### Rollback

Migrationの`down`は新規3 table、index、新規3 columnをdropし、隔離Cloneで成功した。Schemaとしてはreversibleだが、運用開始後のdownはGroup、Group Membership、Organization Audit、Role / Position / Statusを削除するため業務Data削除に該当する。

本番切戻しの推奨順序:

1. Organization管理操作を停止する。
2. DB backupを取得する。
3. 旧Application releaseへ切り戻す。
4. additive Schemaは原則そのまま残す。
5. `down`が必要な場合だけ、Scope 3新規Dataの保全 / 廃棄を明示承認後に実行する。

Scope 3作業ではProduction Migration / rollback / deployを実施していない。

## 8. Permission / Tenant / Securityの保証範囲

### 保証するもの

- Organization Role管理はactive Ownerだけ、Position / Group管理はactive Owner / Adminだけ。
- 最後のactive Ownerを0人にしない。
- active以外のMembershipは対象OrganizationのCompany / Workspace / AIアクセスへ使用できない。
- Target Organization以外のMembership / Groupを変更できない。
- Position / Groupから既存Resource Permissionを生成しない。
- Role / Position / Group変更とAuditを原子的に保存する。
- Auditへ認証秘密を保存しない。
- Role / Statusの変更は古いWeb Sessionでも次Requestから反映する。
- 日時はApplication標準`Asia/Tokyo`で保存・表示する。

### 保証していないもの

- Organization Invitation、Owner Onboarding、Avatar、Offboardingの業務Flow。
- Membership Statusを変更する管理UI / Lifecycle。
- Group階層、Group Manager、Group由来Permission、GroupとWorkspaceの自動同期。
- Position由来Permission。
- legacy `role` / `company_role` / `permissions`の物理統合・廃止。
- Project Roleの再設計。
- Production DB / Production traffic上のMigration・負荷・並行実行検証。
- Production deploy。

## 9. Pending Decision / 次工程への引継ぎ

### 次Scope以降へ持ち越すもの

- Membership Statusの遷移主体・UI・Invitation / Offboardingとの接続。Scope 3では保存と入口gateだけを提供する。
- archived Groupのrestore可否と名称再利用方針。現在はOrganization内の名称uniqueをArchive後も維持する。
- 将来Groupを別Resourceが参照する場合のArchive guard拡張。
- legacy `role` / `company_role` / `permissions`と将来Role体系の最終整理。既存実効権限を暗黙変換しない原則を維持する。
- Production Migration前のDB backup、Clone rehearsal、Release gate、監視。
- Scope 1から継続するVersion粒度、AI Context上限、Action Theme / Action物理名称、Create Undo、Conditional Evidenceは本Scopeで変更していない。

### Scope 3固有で終了したもの

- B01はS3-DE-01として確定・実装済み。
- B02はS3-DE-02として確定・実装済み。
- S3-B03相当のlegacy互換問題は、新field分離・既知値だけのbackfill・未知値fail-closedにより既存実効権限を変えず解消した。
- Scope 3時点Frontend buildは成功した。Scope 1 Close時点の記録を遡及変更はしない。

## 10. Role / Operation Matrix

| 操作 | Owner | Admin | Member |
|---|---:|---:|---:|
| Organization管理画面 | 可 | 可 | 不可 |
| Organization Role変更 | 可 | 不可 | 不可 |
| Position変更 | 可 | 可 | 不可 |
| Group作成・名称変更・Archive | 可 | 可 | 不可 |
| Group Member追加・解除 | 可 | 可 | 不可 |
| 自分の所属情報をAccountで閲覧 | 可 | 可 | 可 |

このMatrixはOrganization管理機能だけに適用する。Project / Workspace / Financial / AIのResource権限は既存の各認可を継続し、新Organization Roleから自動grantしない。

## 11. 主要ファイルと責務

| File / Directory | 責務 |
|---|---|
| `database/migrations/2026_09_20_000002_add_scope_three_organization_foundation.php` | additive schema、legacy Role mapping、Group / Audit、rollback |
| `app/Models/OrganizationUser.php` | Organization Role / Status定数、Position、Group relation、legacy Contract保持 |
| `app/Models/OrganizationGroup.php` | Organization内GroupとArchive状態 |
| `app/Models/OrganizationGroupMembership.php` | GroupとOrganization Membershipの多対多関係 |
| `app/Models/OrganizationAuditEvent.php` | Organization変更Audit |
| `app/Services/Organization/OrganizationAccess.php` | active MembershipとOwner / Admin認可、lock後再認可 |
| `app/Services/Organization/OrganizationAdministration.php` | Role / Position / Group mutation、Transaction、invariant、idempotency |
| `app/Services/Organization/OrganizationAudit.php` | Audit記録と秘密key sanitizer |
| `app/Http/Controllers/OrganizationManagementController.php` | Organization管理HTTP入口と入力Validation |
| `resources/views/organization-management/index.blade.php` | Role / Position / Group管理UI |
| `resources/views/auth/profile.blade.php` | 本人Membershipのread-only表示 |
| `app/Http/Middleware/EnsureCurrentCompany.php` | current Organizationのactive Membership gate |
| `app/Http/Middleware/EnsureCurrentWorkspace.php` | Workspaceとactive Organization Membershipの整合 |
| `app/Http/Middleware/AuthenticateAiAccessKey.php` | AI Key利用時のactive Membership再確認 |
| `app/Services/Company/CompanyAccess.php` | 既存Company権限にactive Membership前提を追加 |
| `app/Services/AiProjectContextGuard.php` | AI Project ContextのOrganization / Workspace境界 |
| `app/Services/AiProposalAuthorization.php` | AI Proposal認可のactive Membership境界 |
| `routes/web.php` | Organization管理8 Route |
| `tests/Feature/OrganizationFoundationMigrationTest.php` | Migration / data compatibility Evidence |
| `tests/Feature/OrganizationManagementTest.php` | Role / Position / Group / Audit / atomicity Evidence |
| `tests/Feature/OrganizationMembershipBoundaryTest.php` | Status / Web / Workspace / AI / Session境界 Evidence |

## 12. 最終引渡し

- Scope 3 implementation: `abf1493639ec8eeae336dea8fb863309519bb94b`。
- Final test: **372 tests / 2792 assertions**。
- DC: **Done 20 / Conditional 0 / Not Done 0**。
- ローカルDB: Scope 3 Migration適用済み、事前backup保持。
- Production: Migration / deployとも未実施。
- Scope 4: 未着手。

Master資料へは、Scope 3実装Commit、Final Close Report Commit、Test件数、DC判定、Production未Deploy、上記Pendingを反映する。
