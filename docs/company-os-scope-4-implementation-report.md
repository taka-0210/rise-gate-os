# Company OS Ver.1 Scope 4 Implementation / Final Close Report

- Scope: Staff Invitation・初回利用
- Repository: `C:\xampp\htdocs\rise-gate-os`
- Branch: `master`
- P0基準HEAD / origin: `ea9f1b5760f3b8f1c05206aba910ae0c8ebb7172`
- Scope 1実装Commit: `15751f290f366aace117cfd976190d581c1b2cae`（P0基準HEADに包含）
- Scope 2実装Commit: `7ee3c28b6dc3c32f0d2e9233cfe3d38aec5b93fa`（P0基準HEADに包含）
- Scope 3実装Commit: `abf1493639ec8eeae336dea8fb863309519bb94b`（P0基準HEADに包含）
- Scope 4実装Commit: `476f0837ab848f057593422c78dee811eb253e4c`
- 実施日 / Timezone: 2026-09-20 / `Asia/Tokyo`
- Production deploy / Production migration: 未実施
- Scope 5: 未着手

## 1. Close判定

Scope 4のContract、Migration、Invitation Journey、標準Workspace、Profile / Avatar、Mail / Queue、DC-25の旧入口停止、Test、Frontend Build、実Browser受入、ローカルMigration rehearsalを完了した。

S4-DC-01〜28は **Done 28 / Conditional 0 / Not Done 0** と判定する。

Scope 1〜3はClosedのまま維持した。既存User / Organization / Workspace / ProjectのID・Relation・Historyを削除・Renameせず、既存Role / Permissionを新Organization Role、Group、標準Workspaceから自動grantしていない。本番Mail配信、Production migration / deploy、Offboarding等はDone条件に含めず、Release Gateまたは次Scopeへ明示的に持ち越す。

## 2. 確定DecisionとDC-25 Decision Evidence

### S4-DE-01｜Invitation actor / Role

- OwnerはOwner / Admin / Memberを招待できる。
- AdminはMemberだけを招待でき、Roleを選択・送信できない。
- 既存Userが同じOrganizationですでにactiveの場合、既存Organization RoleをInvitationで上書きしない。
- privileged Invitationのissue / resend / revokeはactive Ownerだけが行える。
- pending Owner Invitationは最後のactive Owner判定へ数えない。

### S4-DE-02｜標準Workspace

- Organizationごとに明示的な標準Workspaceを持つ。
- Workspace名、最古ID、件数等から推測・backfillしない。
- 未設定Organizationではactive Ownerだけが、空のshared / active Workspaceを明示作成して標準Workspaceへ設定する。
- 作成OwnerをWorkspace owner、新規Invitation受諾者をWorkspace memberとする。
- Group / Organization RoleとWorkspace Permissionを同期・派生させない。

### DC-25｜新規Account作成とWorkspace Membership管理の境界

高見氏の正式判断により、次をScope 4のDecision Evidenceとして固定した。

1. System Adminによる通常Staffの直接Account作成を停止する。
   - 新規Account＋新規Workspace作成を停止。
   - 新規Account＋既存Workspace所属を停止。
   - UIを撤去し、HTTP入口を`410 Gone`で拒否する。
2. System AdminによるStaff本人の恒久Password設定を停止する。
   - UIを撤去し、HTTP入力をprohibitedとして拒否する。
   - 新UserはInvitationで本人がPasswordを設定し、既存UserはScope 2の本人変更 / Recoveryを使用する。
3. 既存AccountのWorkspace Membership管理は維持する。
   - 既存UserのWorkspace追加。
   - Workspace Role更新。
   - Workspace所属解除。
   - 既存Permission / Tenant境界と最後のWorkspace owner保護を維持する。
4. 初回Bootstrap、既存Data、Account情報・停止状態の管理は維持する。

P0監査時点でWorkspace直接追加も退役候補としていた暫定判断は、この正式Decisionで上書きした。実装とTestは上記の最終境界へ一致させた。

## 3. Scope 4でできるようになったこと

### Staff Invitation Journey

- active Owner / Adminが確定Role matrixの範囲でStaff Invitationを発行できる。
- InvitationへOrganization、予定Role、予定Group、Sponsor、期限、Generation、配信状態を保持する。
- Token平文を保存せずhashだけを保存し、URL / Password / credentialをAuditへ残さない。
- resendは旧Generationを即時無効化し、revokeは未受諾Invitationを即時無効化する。
- request idempotency keyとoperation記録により、同一発行 / resend / revokeの再試行を安全に扱う。
- 同一Organization＋Emailのpending Invitationを一意にし、競合時も重複active Invitationを作らない。

