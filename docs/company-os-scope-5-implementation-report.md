# Company OS Ver.1 Scope 5 Implementation / Final Close Report

- Scope: Organization所属の停止・終了と一時停止からの復帰
- Repository: `C:\xampp\htdocs\rise-gate-os`
- Branch: `master`
- P0基準HEAD / origin: `88b908ab6541f5bd9a65b2d62ffc96a4e1c1dc5b`
- Scope 1〜4: Closedを維持し、P0基準HEADに包含
- Scope 5実装Commit: `07d6a9e7f834a38a10987018f6d4e080902e2644`
- 実施日 / Timezone: 2026-09-20 / `Asia/Tokyo`
- Production deploy / Production migration: 未実施
- 通常local DBへのScope 5 Migration: 未適用、`Pending`
- Scope 6: 未着手

## 1. Close判定

Scope 5のMembership Lifecycle、Permission、Session epoch、対象Organization限定のAI Key / Invitation失効、Relation / History保持、停止・終了・復帰UX、Cross-Organization Project Access、Migration / rollback、Test、Frontend Build、実Browser受入を完了した。

S5-DC-01〜28は **Done 28 / Conditional 0 / Not Done 0** と判定する。

Scope 1〜4はClosedのまま維持した。Organization停止からglobal Account、Password、Email、Scope 2 credential generation、他Organization利用を変更していない。Organization Role / Position / Group / Workspace / Project Relation、Creator / Owner / Assignee等のHistoryを物理削除せず、Organization RoleやGroupからResource Permissionを新規grantしていない。

本判定は、現行SQLiteでのfail-closedな競合、不変条件、隔離Migration、実Browserを含む実測結果に基づく。Production相当負荷、Production migration / deploy、外部送信済みDataの回収、`left`からの再入社は保証対象外としてRelease Gateまたは次Scopeへ分離する。

## 2. 確定Decision Evidence

### S5-DE-01｜状態遷移と操作主体

- `active → suspended / left`、`suspended → active / left`だけを許可する。
- `left → active`、再入社、自己停止・自己退出はScope 5外とする。
- Ownerは自分以外のOwner / Admin / Memberを操作できる。
- Adminは自分以外のMemberだけを操作できる。
- Memberは操作できない。
- 状態変更でOrganization Roleを変更せず、最後のactive Ownerを必ず残す。

### S5-DE-02｜Relation保持と復帰

- 停止・終了時もRole / Position、Group、Workspace、Project、Creator / Owner / Assignee等のRelation / Historyを物理削除しない。
- `suspended → active`では、復帰時点で現在残っている有効なRelation / Domain Permissionだけを再利用する。
- 停止中に解除された所属、過去Snapshot、標準Workspace、Group / Projectを自動復元・再付与しない。

### S5-DE-03｜Credential / Invitation失効

- 停止・終了時、対象User×対象OrganizationのAI Access KeyだけをRevokeする。
- 同じOrganizationで対象UserがSponsorのpending Invitationと、対象User本人へ安全に紐づくpending Invitationだけを失効する。
- 復帰しても旧Key、旧Invitation、旧Token、旧Jobを復活させない。
- 他OrganizationのKey / Invitation / Membership / Sessionへ影響させない。

### S5-DE-04｜Cross-Organization Project Access

高見氏の追加Decisionとして2026-09-20（Asia/Tokyo）に確定し、P0 Audit、Policy、Test、本Reportへ固定した。

- Project所有Organizationのactive Membershipを一律必須にしない。
- 認可主体は、UserのactiveなProject Membershipに保存された`workspace_id`、そのWorkspace Membership、Workspaceのactive状態、そのWorkspaceが属するOrganizationのactive Membership、既存`permission_level`とする。
- 条件を満たす既存Cross-Organization共同参加をProject / Roadmap / Improvement / Taskで維持する。
- Project Member自身のWorkspaceが属するOrganization Membershipが`suspended / left`なら、Project Membershipを保持したまま、古いSession / Context / URLを含めて拒否する。
- Project所有OrganizationへのMembership追加、Organization RoleからProject Permissionへの自動grant、既存Project Permission Level変更は行わない。

## 3. Scope 5でできるようになったこと

### Organization Membership Lifecycle

