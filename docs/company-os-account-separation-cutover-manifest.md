# Company OS｜Account分離 Cutover Manifest v2

版：AS-G04通常local実Cutover反映版｜2026-09-23 JST

状態：**Phase A〜E完了 / 通常local Account分離 Formal Closed**

## 1. 目的と固定対象

旧User ID `1`をRise Gateに残し、株式会社ライズアップへ本人管理の新Accountを作成する。Organization / Workspace / Project等のBusiness Dataは複製せず、現在責任だけを切り替える。過去HistoryのUser参照は変更しない。

| 項目 | 固定値 |
| --- | --- |
| KEEP Organization | Rise Gate / ID `1` / 旧Membership ID `1` |
| TARGET Organization | 株式会社ライズアップ / ID `4` / 旧Membership ID `5` |
| 旧Account | User ID `1`。現Login Email・global active・System Adminを維持 |
| 新Account Email | `takami@pro-chubo.com` |
| Standard Workspace | 経営WS / Workspace ID `5`。新規作成しない |
| 新Account権限 | Organization Owner / Workspace Owner / legacy role owner / company_role owner / System Admin=false |
| 旧資格の最終状態 | Rise Gate（Org 1）`single` |
| 新資格の最終状態 | 株式会社ライズアップ（Org 4）`single` |
| History / Personal | 移管・付替えなし |

### Manifest v2実行ID

| 対象 | 実ID / 固定値 |
| --- | --- |
| Case | `AS-G04-20260923-LOCAL` |
| Repository HEAD | `8095bd98a180d1f5bcfacd9fa12acad6ff35062c` |
| 新User | ID `5` / `高見 昌也` / `takami@pro-chubo.com` |
| Invitation | ID `1` / generation `2` / accepted |
| 新Organization Membership | ID `6` / Org `4` |
| 新Workspace Membership | ID `6` / Workspace `5` |
| 新Eligibility | ID `4` / single Org `4` |
| Phase C inventory hash | `882cab4908da3522a76d2de34efb89a6cb03247d60eea51cc570b447b24f36fc` |
| Phase C payload hash | `859682d3cdc5f2214a2b640a2bc56d7f18f11e857f5e692bf00b65eb7e6b28d9` |
| Lifecycle operation | ID `1` / suspend / result suspended |

## 2. 実行前Gate

以下が1つでも不成立なら書込み前に停止する。

1. AS-G04の高見承認済み。操作者はCodex、本人受入者は高見 昌也。実施日時・停止窓は実行承認時に記録する。
2. Repository HEAD / schema / Migrationが承認時の値と一致。
3. Productionではなく、承認された通常local環境である。
4. 復元可能Backupを取得し、size / SHA-256 / JST / integrity /保管先を記録。
5. User 1、Org 1/4、Membership 1/5、Workspace 5、eligibility ID 1のbefore値がManifestと一致。
6. `takami@pro-chubo.com`がUser、pending Email変更、pending Invitationのいずれとも衝突しない。
7. Org 4 active OwnerがUser 1の1名、Workspace 5 ownerがUser 1。
8. 旧資格が`legacy_multi`、compatibility Membershipが`1, 5`。
9. 外部Mail、本人Email確認、別Browser Profileでの受入が可能。
10. 実行中にOrganization / Membership / Workspace / eligibilityを書き換える別処理を止める。

## 3. Cutover手順

### Phase A｜BackupとStandard Workspace準備

1. 通常local DBの一貫Backupを取得する。
2. before inventoryとlogical fingerprintを保存する。
3. Org 4の`standard_workspace_id`が現在`null`であることを条件に、承認済みWorkspace ID `5`へ設定する。
4. Workspace 5がOrg 4 / active / shared / owner User 1であることを再確認する。

停止点：別Workspaceが設定済み、Workspace 5のTenant / status / type / ownerが不一致なら停止。新規Workspaceを作らない。

### Phase B｜正式S4 Invitationと本人登録

