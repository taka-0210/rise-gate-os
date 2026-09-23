# Company OS｜Account分離 AS-G03 隔離Cloneリハーサル

最終更新：2026-09-23 JST

判定：**AS-G03 Done / AS-G04承認待ち**

## 1. 実行境界

- 基準HEAD：`19951ab34a49b4326b9fea6ab153880f2dcd53bd`
- 対象：通常local SQLiteの一貫Cloneだけ
- Production：未接続・未変更
- 通常local Admission：`false`のまま
- 実Mail：送信なし（Laravel fake transport）
- 実Account / Invitation / Membership：通常localには作成・変更なし
- PUX-A RG01 / RG02 / RG03およびClosed ScopeのEvidenceは再利用し、Account分離固有Deltaだけを検証した。

検証支援は `tests/Support/account_split_clone_rehearsal.php` に固定した。通常localの正規DBパス以外をsourceとして拒否し、OS temp配下の`company-os-as-g03-*`だけをClone対象として許可する。Clone接続を確認してからAdmissionをprocess内だけで有効化する。

このsupportはClone以外への接続を拒否するため、実Cutover runnerではない。AS-G04承認前に通常localへ転用できない状態を維持している。

## 2. Backup / Clone Evidence

| 項目 | Evidence |
| --- | --- |
| Case | `AS-G03-20260923-203253` |
| 元DB | `database/database.sqlite` |
| 元DB size | 1,282,048 bytes |
| 元DB SHA-256 before / after | `fca514ad0a0b73f69e8583da1c04fdbcea94fe65ade6ba41bab5fc7f8ea172af` / 同一 |
| Logical fingerprint | 84 tables、before / after一致 |
| 一貫Backup | SQLite `VACUUM INTO`、1,269,760 bytes |
| Backup SHA-256 | `a1c81bf4519aec64e91e705c77bb5952f2d2c91e598ee4e6b3f161775ae8b8cf` |
| Integrity | source / backup / scenario cloneすべて`ok` |
| Restore | backupから復元したCloneがbyte-identical |
| 一時Data | 検証後にguard付きで削除。AS-G03一時directory残存0 |

Backupはリハーサル時の復元可能性を証明する一時Snapshotであり、実Cutover用Backupではない。AS-G04承認後の実施直前に、保管先・保持期間を決めた新しいBackupを取得する。

## 3. Preflight結果

| 対象 | Clone開始時点 |
| --- | --- |
| 旧User | ID `1`、active、System Admin=true |
| KEEP | Rise Gate：Organization ID `1`、Membership ID `1` |
| TARGET | 株式会社ライズアップ：Organization ID `4`、Membership ID `5` |
| 旧TARGET所属 | active / Organization Owner / legacy owner / company owner |
| Standard Workspace | Workspace ID `5`はOrg 4のactive shared Workspace。`organizations.standard_workspace_id`は`null` |
| Workspace owner | User ID `1`、Workspace role=owner |
| 旧資格 | Eligibility ID `1`、`legacy_multi`、compatibility Membership `1, 5` |
| Owner数 | Org 4 active Owner = 1 |
| 新Email衝突 | User 0 / pending Email change 0 / pending Invitation 0 |
| 件数 | User 3 / Organization 4 / Workspace 5 / Project 17 |

確定DecisionどおりWorkspace ID 5を使えるが、S4受諾前にOrg 4の`standard_workspace_id`へ明示設定するStepが必要と判明した。Cloneでは`null → 5`のみを条件付き更新し、Workspace新規作成は0件だった。

## 4. S4 / Admissionリハーサル

1. 旧Ownerから`takami@pro-chubo.com`へOrganization Owner Invitationを発行。
2. fake mailからsingle-use tokenを取得し、`InvitationOnboardingController`で本人Passwordによる新User作成を実行。
3. 新Userへ`unstarted`資格を作成し、S4 prepareでinvited Membershipを作成。
4. Email未確認時のacceptを拒否し、Membershipがinvitedのままであることを確認。
5. Clone内で外部Email確認境界を模擬し、S4 acceptを実行。
6. 新UserがOrg 4のactive Owner、資格がOrg 4 `single`になることを確認。
7. 同じacceptの再試行は重複Membership / Workspace Membership / Auditを作らないことを確認。