- Organization管理画面から、理由必須で所属を一時停止、終了、再開できる。
- actor / target matrix、自己操作拒否、cross-organization拒否、`left`終端、global inactive復帰拒否をServiceとHTTPの両方で保証する。
- Organization row lock、最新Membership再読込、expected version、request ID / payload hash、限定Transaction retryにより、stale・二重送信・ABA再試行をfail-closedで処理する。
- status、access epoch、lifecycle version、対象Key / Invitation失効、operation、Auditを同じDB Transactionで確定する。

### Session / Web / API / AI

- current Organizationと一緒にMembership `access_epoch`をSessionへ保存する。
- 停止・再開でepochを増加し、古いCookie / tab / Contextは選択し直すまで利用できない。
- 停止Orgのcurrent company / workspaceだけを除去し、activeな他Organizationへ安全に退避する。
- API / MCPは既存active Organization Membership / Workspace Membership / AI Key gateを再利用する。
- Console経由のAI Key発行もglobal activeかつ対象Organization active Membershipを要求する。

### Relation / History / Account

- Group / Workspace / Project Membershipと業務Dataの人物Relationを保持する。
- 停止中も管理者による既存Workspace Role更新・所属解除、Group解除を可能にする。
- 停止・終了中のUserへの新しいWorkspace / Group grantは拒否する。
- 復帰時に解除済Relationを戻さず、残っているRelationだけが再び有効になる。
- Organization無所属状態でもLogin、Account、Profile、所属履歴、Logoutへ到達できる。
- Profile / private Avatarを保持し、既存Identity可視性がない表示ではfallbackを使用する。

### Cross-Organization Project

- Project所有Organizationとは別のOrganization / Workspaceから参加している既存Project Memberを維持する。
- Project Member自身のWorkspace経路がactiveなら既存permission levelで利用できる。
- その経路のOrganization Membership停止中はProject / Roadmap / Improvement / Taskを拒否する。
- 復帰後も古いepoch Sessionを拒否し、freshなOrganization選択後だけ再利用できる。

## 4. KEEP / REFACTOR / ADD / DEPRECATE

| 分類 | 内容 |
|---|---|
| KEEP | User / Organization / Workspace / ProjectのID・Relation・History、Scope 1 Proposal Contract、Scope 2 Account / Credential、Scope 3 Role / Group、Scope 4 Invitation / Avatar、既存Project Permission Level、既存Cross-Organization共同参加、既存Workspace Membership管理 |
| REFACTOR | Organization access / management、current company Session、System Admin global Account writer、Project系Policy、AI Key発行、Group / Workspace解除、Avatar表示境界 |
| ADD | Membership Lifecycle Service / Operation、`access_epoch`、`lifecycle_version`、状態変更metadata、停止・終了・復帰UI、Lifecycle Route、S5-DE-04回帰Test、Scope 5 additive Migration |
| DEPRECATE | Scope 5で新たに廃止した正式機能なし。Scope 4で停止済みの直接Staff Account作成と恒久Password設定は復活させていない |
| RENAME | なし。既存物理名、ID、Role、Permissionを破壊的変更していない |

## 5. Phase記録

### S5-P0｜Repository・Data・互換境界の固定

- Repository / Branch / HEAD / origin、AGENTS、開発skill、正本7点、Scope 1〜4 Close Reportを確認した。
- P0 HEAD / originは`88b908a...`、worktreeはclean、Scope 1〜4 Commitを包含していた。
- fresh baselineとして **387 tests / 3008 assertions** を隔離Test DBで再実行した。
- User、Organization、Membership、Workspace、Project、AI Key、Invitation、Auditを匿名集計し、ID / Relation hash、重複、孤児、active Ownerを確認した。
- Auth / Session / Reset / Mail / Organization選択 / Web / API / MCP / AI / Job / Role・状態writerを実コードから確認した。
- S5-C01 / C02等の重大互換問題は発生しなかった。
- 詳細は`docs/company-os-scope-5-p0-audit.md`へ固定した。

### S5-P1｜Contract・Service・最小Schema

- S5-DE-01〜03の状態遷移、actor matrix、last Owner、Relation保持、Credential失効をLifecycle Serviceへ固定した。
- expected version、request idempotency、payload hash、operation result、Audit、Transaction rollbackを実装した。
- `organization_users`へepoch / version / 状態変更metadataをadditiveに追加し、Lifecycle Operation tableを追加した。
- 追加Decision S5-DE-04に従いProject系PolicyをProject Member自身のWorkspace経路で評価するよう補強した。

### S5-P2｜停止境界・Key・Invitation