### 新規User / 既存Userの初回利用

- 新規UserはInvitation claim後、本人がName / Passwordを設定し、Scope 2のEmail Verificationを完了して受諾する。
- 既存Userは既存Password / Profile / 他Organization所属を維持し、Login後にInvitationを受諾する。
- URL閲覧だけではTokenを消費せず、受諾時にEmail、Generation、期限、Sponsor権限、Role / Group / Workspace状態を再検証する。
- Email collision、Login中Userの不一致、Invitation後のEmail変更、Sponsor降格、期限切れ、revokeをfail-closedで拒否する。
- 成功時だけOrganization Membership、Group Membership、標準Workspace Membership、Audit、Invitation状態を同一Transactionで確定する。
- 再送・二重送信・受諾再試行は既存結果を返し、Relationを重複作成しない。

### Organization / Group / Workspace

- 標準Workspace未設定を明示表示し、active Ownerだけが初期化できる。
- 既存Workspaceを推測選択・転用せず、新しい空Workspaceを作成する。
- pending Invitationが参照するGroupはArchiveできず、revoke後は従来条件でArchiveできる。
- 受諾Userのlegacy Roleは`member`、`company_role=member`、個別`permissions=[]`、標準Workspace Roleは`member`とする。
- Invitation、Organization Role、Group、Workspace Membershipだけで既存Project / Financial / AI Context / Scope 1 Apply権限を付与しない。

### Profile / Avatar

- User ProfileでAvatarをUploadまたはskip / laterできる。
- PNG / JPEG / WebP、最大5MB、最大16MPを受け付け、最大512pxのWebPへ再エンコードする。
- 元画像とEXIFを公開せず、private storageへ保存する。
- Profile、Navigation、Organization Member、Project、Task、Improvement等で共通Avatar componentを利用する。
- Avatar取得は既存Identity可視性へ従い、公開URLやDirectory listingを追加しない。

### Mail / Queue

- Invitation専用Jobは`ShouldBeEncrypted`、`ShouldQueueAfterCommit`を使用する。
- Job実行時に最新Generation、状態、期限、Sponsor認可を再確認し、遅延した旧Jobの送信を抑止する。
- Invitation lifecycleとMail delivery statusを別軸にし、配信失敗だけでInvitationをrevokeしない。
- 開発用array transportで実際にrenderしたInvitation linkをclaimできることをTestした。

### System Admin境界

- 通常Staffの直接新規Account作成フォームを撤去し、両作成モードのHTTP入口を拒否した。
- System Adminの恒久Password設定UI / HTTP入力を停止した。
- Account情報・active状態管理、初回Bootstrap、既存AccountのWorkspace追加 / Role更新 / 解除は維持した。

## 4. KEEP / REFACTOR / ADD / DEPRECATE

| 分類 | 内容 |
|---|---|
| KEEP | Scope 1〜3、User / Organization / Membership / Workspace / ProjectのID・Relation・History、初回Bootstrap、Scope 2 Password / Recovery / Verification、Account情報・停止状態管理、既存AccountのWorkspace Membership管理 |
| REFACTOR | Login / Email変更後のInvitation再開、Organization管理画面、Group Archive guard、Mail dispatch、Profile / Avatar表示、System Admin Account管理の責務境界 |
| ADD | Invitation / operation / group予定Relation、claim / acceptance、明示標準Workspace、Avatar private pipeline、Invitation Mail Job、Journey UI / Route / Service / Test、Scope 4 Migration |
| DEPRECATE | System Adminによる通常Staff新規Account直接作成（新規Workspace / 既存Workspaceの両モード）、System AdminによるStaff恒久Password設定 |
| RENAME | なし。既存物理名称の破壊的変更は行わない |

## 5. Phase記録

### S4-P0｜Repository・Data・互換境界の固定