1. User 1（Org 4 active Owner）から`takami@pro-chubo.com`へOwner Invitationを発行する。
2. 本人が招待リンクを開き、新Accountの表示名とPasswordを本人入力する。
3. S4 prepareでinvited Membershipを作る。
4. 本人がEmail Verificationを完了する。
5. S4 acceptで新Membershipをactive Organization Owner、新資格をOrg 4 `single`へする。
6. Workspace 5の初期Membershipが作成されていることを確認する。

停止点：Mail不達、本人不一致、Verification未完了、Invitation世代不一致、資格bind不成立なら停止。旧Ownerは変更しない。

### Phase C｜現在責任の原子的切替

新User ID確定後にManifest v2を作成し、IDとpayload hashを固定する。次を同一Transactionで行う。

1. Org 4、Workspace 5、旧/新Membership、旧/新eligibilityをlock。
2. 新Membershipがactive Organization Owner、新資格がOrg 4 singleであることをassert。
3. 新Membershipのlegacy `role=owner`、`company_role=owner`を設定。明示permissionsは旧Ownerの現在値（AS-G03時点は空配列）と完全一致させる。
4. 新Workspace Membershipを`owner`へ更新。
5. Workspace 5 `owner_user_id`をUser 1から新Userへ更新。
6. S5正式経路で旧Membership ID 5を`suspended`へ変更。access epoch更新と対象Credential失効を行う。
7. 旧eligibility ID 1を`legacy_multi`からOrg 1 `single`へ条件付き更新。eligibility ID、classification version、compatibility Evidenceを保持。
8. lifecycle request IDとmanifest hashを永続receiptとして確定し、全assertion成功時だけcommit。

停止点：before値、Owner数、version、payload hash、対象IDが1つでも不一致なら全rollback。推測更新・部分commitをしない。

### Phase D｜suspended受入確認

Environment Gate：新旧Accountとも、承認済み通常local http://localhost/rise-gate-os/public を別Browser Profileで使用する。各確認でbase URL、Login User ID、current Organization IDをEvidenceに含める。os.rise-gate.comその他Production URLを検出した場合は、その時点でAcceptanceを停止し、異なる環境間のData件数を比較しない。

1. 新Accountを別Browser ProfileでLoginし、株式会社ライズアップCompany Homeへ直接進める。
2. 新AccountでWorkspace 5、Business Domain、FinancialのOwner権限を確認する。
3. 旧AccountでRise GateへLoginでき、Project / Task / Improvement等の既存利用が維持されることを確認する。
4. 新AccountからOrg 1、旧AccountからOrg 4 Company / Workspace / Business Domain / Financialへの直URL・POSTが拒否されることを確認する。
5. Organization / Workspace / Project / Business Domain / FinancialのID・件数、History actor fingerprintに許可外差分がないことを確認する。
6. 同じcase再実行がNOOP、異なるpayloadが拒否されることを確認する。

不合格時：Phase Eへ進まず、Phase Cの補償手順を人承認のもと実施する。

### Phase E｜所属終了finalize

受入者がPhase Dを承認した後だけ、S5正式経路で旧Membership ID 5を`suspended → left`へする。left後の自動resumeは行わない。

## 4. Permission / DataのBefore → After

| 対象 | Before | After |
| --- | --- | --- |
| User 1 global | active / SA=true | 不変 |
| User 1 Org 1 | active Owner | 不変 |
| User 1 Org 4 | active Owner | suspended、受入後left。行とHistoryは保持 |
| User 1資格 | legacy_multi | single Org 1。資格IDとcompatibility Evidence保持 |
| 新User | 存在なし | active / verified / SA=false |
| 新User Org 4 | 存在なし | active Owner / legacy owner / company owner |
| 新User Workspace 5 | 存在なし | owner |
| 新User資格 | 存在なし | single Org 4 |
| Workspace 5 owner | User 1 | 新User |
| Org 4 Standard Workspace | null | Workspace 5 |
| Business Data | 既存ID・内容 | 不変 |
| 過去creator / actor / reviewer等 | User 1 | 不変 |
| Password / Token / Session / Avatar | 旧Account固有 | コピーしない |

## 5. Rollback / Compensation