- Session epochをLogin、Company選択、Bootstrap、Invitation受諾、Client会社化へ接続した。
- 対象Organization限定のAI Key Revoke、Invitation派生失効、旧Job / Token抑止を実装した。
- global Account、他Organization、共有Provider secret、既存Relationを変更しない対照Testを追加した。
- Invitation issue / resend / acceptanceとLifecycleが同じOrganization lock・最新認可に従うことを確認した。

### S5-P3｜管理・本人UXと履歴

- Organization管理画面へ一時停止 / 終了 / 復帰、理由、影響、不可逆境界、復帰警告を追加した。
- Account画面へ一時停止 / 所属終了状態を表示した。
- 無所属UserのAccount退避導線、他Organization自動退避、private Avatar fallbackを追加した。
- Desktop / mobileで管理者、本人、二社、拒否、復帰Journeyを確認した。

### S5-P4｜回帰・競合・Migration Evidence

- Scope 5 focused、Account / Organization / Workspace / AI回帰、Scope 1〜4を含むFull Suiteを実行した。
- SQLite二接続のbarrier同時実行で、2名のactive Ownerが互いを停止する競合を実測した。1操作成功、競合側はDB lockでfail-closed、最終active Ownerは1名だった。
- local DBの隔離CloneでMigration up / rerun / down / re-up、logical hash、既存row保持を検証した。
- Frontend production buildと実Chrome browser受入を完了した。

### S5-P5｜Final Close / Git引渡し

- 実装Commit `07d6a9e...`を作成した。
- 本ReportへDecision、Phase、DC、保証 / 非保証、Migration / rollback、Pendingを固定した。
- 本Report Commitと`origin/master` Pushは本Report作成後にGit履歴へ記録する。
- Production migration / deployおよびScope 6実装は行わない。

## 6. S5-DC-01〜28 最終判定

| DC | 判定 | 実装 / Test / Evidence |
|---|---|---|
| S5-DC-01 | Done | P0 HEAD / Branch / origin / 正本 / Scope 1〜4包含、schema / writer / route、匿名Data inventory、fresh baselineをP0 Auditへ記録 |
| S5-DC-02 | Done | S5-DE-01〜03の原文・日付・Evidenceと資料同期を確認し、追加S5-DE-04をAudit / Policy / Test / Reportへ記録 |
| S5-DC-03 | Done | 許可遷移だけをServiceで実装し、`invited` / `left` / 自己操作を拒否する全遷移Test |
| S5-DC-04 | Done | Owner / Admin / Member×target、self、cross-org、stale RoleをService / HTTP / UIで検証 |
| S5-DC-05 | Done | last active Owner、pending Owner非算入、global inactive writer、Role writer回帰。SQLite二接続barrierでも最終active Owner 1名を維持 |
| S5-DC-06 | Done | status / epoch / Key / Invitation / operation / Auditを同一Transaction化し、Audit故障注入で全更新rollback |
| S5-DC-07 | Done | 同一requestは同じoperation、別payload / stale versionを拒否し、suspend→resume後の古いretryで状態を上書きしないTest |
| S5-DC-08 | Done | Org停止前後でEmail / Password / `is_active` / credential generation / remember tokenを比較し、本人Account利用を維持 |
| S5-DC-09 | Done | 同一User二社でA停止後もB Session / Membership / Workspace / Key / Invitation / Relationを維持 |
| S5-DC-10 | Done | A Home / switch / deep link /再Login /古いepoch / API Keyを停止commit後に拒否 |
| S5-DC-11 | Done | Webは毎Request、Invitation Jobは実行時に最新状態 / generation / sponsor認可を再確認。旧epoch / old Jobの復帰後再利用を拒否 |
| S5-DC-12 | Done | User×対象Org KeyだけRevokeし、他User Key / 他Org Key / Provider secretを維持。発行入口もactive Membershipを要求 |
| S5-DC-13 | Done | suspended復帰後も旧Key / Invitation / Token / Jobを失効状態のまま保持し、再発行が必要なことをUI表示 |
| S5-DC-14 | Done | Sponsor一致、claimed User / Membership、現在のverified Emailに限定し、stale Email / 別Org / 無関係招待を保持 |
| S5-DC-15 | Done | Lifecycle / issue / resend / acceptが同じOrg lockと最新状態を使用。停止後accept、旧generation Job、復帰後旧Tokenを拒否 |
| S5-DC-16 | Done | Owner / Admin matrixで`suspended`だけ再開し、global inactiveを拒否。Role据置、現存Relationだけ再利用し警告表示 |
| S5-DC-17 | Done | Role / Position / Group / Workspace / Project relation保持、停止中の解除可、新規grant拒否、復帰時非復元Test |
| S5-DC-18 | Done | Project / Improvement / TaskのOwner / proposer / assignee / creatorとProject Membershipを保持し、孤児化させないTest |
| S5-DC-19 | Done | Profile / Avatar file / metadata保持、本人可視、既存Identity不可視、fallback表示の対照Test |
| S5-DC-20 | Done | S4 DC25を回帰し、直接Account / Password停止を維持。既存Workspace管理は維持し、非active所属への追加を拒否 |
| S5-DC-21 | Done | success / rejected / stale conflictをAuditし、actor / target / Org / before / after / reason / 件数 / JST時刻を記録。秘密値非混入とAudit失敗rollback |
| S5-DC-22 | Done | 停止 / 終了 / 復帰の差、対象Org限定、Relation再利用、Key / Invitation再発行、理由必須、request IDをUI / Browserで確認 |
| S5-DC-23 | Done | 全Org非activeでもLogin→会社選択→Account→Logoutがloopせず、他にactive Orgがあれば退避して利用可能 |
| S5-DC-24 | Done | legacy role / company_role / permissionsを保持し、Lifecycle / Org Role / GroupからProject / Workspace / Financial / AI permissionをgrantしない |
| S5-DC-25 | Done | 最小additive Migration。推測backfill / DELETE / renameなし。Clone up / rerun / down / re-upとlogical hash一致 |
| S5-DC-26 | Done | Scope 5 focused、Account / Organization回帰、Scope 1〜4を含むFull **401 tests / 3223 assertions**、失敗0 |
| S5-DC-27 | Done | Vite production build成功。Chrome 1440x1000 / 390x844で停止、二社、Cross-Org許可 / 拒否、stale復帰拒否、fresh復帰を確認 |
| S5-DC-28 | Done | 本Final Close Report、実装 / Report Commit、保証 / 非保証、Pending / Release Gateを固定し、Production / Scope 6を分離 |