- Repository / Branch / HEAD / origin、AGENTS、開発skill、正本7点、Scope 1〜3 Reportを確認した。
- P0 HEADは`ea9f1b5...`、worktreeはclean、Scope 1〜3 Commitを包含していた。
- fresh baselineとして **372 tests / 2792 assertions** を再実行した。
- User 3、Organization 4、Organization Membership 4、Workspace 5、Workspace Membership 5、Project 17、Project Membership 17を匿名集計した。
- Membershipの重複・孤児は0。標準Workspace / Avatar / Invitation schemaは存在しなかった。
- Writer / Policy / Middleware / Organization選択 / Web / API / AI Key / Job / Session / Reset Broker / Mail経路を実コードから確認した。
- 既存Role / Permissionを変更せず、legacy member＋empty permissionで新Staffを安全に開始できることを確認した。
- 詳細は`docs/company-os-scope-4-p0-audit.md`へ固定した。

### S4-P1｜Contract・Migration・Service

- S4-DE-01 / S4-DE-02をModel定数、Service認可、UIへ固定した。
- additive Migration、Invitation / Operation Model、issue / resend / revoke / claim / acceptance Serviceを追加した。
- Organization lock、最新row再読込、Transaction、idempotency、Token hash、Generationを実装した。
- 明示標準Workspace ServiceとInvitation中Group Archive guardを追加した。

### S4-P2｜Invitation / Onboarding / Mail

- Owner / Admin向けInvitation UI、受諾Journey、Scope 2 Login / Verification接続を追加した。
- 新規Userと既存Userを分岐し、既存Account情報を上書きしないContractを実装した。
- encrypted after-commit Job、最新Generation / Sponsor再検証、配信結果記録を追加した。
- acceptanceとMembership writerを分離し、業務更新＋Auditのatomic rollbackを実装した。

### S4-P3｜Profile / Avatar / 既存画面統合

- private Avatar upload / skip、再エンコード、Identity accessを追加した。
- 共通Avatar componentを既存主要People表示へ適用した。
- System Admin画面のmobile overflowを修正した。
- Group / Organization / Workspace / Profileの説明と状態表示を追加した。

### S4-P4｜DC-25・回帰・受入・Migration Evidence

- 正式DC-25判断に従い、新規Account直接作成の両モードと恒久Password設定を停止した。
- 既存AccountのWorkspace Membership追加 / Role更新 / 解除を維持し、否定Testと回帰Testを追加した。
- Scope 4 focused、Account / Organization回帰、Full Suite、Pint、syntax、Blade、Route、Frontend Buildを検証した。
- Chrome実BrowserでSystem Admin、新規User、既存User、期限切れ拒否、desktop / mobileを検証した。
- 実local DBのbackup、隔離Clone up / rerun / down / re-up、Data hash / 件数 / 孤児、通常`artisan migrate`を確認した。

### S4-P5｜Final Close / Git引渡し

- 実装Commit `476f083...`を作成した。
- 本ReportにDecision、Phase、DC、保証 / 非保証、Migration / rollback、Pendingを固定した。
- Report Commitと`origin/master` Pushは本Report作成後にGit履歴へ記録する。
- Production deploy / migrationとScope 5実装は行わない。

## 6. S4-DC-01〜28 最終判定