- Phase A/Bで失敗：作成済みInvitation / Accountの正式な取消・状態を確認し、旧Ownerには触れない。本人CredentialをDB rollbackで消したことにしない。
- Phase CのTransaction内失敗：全rollback。新Accountはactive Ownerとして残り、旧Accountもactive Ownerのまま。再実行は同一case / payloadだけ許可。
- Phase C commit後、Phase E前：補償可能。S5 resumeで旧Membershipをactiveへ戻し、Workspace owner・legacy responsibility・旧資格をbeforeへ戻す。新Account、本人Password、Email Verification、accepted Invitationは巻き戻さない。
- Phase E left後：自動rollback禁止。再所属は別の人判断・正式Journeyとして扱う。
- いずれの補償でも、失効済みAI Key、Token、Session等を復活させない。
- DB全体restoreは、後続書込み0・外部送信影響・Audit整合を確認した別の明示承認がある場合だけ行う。

## 6. 完了Acceptance

- User 1：global active / SA=true / Org 1 active / Org 4 left / eligibility single Org 1。
- 新User：本人verified / SA=false / Org 4 active Owner / Workspace 5 owner / eligibility single Org 4。
- 両Organizationのactive Ownerが1名以上。
- User 1はRise Gate、新Userは株式会社ライズアップへ通常Loginで直接進む。
- 旧AccountのOrg 4アクセス、新AccountのOrg 1アクセスを拒否。
- Company / Financial / Business Domainの新Owner権限が現行Ownerと一致。
- Organization / Workspace / Project等の本体ID・Tenant・内容に差分なし。
- 過去History・Revision・Operation・SnapshotのUser参照に差分なし。
- 同一retryで重複0、変更payload拒否、receiptとAuditを保存。
- Production未接続・未変更。

## 7. AS-G04 Decisionと残承認

1. このManifestの手順と変更allowlist。
2. 実施対象が通常localであること。
3. 新Accountの表示名：`高見 昌也`（承認済み）。
4. 実施日時・停止窓・操作者・本人受入者。
5. Backup保持期間：30日（承認済み）。保管先はprivate storageのAccount分離専用directory。
6. Phase Dの受入完了後にPhase E（left）へ進むこと。
7. Manifest v2の実IDとhashは上記の値で確定済み。
8. Cloneで検証した処理を、default dry-run・環境guard・allowlist・before hash必須の一回限り実Cutover runnerとして準備すること（完了）。AS-G03 supportは通常local実行を拒否する状態を維持。

### Phase A〜C実行Evidence

- Backup：`AS-G04-20260923-LOCAL-pre-cutover-20260923-225114.sqlite`、integrity `ok`、SHA-256 `a1c81bf4519aec64e91e705c77bb5952f2d2c91e598ee4e6b3f161775ae8b8cf`、保持期限 2026-10-23 22:51:14 JST。
- Phase A：Org 4 Standard Workspaceを既存Workspace ID 5へ設定。Workspace新規作成なし。
- Phase B：Invitation ID 1を正式Journeyで受諾。本人Password / Email Verification完了。新User ID 5を作成。
- Phase C：同一Transactionで新legacy Owner・Workspace Ownerを設定し、旧Membership ID 5を`suspended`、旧Eligibility ID 1をsingle Org 1へ更新。
- Phase C後：Org 4 active Owner 1名、Workspace 5 owner User 5、DB integrity `ok`、Audit / lifecycle receipt各1件。
- Business Data非複製：Project総数17、新UserのProject Membership 0、旧UserのProject Membership 17を保持。
- 旧Eligibility ID / classification version / compatibility Membership `1, 5`を保持。
- Phase C後DB SHA-256：`65eec5790ec749945135eb44f32f2ce41fe35fb09da0b8dde51c5f5463130934`。
- Production未接続・未変更。Deploy未実施。

### Phase D通常local Acceptance Evidence