## 7. Test / Build / Browser / Concurrency 最終結果

### Test

- P0 fresh baseline: **387 tests / 3008 assertions**。
- Scope 5 Lifecycle focused final: **14 tests / 215 assertions**。
- Cross-Organizationを含む重点回帰: **26 tests / 268 assertions**。
- Account / Organization / Workspace / AI回帰: **91 tests / 779 assertions**。
- Final Full Suite: **401 tests / 3223 assertions**、128.70秒、全件成功。
- Baseline差: **+14 tests / +215 assertions**。
- Scope 5対象25 PHP filesのPint `--test`: 成功。
- 全対象PHPの`php -l`: 成功。
- `git diff --check`: 成功。
- JST: `Asia/Tokyo`をTest / Audit / UIで確認。

### Frontend Build

- System Node.jsを変更・導入していない。
- 隔離portable Node.js v26.9.0 / npm 11.19.1を使用した。
- Node archive SHA-256: `C8AF870B5B3E9789A6CBDB30270E7C212E12D76A7AFA7FC7F21B6B21CC22A71B`。
- `npm install --no-package-lock --ignore-scripts`: 88 packages、0 vulnerabilities。
- `npm run build`: Vite 7.3.6、58 modules、成功。
- `node_modules`、`public/build`、portable Nodeはignored / 一時検証物でありCommitしていない。安全審査によりrecursive cleanupは実行されず、local環境に残っている。

### 実Browser

- 隔離SQLite、実Laravel server、Chrome headlessを使用した。
- viewport: 1440x1000 / 390x844。
- A社停止後のA拒否、B社維持、B社Workspace経由Cross-Organization Project許可を確認した。
- B社停止後のCross-Organization Project拒否を確認した。
- A / B再開後も古いepoch Sessionを拒否し、fresh選択後だけ利用可能なことを確認した。
- Scope 5管理 / Account画面のmobile horizontal overflowは0。
- Browser用DB / log / script / server / resultは削除した。

### SQLite同時実行

- 隔離SQLite fileへ2つの実PHP processを接続し、barrier後に2名のactive Ownerが互いを同時停止した。
- 結果は1操作成功、1操作はSQLite DB lockでfail-closed。
- 最終状態は`suspended / active`、active Owner countは1。
- Transaction retryは3回に限定し、二重適用しない。
- SQLiteのwrite serializationを、分散DBやProduction負荷の一般保証とは扱わない。