| DC | 判定 | 実装 / Test / Evidence |
|---|---|---|
| S4-DC-01 | Done | P0 HEAD / Branch / origin / AGENTS / 正本 / Scope 1〜3包含、DB匿名集計、fresh 372 testsをP0 Auditへ記録 |
| S4-DC-02 | Done | S4-DE-01 / S4-DE-02とDC-25最終DecisionをModel / Service / UI / Test / 本Reportへ固定 |
| S4-DC-03 | Done | additive / reversible Migration、既存ID・Relation・History非削除、Clone up / rerun / down / re-up、実local適用 |
| S4-DC-04 | Done | Owner / Admin actor matrix、active Membership、cross-org非開示、privileged操作Owner限定Tests |
| S4-DC-05 | Done | Owner / Admin / Member Invitation Role matrix、AdminのRole混入拒否、既存active Role非上書きTests |
| S4-DC-06 | Done | Group予定Relation、同一Organization制約、pending参照中Archive拒否、revoke後Archive Test |
| S4-DC-07 | Done | Token hashのみ保存、平文 / URL / Password / credential非保存、claim GET非消費Tests |
| S4-DC-08 | Done | JST期限、境界時刻、resend世代更新、旧link無効、revoke即時、遅延旧Job抑止Tests |
| S4-DC-09 | Done | operation idempotency、同一Organization＋Email pending一意、issue / resend / revoke再試行Tests |
| S4-DC-10 | Done | Organization lock、最新再読込、unique制約、順序違いの競合操作、SQLite直列化Evidence |
| S4-DC-11 | Done | 新User本人Password設定、hash保存、Verification前accept拒否、Profile入力、Bootstrap非破壊Tests |
| S4-DC-12 | Done | 既存User Login、既存Password / Profile / 他Org Relation保持、Invitationだけを接続するTests |
| S4-DC-13 | Done | Scope 2 signed Email Verification接続、未確認User拒否、確認後再開、Credential generation維持Tests |
| S4-DC-14 | Done | Email collision、Login中User不一致、Invitation後Email変更、別Account claimをfail-closedで拒否 |
| S4-DC-15 | Done | `invited`から成功時だけ`active`へ遷移。pending Ownerをactive Ownerとして数えないTests |
| S4-DC-16 | Done | 既存active MembershipのRole / Position / Group / 他Organization / Passwordを上書きしないTests |
| S4-DC-17 | Done | acceptance＋Membership / Group / Workspace / Auditの同一Transaction、writer故障rollback、retry Tests |
| S4-DC-18 | Done | nullable明示標準Workspace、推測 / backfillなし、active Ownerだけのempty shared Workspace初期化Tests |
| S4-DC-19 | Done | 標準Workspace memberのみ付与し、既存Project / Financial / AI / Scope 1 ApplyをgrantしないTests |
| S4-DC-20 | Done | 未設定状態、Owner初期化、Invitation管理、受諾後導線をOrganization UI / Browserで確認 |
| S4-DC-21 | Done | 新規 / 既存UserのProfile確認、Name更新境界、skip / laterを実装・Test |
| S4-DC-22 | Done | PNG / JPEG / WebP、5MB / 16MP、512px WebP再エンコード、private保存、skip Tests |
| S4-DC-23 | Done | 共通Avatar component、Identity認可、他Tenant / 未認証拒否、主要People表示とBrowser確認 |
| S4-DC-24 | Done | encrypted after-commit Job、最新世代 / Sponsor再確認、delivery状態分離、retry / fail、array mail実link claim Test |
| S4-DC-25 | Done | 新規Account両モードと恒久PasswordをUI撤去・HTTP拒否。Bootstrap / Account管理 / 既存User Workspace管理の維持Tests |
| S4-DC-26 | Done | Account / Organization回帰44 tests、Scope 1〜3を含むFull 387 tests、既存Permission非grant Tests |
| S4-DC-27 | Done | Vite production build、Chrome desktop / mobile、新規 / 既存 / expired Journey、overflowなしを確認 |
| S4-DC-28 | Done | 本Final Close Report、実装Commit、Decision / Test / Data / Pending / 非保証を固定し、Push前最終確認済み |

## 7. Test / Build / Browser 最終結果

### Test

- P0 fresh baseline: **372 tests / 2792 assertions**。
- Scope 4 focused final: **28 tests / 276 assertions**。
  - `OrganizationInvitationTest`: 14 tests。
  - `SystemAdminMemberTest`: 14 tests。
- Account / Organization regression: **44 tests / 458 assertions**。
- Final full suite: **387 tests / 3008 assertions**、150.50秒、全件成功。
- Baseline差: **+15 tests / +216 assertions**。
- Scope 4変更PHPのPint: 成功。
- 全変更PHPの`php -l`: 成功。
- `git diff --check`: 成功（既存CRLF→LF warningのみ）。
- Blade描画 / Route確認: 成功。

### Frontend Build

- System Node.jsを変更・導入していない。
- 隔離portable Node.js v26.9.0 / npm 11.19.1を使用した。
- Node archive SHA-256: `C8AF870B5B3E9789A6CBDB30270E7C212E12D76A7AFA7FC7F21B6B21CC22A71B`（公式SHASUMと一致）。
- `npm install --no-package-lock --ignore-scripts`: 87 packages、0 vulnerabilities。
- `npm run build`: Vite 7.3.6、58 modules、成功。
- `node_modules`、`public/build`、portable Node、browser検証用依存は検証後に削除し、Commitしていない。

### 実Browser

- 隔離SQLite、実Laravel server、Chrome headlessを使用した。
- System Admin画面: 新規Account UIなし、恒久Password UIなし、既存Workspace管理あり。
- 新規User Invitation: claim、本人Password、Email Verification、accept、完了を確認。
- 既存User Invitation: Login、claim、accept、既存Account保持を確認。
- expired Invitation: 拒否を確認。
- 1440x1000 / 390x844で確認し、mobile horizontal overflowは0件。
- browser用DB / log / script / server / dependency / screenshotは検証後に削除した。