- Environment：全操作のbase URLはhttp://localhost/rise-gate-os/public。Apache access log、Account Security Event、DB Sessionを相互照合し、Production URLをAcceptance対象から除外した。
- 新Account：User ID 5、current Organization ID 4。Login成功、Company Home、Workspace 5、Business Domain一覧・詳細をHTTP 200で確認。Resolverはsingle Org 4、Org 1 active access=false。
- 旧Account：User ID 1、current Organization ID 1。Login成功、Company Home、Workspace 1 / 2、Project一覧をHTTP 200で確認。Resolverはsingle Org 1、Org 4 active access=false。
- Permission：User 5はOrg 4 Financial view / Business Domain edit=true。User 1は同権限=false。User 1のWorkspace 5 membership行はHistoryとして保持するが、親Org 4 Membershipがsuspendedのため実効Accessは拒否される。
- 通常local Data：Financial 21件。Business Domainはactive 2件＋archived 1件の計3件、Domain明細21件。借入0件、Workspace 5はProject 0件 / 改善0件で、Productionとの件数一致はAcceptance条件外。
- Data保全：Phase A前Backupと現在DBでFinancial、Business Domain / items / attributes / revisions / operations、Project / Project Member / Task / Improvement / Roadmap / Clientの件数・内容hashが一致。History actor参照はUser 1のまま保持。

Phase D通常local Acceptanceは完了。自動compensateは行っていない。Phase Eは高見の別承認により実行した。

### Phase E所属終了finalize Evidence

- Human Gate：高見 昌也がPhase D本人Acceptanceを正式承認し、Membership ID 5の`suspended → left`を明示承認。
- Environment / Repository：通常local SQLite、HEAD `657ae6aaaca53c0adf4866ea0e325757976332bf`、clean worktree、pending Migration 0、DB integrity `ok`。
- Finalize前固定値：DB SHA-256 `85f285d6188d193bcb51b41c5dc0ebcb029984738ad3ef475c5816d7098ecb41`、inventory hash `2d446456aa87a515945a979c7ccde2ca2a85759737c2d5da997883171bca05dc`。
- 実行：Phase E専用flagと本人Acceptance確認文をプロセス内だけ有効化し、S5正式Lifecycle経路でMembership ID 5を`left`へ更新。自動resume / compensateなし。
- Receipt：Lifecycle operation ID `2`、command `end`、request ID `a3959b84-7243-4f43-8e32-92500b4d82ea`、result `left`、version `3`、access epoch `3`。Organization Audit event ID `14`、event `organization.membership.end`、outcome `success`。
- Final Account：User 1はglobal active / System Admin=true / Org 1 active Owner / Org 4 left / eligibility ID 1 single Org 1。User 5はactive / verified / System Admin=false / Org 4 active Owner / eligibility ID 4 single Org 4。
- Workspace / Owner：Workspace 5はactive shared、owner User 5、Workspace Membership ID 6 owner。Org 1 / Org 4のactive Ownerはいずれも1名。
- Business Data保全：Phase A前BackupとPhase E後でFinancial 21件、Business Domain 3件、Domain items 21件、attributes 3件、revisions 6件、operations 8件、Project 17件、Project Member 17件、Task 51件、Improvement 51件、Roadmap 36件、Client 9件の件数・全行hashが一致。過去History actor参照を含めallowlist外差分なし。
- Final DB：SHA-256 `fb7ea49108fdcfaf8d863ec89b8c66c4fb368a23ac61bf7c47a6afacf4aac771`、inventory hash `c45ff8044adef56dba01a3394fa963aa22d1c615b5c4dd2e3a2f21a13e7910d8`、integrity `ok`。
- Backup：`AS-G04-20260923-LOCAL-pre-cutover-20260923-225114.sqlite`、SHA-256 `a1c81bf4519aec64e91e705c77bb5952f2d2c91e598ee4e6b3f161775ae8b8cf`、integrity `ok`、2026-10-23 22:51:14 JSTまで保持。
- Environment Gate：Phase D Evidenceのbase URL / Login User ID / current Organization ID記録を正式運用Evidenceとして維持。Production Dataはlocalへ複製していない。
- Close判定：Account分離 Formal Close可。既存Product / Permission / Tenant / Data Contractの変更はなく、Master Update不要。Production接続・変更、Deploy、HOW、Scope 8はいずれも未実施。