## 8. Data / Migration / Rollback / 切戻し

### Migrationで追加するもの

`organization_users`へ次を追加する。

- `access_epoch` default 1。
- `lifecycle_version` default 1。
- `status_changed_at` nullable。
- `status_changed_by_user_id` nullable User FK / null on delete。
- `status_change_reason` nullable 500文字。

`organization_membership_lifecycle_operations`を追加し、Organization単位のrequest ID、payload hash、expected / result version、result status / epoch、失効件数を保持する。

既存User、Organization Membership、Workspace / Group / Project Relation、Role / Permission、Creator / Owner / Assignee、Scope 1〜4履歴は削除・Rename・推測補正しない。

### Migration Evidence

- 実local DBのbackupを取得してから、隔離CloneだけへScope 5 Migrationを適用した。
- Clone: up成功、rerunは`Nothing to migrate`、down成功、re-up成功。
- 既存Membership 4 rowsを全工程で保持し、default epoch / versionは1。
- Scope 5 operation tableと5 columnsはup時のみ存在し、downで除去できた。
- 匿名logical hashはbefore / up / down / re-upで一致した。
- 実local DBへScope 5 Migrationは適用しておらず、最終`migrate:status`は`Pending`。
- Production DBへMigrationは適用していない。

### Rollback / 切戻し

Migrationの`down`は新規Operation tableと5 columnsを除去でき、隔離Cloneで確認済み。ただし運用開始後の`down`はLifecycle operation / reason / epoch / versionを失うため、業務Data削除に該当する。

本番切戻しは次を推奨する。

1. Lifecycle操作入口を停止する。
2. DB backupを取得する。
3. 旧Application releaseへ切り戻す。
4. additive Schemaは原則残す。
5. `down`が必要な場合だけ、Scope 5履歴の保全 / 廃棄を明示承認後に実行する。

Scope 5は一時停止の明示復帰を提供するが、所属終了のUndo、再入社、過去Relation snapshot復元、停止中に解除されたRelationの復元は保証しない。

## 9. Permission / Tenant / Securityの保証範囲

### 保証するもの

- actor / targetを同じOrganizationの最新active Membershipで再認可する。
- Owner / Admin / Member matrix、自己操作禁止、last active Ownerを維持する。
- 停止OrgのWeb / deep link / old Session / API / MCP / AI Keyをfail-closedで拒否する。
- A社停止からB社のactive Membership / Session / Key / Invitation / Relationを変更しない。
- status / epoch / Key / Invitation / Audit / operationをatomicに確定する。
- stale version、request replay、payload mismatch、resume後の古いstop retryを拒否する。
- 停止・終了でglobal Account / Password / Email / credential generationを変更しない。
- Relation / Historyを物理削除せず、復帰時に残存Permissionだけを再利用する。
- Cross-Organization Project accessをProject Member自身のWorkspace経路で評価し、Project所有Orgへの所属を誤要求しない。
- Organization Role / Group / LifecycleからResource Permissionを新規grantしない。
- AuditへPassword、Token、Invitation URL、AI Key平文、Emailを混入させない。

### 保証しないもの

- Production migration / deploy、Production負荷・複数nodeでの競合、DB failover。
- SQLite lock競合時の必ず成功する自動再試行。競合はfail-closedであり、操作再試行が必要な場合がある。
- 停止commit前に外部へ送信・download済みのData回収。
- Provider側ですでに実行済みの外部AI処理の取消。
- `left`からの再入社 / 再招待、自己退出、退職時の一括引継ぎ。
- 停止中に解除されたGroup / Workspace / Project Relationの自動復元。
- 既存Project詳細画面全体のmobile layout再設計。Scope 5画面の受入範囲だけを確認した。

## 10. Pending Decision / Release Gate

### 次Scopeへ持ち越すもの

- Scope 6: Owner Onboarding、Public Signup、新Organization開始、Personal Workspace判断。
- FR-253: 退職時のProject / Action / Document / Client等の一括引継ぎJourney。
- `left`からの再入社 / 再招待Contract。
- 自己退出、休職期間、予定停止 / 自動復帰。
- 既存Project詳細画面のmobile layout debt。

### Release Gate / 運用準備へ持ち越すもの

