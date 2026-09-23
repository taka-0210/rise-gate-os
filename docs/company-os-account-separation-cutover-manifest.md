# Company OS｜Account分離 Cutover Manifest（AS-G04承認用）

版：AS-G03 rehearsal反映版｜2026-09-23 JST

状態：**未承認・実行禁止**

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

新User ID、Invitation ID、新Membership ID、新Eligibility IDは実作成後に確定する。AS-G03の仮ID `5 / 1 / 6 / 4`を実行値として使わない。

## 2. 実行前Gate

以下が1つでも不成立なら書込み前に停止する。

1. AS-G04の高見承認、実施日時、操作者、本人受入者、停止窓が記録済み。
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

1. 新Accountを別Browser ProfileでLoginし、株式会社ライズアップCompany Homeへ直接進める。
2. 新AccountでWorkspace 5、Business Domain、FinancialのOwner権限を確認する。
3. 旧AccountでRise GateへLoginでき、Project / Task / Improvement等の既存利用が維持されることを確認する。
4. 旧AccountからOrg 4 Company / Workspace / Business Domain / Financialへの直URL・POSTが拒否されることを確認する。
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

## 7. AS-G04で高見が承認する事項

1. このManifestの手順と変更allowlist。
2. 実施対象が通常localであること。
3. 新Accountの表示名（リハーサルは旧Userの表示名を仮使用）。
4. 実施日時・停止窓・操作者・本人受入者。
5. Backupの保管先と保持期間。
6. Phase Dの受入完了後にPhase E（left）へ進むこと。
7. 実行直前inventoryから再生成するManifest v2の新User ID / Membership ID / Eligibility ID / Invitation ID / final payload hash。
8. Cloneで検証した処理を、default dry-run・環境guard・allowlist・before hash必須の一回限り実Cutover runnerとして準備すること。現在のAS-G03 supportは通常local実行を拒否するため、そのまま実適用には使用しない。

承認されるまで、Admission flag変更、Invitation、Account作成、Membership / eligibility / Workspace変更を行わない。