## 8. Data / Migration / Rollback / 切戻し

### Migrationで追加するもの

- `organizations.standard_workspace_id` nullable foreign key。
- User Avatarのprivate storage metadata。
- `organization_invitations`。
- Invitation予定Group relation。
- Invitation idempotency operation記録。
- Invitation lifecycle / Mail deliveryを分離するfield / index / constraint。

既存User、Organization、Organization Membership、Workspace、Workspace Membership、Project、Project Membership、Role / Permission、Scope 1〜3履歴は削除・Rename・backfillしない。

### ローカルEvidence

- backup: `storage/app/private/backups/scope4-pre-migrate-20260920-092356.sqlite`
- backup SHA-256: `8104F30BC3951B186190D07C55ED8A55B32545629C06ADAE48483AEA96B52550`
- 隔離Cloneでup → rerun no-op → rollback step 1 → re-upを確認した。
- safe additiveと確認後、実local DBへ通常の`artisan migrate`でBatch 45として適用した。
- 最終件数: User 3、Organization 4、Organization Membership 4、Workspace 5、Workspace Membership 5、Project 17、Project Membership 17。
- Invitation / planned Group / operationは0件、Organization標準Workspace設定は0件。推測backfillしていない。
- 標準Workspace / Invitation relationの孤児は0件。
- P0のUser / Organization / Membership / Workspace / Project hashと既存件数・ID・Relationを保持した。
- 最終確認時にUser Avatar参照1件が存在するが削除・書換えしていない。検証用隔離DBのDataはlocal DBへ混入していない。

### Rollback / 切戻し

Migrationの`down`はScope 4新規table / column / foreign keyを除去でき、隔離Cloneで成功した。ただし運用開始後の`down`はInvitation、標準Workspace参照、Avatar metadataを失うため、業務Data削除に該当する。

本番切戻しは次を推奨する。

1. Invitation発行 / 受諾 / Avatar更新を停止する。
2. DBとprivate Avatar storageのbackupを取得する。
3. 旧Application releaseへ切り戻す。
4. additive Schemaとprivate filesは原則そのまま残す。
5. `down`が必要な場合だけ、Scope 4 Dataの保全 / 廃棄を明示承認後に実行する。

Invitation受諾後の自動Undo、Membership解除、Account無効化、Avatar履歴復元はScope 4で保証しない。受諾済みStaffのOffboardingはScope 5以降の責務である。

## 9. Permission / Tenant / Securityの保証範囲

### 保証するもの

- actor / Sponsor / target OrganizationをRequest時とJob / acceptance時に再認可する。
- privileged Role InvitationはOwnerだけ、AdminはMemberだけを招待できる。
- pending / expired / revoked / superseded Tokenを受諾できない。
- Token平文、Password、Credential、Invitation URLをDB / Auditへ保存しない。
- Organization / Group / Workspaceのcross-tenant relationを拒否する。
- Invitation受諾だけで既存Project、Financial、AI Key / Context、Scope 1 Apply権限を得ない。
- 既存active Organization RoleをInvitationで上書きしない。
- User Avatarをprivate storageに置き、Identity可視性のないUserへ配信しない。
- System Admin経由で通常Staffの新規Account / 恒久Passwordを作れない。
- 既存AccountのWorkspace管理は従来Permission / Tenant境界内だけで行える。

### 保証しないもの

- Production Mail provider、DNS、worker、queue監視、bounce / complaint処理、実顧客Inbox到達。
- Production負荷下の分散DB race / queue障害。SQLiteではlock / unique / idempotent順序を検証した。
- Invitation受諾後の自動Undo / Offboarding / Session一括失効。
- Avatar crop、AI Avatar生成、公開Profile、過去Avatar履歴。
- 既存Staffの標準Workspace一括追加、標準Workspace変更 / Data移動。
- Owner Onboarding、Public Signup、新Organization / Personal Workspace Journey。

## 10. Pending Decision / Release Gate

### 次Scopeへ持ち越すもの

- Scope 5: suspended / left / global inactive、Offboarding、退職者Relation保持、Session / AI Key等の停止連動。
- Scope 6 / G02: Owner Onboarding、Public Signup、新Organization作成、Personal Workspaceの将来採用。
- 既存Staffの標準Workspace一括所属、標準Workspace変更 / 廃止時の運用。
- Group restore、legacy Role物理cleanup、将来Role体系との完全対応。
- Invitation受諾後の明示Undo / Membership解消Journey。