- Production DB backup、Migration rehearsal、deploy / rollback承認。
- Production相当DB / queue / workerでの負荷・競合Evidence。
- SQLite lock時の運用再試行案内と監視。
- Lifecycle / Organization Auditのretention / purge policy。
- 外部AI Provider / Mail送信済み処理の運用上の扱い。
- local ignored検証物`node_modules`、`public/build`、portable Node tempの明示cleanup。

### Scope 5で解消した事項

- S5-DE-01〜03の状態 / actor / Relation / Credential境界。
- 追加S5-DE-04のCross-Organization Project Access境界。
- last Owner、global inactive writer、Workspace / Group解除責務。
- Session ABA、旧Key / Invitation / Jobの復活防止。
- 無所属UserのAccount / Logout導線。
- Scope 5 Migrationのadditive / rollback / Data保持Evidence。

Scope 1から継続するVersion粒度、AI Context上限、Action Theme / Action物理名称、Create Undo、旧Conditional Evidenceは変更していない。

## 11. 主要ファイルと責務

### Contract / Data

- `database/migrations/2026_09_20_000004_add_scope_five_membership_lifecycle.php`: Scope 5 additive schema / rollback。
- `app/Models/OrganizationMembershipLifecycleOperation.php`: idempotent operation result / Evidence。
- `app/Models/OrganizationUser.php`: status、epoch、version、状態変更metadata。
- `app/Models/OrganizationAuditEvent.php`: conflict outcome。
- `app/Models/Organization.php`, `app/Models/User.php`: Lifecycle pivot metadata。

### Domain / Authorization

- `app/Services/Organization/OrganizationMembershipLifecycle.php`: 状態遷移、actor matrix、lock、stale、idempotency、Key / Invitation失効、Audit、Transaction。
- `app/Services/Organization/OrganizationSessionContext.php`: company / workspace / epoch Sessionの選択とclear。
- `app/Http/Middleware/EnsureCurrentCompany.php`: active Membershipとepoch再認可、他Org退避。
- `app/Policies/ProjectPolicy.php`, `RoadmapPolicy.php`, `ImprovementPolicy.php`, `TaskPolicy.php`: S5-DE-04 Cross-Organization認可。
- `app/Policies/ClientPolicy.php`: Workspace active gate。
- `app/Console/Commands/CreateAiAccessKey.php`: 非active OrgへのKey発行拒否。

### HTTP / UI / Writer

- `app/Http/Controllers/OrganizationManagementController.php`: Lifecycle HTTP入口とUI action生成。
- `app/Http/Controllers/SystemAdmin/MemberController.php`: global last Owner guard、停止中の既存Relation管理、新規grant拒否。
- Login / Registration / Company / Invitation / Client promotion Controllers: epoch-aware Session選択。
- `routes/web.php`: Lifecycle PATCH Route。
- `resources/views/organization-management/partials/membership-lifecycle.blade.php`: 停止 / 終了 / 復帰UI。
- Organization管理 / Account / Company選択 / Avatar component: 状態、退避、fallback表示。

### Test / Evidence

- `tests/Feature/OrganizationMembershipLifecycleTest.php`: S5-DE / DC、Migration、Permission、atomicity、二社、Key / Invitation、Relation、UX、Avatar、Cross-Organization。
- `tests/Feature/SystemAdminMemberTest.php`: Scope 4 DC25、既存Workspace Membership管理回帰。
- `docs/company-os-scope-5-p0-audit.md`: P0 baseline / hash / writer / route / C01 / C02 / S5-DE-04。
- 本File: Scope 5 Final Close Evidence。

## 12. Final Handoff

Scope 5により、Userのglobal Accountと他Organization利用を維持したまま、特定Organizationだけを一時停止・所属終了できる。復帰は`suspended`だけに限定され、残っているRelation / Domain Permissionだけが再利用される。停止時のSession、AI Key、Invitation、旧Jobは対象Organization単位で失効し、復帰後も古いcredentialは復活しない。

Cross-Organization Project参加はProject所有Organizationへの所属を要求せず、Project Member自身のWorkspace / Organization Membershipを正本として維持する。停止された経路は古いSession / URLを含めて拒否し、Project MembershipとHistoryは保持する。

Production migration / deploy、本番相当負荷Evidence、再入社、一括引継ぎ、Scope 6は未実施・未保証である。Scope 5は本ReportをもってFinal Closeとし、ProductionおよびScope 6へは進まない。