Clone内の新User IDは`5`、Membership IDは`6`、Eligibility IDは`4`だった。これらは**リハーサル仮ID**であり、実Cutoverでは使用・予約しない。

S4はOrganization RoleをOwnerにするが、legacy `role/company_role`とWorkspace Roleはmemberで開始する。今回承認済みのOwner責任はCutover Stepで明示的にOwnerへ変更する必要がある。

## 5. Owner / Permission / 資格切替結果

- 新Owner active化を先に完了し、active Ownerが0になる瞬間はなかった。
- pending Owner段階で旧Owner降格を試み、最後のactive Owner保護で拒否された。
- 新Membership：Organization Role / legacy role / company_role = `owner`。
- 新Workspace Membership：Workspace ID 5 / role=`owner`。
- Workspace ID 5 `owner_user_id`：旧User 1 → 新User。
- 旧Org 4 Membership：active → suspended、受入後のfinalizeでleft。
- 旧資格：`legacy_multi` → Rise Gate（Org 1）`single`。
- 新資格：株式会社ライズアップ（Org 4）`single`。
- 旧資格IDとcompatibility Evidence `1, 5`は保持。
- 新AccountのSystem Admin=false。
- 旧Userのglobal active / System Admin / Rise Gate Membershipは維持。
- Organization / Workspace / Projectの件数・ID・Tenantは不変。
- Business Domain、Revision / Operation、Financial、Project / Task / Improvement等の主要History fingerprintは不変。

現行Ownerの明示`permissions`は空配列だった。新Accountへ未知の個別Permissionは追加せず、Owner Contractによる既存Company / Financial全権限が新Account=true、旧suspended Account=falseになることを全7 permissionで確認した。Business Domain編集も新Owner=true、旧Account=falseだった。

## 6. Failure / Retry / Rollback

| Scenario | 結果 |
| --- | --- |
| Email未確認でaccept | 拒否。Membership active化なし |
| 新Owner active前の旧Owner降格 | 拒否。Owner 0なし |
| 全Cutover更新後に例外注入 | Transaction全rollback。post-accept状態と全table fingerprint一致 |
| 同一case / request / payload再実行 | NOOP。Lifecycle receipt 1件、Data差分0 |
| 同一requestでpayload変更 | 拒否。Data差分0 |
| suspended段階の補償 | 旧Membership active、旧資格 legacy_multi、Workspace owner旧Userへ復元 |
| 補償時のCredential / Invitation | 新Accountとaccepted Invitationは巻き戻さず維持 |
| 受入後のleft finalize | 成功。同一retryはNOOP |
| left後のresume | 拒否。自動復帰なし |
| Backup restore | byte-identicalで成功 |

失効済みCredentialを復活させるrollbackは行っていない。実Cutoverでも補償可能なのはsuspended段階までとし、leftは人の受入完了後に限定する。

## 7. Verification判断

- 実施：PHP lint、Clone guard負例、実Data CloneによるS4 / PUX-A / S5 / Permission / retry / rollback / restoreのend-to-end rehearsal。
- 未実施：Full Test、Build、Browser、Production接続。
- 理由：ApplicationのProduct / Permission / Data Contractは変更しておらず、変更はAS-G03専用supportとEvidenceのみ。今回のRiskは実Data構造、切替順序、原子性、冪等性、復旧性であり、Clone rehearsalが最も直接的なEvidenceになる。Frontend差分はない。

## 8. AS-G03判定

**Done。** Account分離固有の技術的リハーサルは成功した。AS-C01は「既存Workspace ID 5を明示設定するStep」として具体化、AS-C02はPermission matrix一致で解消、AS-C03に該当する不可逆Schema・History書換え・Data削除は発生していない。

実Account分離は未実施。次はCutover ManifestをAS-G04で人が承認する工程であり、承認前に通常local Admission、Invitation、Account、Membership、資格を変更しない。