### Release Gate / 運用準備へ持ち越すもの

- RG-01: Production Mail provider、From / Reply-To、公開URL、queue worker、実配送・再送運用。
- RG-05: Terms / Privacyの本文・同意記録。
- Invitation、旧Avatar、Auditのretention / purge policy。
- Production migration backup / rehearsal / deploy / rollback承認。
- Production相当DB / queueの負荷・競合Evidence。

### Scope 4で解消した事項

- Invitation期限7日、claim保持30分。
- Sponsor再認可、Email collision、legacy最低権限値。
- Avatar format / size / pixel / output仕様。
- DC-25の直接Account作成・Password停止と既存Workspace管理維持の境界。
- pending Invitation中Group Archive guard。
- lifecycleとMail delivery statusの分離。

Scope 1から継続中のVersion粒度、AI Context上限、Action Theme / Action物理名称、Create Undo、旧Conditional Evidenceは変更していない。

## 11. 主要ファイルと責務

### Contract / Data

- `database/migrations/2026_09_20_000003_add_scope_four_staff_invitation.php`: Scope 4 additive schema。
- `app/Models/OrganizationInvitation.php`: lifecycle、Role、Token / Generation、Delivery contract。
- `app/Models/OrganizationInvitationOperation.php`: idempotent operation記録。
- `app/Models/Organization.php`, `OrganizationGroup.php`, `OrganizationUser.php`, `User.php`: RelationとScope 4 metadata。
- `config/invitation.php`: expiry / claim等の設定。

### Domain Service / Job

- `app/Services/Organization/OrganizationInvitationService.php`: issue / resend / revoke、actor matrix、idempotency。
- `OrganizationInvitationClaim.php`: claim sessionとToken検証。
- `OrganizationInvitationAcceptance.php`: final validation、Transaction、Audit、state transition。
- `OrganizationInvitationMembershipWriter.php`: Organization / Group / Workspace relation作成。
- `StandardWorkspaceService.php`: 明示標準Workspace初期化。
- `OrganizationInvitationMailer.php`: dispatch境界。
- `app/Jobs/SendOrganizationInvitationMail.php`: encrypted after-commit deliveryと最新状態再確認。
- `app/Services/UserAvatarService.php`, `UserAvatarAccess.php`: private画像処理と閲覧認可。

### HTTP / UI

- `app/Http/Controllers/OrganizationInvitationController.php`: Invitation管理HTTP。
- `InvitationOnboardingController.php`: 新規 / 既存User Journey。
- `UserAvatarController.php`: Avatar upload / response。
- `OrganizationManagementController.php`: 標準Workspace初期化。
- `SystemAdmin/MemberController.php`: DC-25の新規Account / Password拒否と既存Workspace管理維持。
- `routes/web.php`: Invitation / onboarding / Avatar routes。
- `resources/views/invitations/onboarding.blade.php`: 初回利用画面。
- `resources/views/organization-management/partials/staff-invitations.blade.php`: Invitation管理UI。
- `resources/views/components/user-avatar.blade.php`: 共通Avatar表示。
- `resources/views/system-admin/members/*.blade.php`: 旧入口撤去と既存Workspace管理UI。

### Test / Evidence

- `tests/Feature/OrganizationInvitationTest.php`: S4-DE / DC、security、transaction、avatar、mail、非grant。
- `tests/Feature/SystemAdminMemberTest.php`: DC-25否定Test、Bootstrap / Account / Workspace管理回帰。
- `docs/company-os-scope-4-p0-audit.md`: P0 baseline / hash / permission到達表。
- 本File: Scope 4 Final Close Evidence。

## 12. Final Handoff

Scope 4により、既存Organizationへ新しいStaffを迎える正式入口はStaff Invitation Journeyへ一本化された。新Userは本人がPassword設定とEmail Verificationを行い、既存Userは既存Accountを維持して参加できる。受諾時のOrganization / Group / 標準Workspace所属はatomicかつidempotentで、既存Resource Permissionを自動付与しない。

一方、既存AccountのWorkspace Membership管理、初回Bootstrap、Account情報・停止状態管理は維持した。Production Mail / migration / deploy、Offboarding、Owner Onboardingは未実施・未保証であり、上記Pending / Release Gateへ持ち越す。

Scope 4は本ReportをもってFinal Closeとし、Production DeployおよびScope 5へは進まない。
