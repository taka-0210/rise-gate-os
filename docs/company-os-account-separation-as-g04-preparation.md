# Company OS｜Account分離 AS-G04 Cutover実行準備

最終更新：2026-09-23 JST

判定：**実Cutover準備Evidence（実行結果はCutover Manifest v2を参照）**

## 1. 今回の境界

AS-G04で承認されたCutover Manifestを、通常local専用の一回限りrunnerへ固定した。今回は既定dry-runと実行直前確認までで停止し、通常localのAdmission、Standard Workspace、Invitation、Account、Membership、eligibilityは変更していない。

- Production：未接続・未変更
- Deploy：未実施
- Admission：`false`のまま
- 実Mail / Invitation：未送信
- Backup：実施予定と30日保持条件を固定。実Cutover Backupは未取得
- Phase E：別flag・別確認文を必要とし、今回の承認では実行不可

## 2. Cutover runner

Artisan command：`company-os:account-separation`

| Phase | 責務 | 現在の状態 |
| --- | --- | --- |
| `dry-run` | 環境・Migration・schema・inventory・hash・Backup計画 | 既定動作。書込みなし |
| `phase-a` | 一貫Backup取得後、Org 4 Standard WorkspaceをID 5へ条件付き設定 | 実行guard済み・未実行 |
| `phase-b` | S4 Owner Invitationを正式Serviceから発行 | 実行guard済み・未実行 |
| `phase-c` | 新Owner active後、責任切替・旧所属suspend・資格single化を原子的実行 | 実行guard済み・未実行 |
| `compensate` | suspended段階の補償 | 実行guard済み・未実行 |
| `phase-e` | 高見受入後の旧所属left | 独立flagと受入確認文が必要。別承認まで禁止 |

runnerの`--apply`を付けない場合は、Phase指定があっても読取dry-runになる。実適用には次の全条件を必要とする。

1. normal-local SQLiteの正規パスと`APP_ENV=local`。
2. `ACCOUNT_SEPARATION_CUTOVER_ENABLED=true`。
3. 通常local確認文の完全一致。
4. clean working tree。
5. case ID。
6. 承認済みHEAD、DB SHA-256、inventory hashの完全一致。
7. DB integrity=`ok`、必須table存在、Pending Migration 0。
8. Phase B / CはAdmission=true。
9. Phase C以降は実作成後の新User ID。
10. Phase Eは`ACCOUNT_SEPARATION_FINALIZE_ENABLED=true`と高見受入確認文を追加要求。

Production、別SQLite、別HEAD、dirty tree、stale DB / inventory、flag不足ではfail closedする。

## 3. 通常local dry-run

| 項目 | 結果 |
| --- | --- |
| Result | `DRY_RUN_PASS` |
| DB | normal-local SQLite、integrity=`ok` |
| DB size | 1,282,048 bytes |
| DB SHA-256 | `fca514ad0a0b73f69e8583da1c04fdbcea94fe65ade6ba41bab5fc7f8ea172af` |
| Migration | 92 files、Pending 0 |
| 必須schema | 全table存在 |
| Inventory hash | `cc592e75a834904492294f3f8daa54a4b917ce71668376a52feb7ea88d1a24f8` |
| Admission | false |
| Cutover apply flag | false |
| Finalize flag | false |
| Email衝突 | User 0 / pending Email change 0 / pending Invitation 0 |
| Dry-run前後DB hash | 一致 |

HEADは実Cutover直前のdry-runでcurrent clean HEADを再取得し、その実行単位の承認値として使う。資料更新で変わるCommit値を先回りして固定しない。

## 4. Before inventory

- 旧User ID `1`：active / System Admin=true。
- Rise Gate：Org `1` / Membership `1` active。
- 株式会社ライズアップ：Org `4` / Membership `5` active Owner。
- 旧Membership：legacy role owner / company_role owner / explicit permissions `[]` / epoch 1 / lifecycle version 1。
- active Owner数：1。
- Org 4 Standard Workspace：`null`。
- Workspace ID `5`：Org 4 / active shared / owner User 1 / Workspace role owner。
- Eligibility ID `1`：`legacy_multi` / compatibility Membership `1, 5`。
- User 3 / Organization 4 / Workspace 5 / Project 17。

## 5. 変更allowlist

Phase A〜Cで変更を許可するのは次だけ。

- Org 4 `standard_workspace_id: null → 5`。
- 新User / S4 Invitation / 新Org Membership / 新Workspace Membership / 新eligibilityの正式Journeyによる追加。
- 新Membershipのlegacy `role/company_role: member → owner`と、承認済みexplicit permissions `[]`。
- 新Workspace Membership `member → owner`。
- Workspace 5 `owner_user_id: 1 → new_user_id`。
- 旧Membership ID 5 `active → suspended`、epoch / lifecycle version / status metadata、S5失効処理。
- Eligibility ID 1 `legacy_multi → single Org 1`。ID・classification version・compatibility Evidenceは保持。
- S4 / S5 / Account / Organization Auditと冪等operation receipt。

Organization / Workspace / Project / Business Domain / Financial本体、過去creator / actor / reviewer、Password / Token / Session / Avatar、Rise Gate側Relationは変更allowlist外。

## 6. Backup計画

- 実行時期：Phase Aの最初、DB mutationより前。
- 方法：SQLite `VACUUM INTO`。
- 保存先：`storage/app/private/backups/account-separation/`。
- 記録：元DB、Backup path、size、SHA-256、integrity、取得JST、保持期限JST。
- 保持期間：30日。
- Phase AはBackup後にも元DB SHA-256一致を確認してからStandard Workspaceを設定する。
- restoreは後続書込み・Mail・Audit影響を確認した別承認なしに実行しない。

## 7. 実行順序と停止点

1. 最終dry-runでHEAD / DB SHA / inventory hashを固定。
2. Phase A：Backup → integrity / hash確認 → Standard Workspace ID 5設定。
3. Phase B：Owner Invitation送信。
4. 高見本人が表示名`高見 昌也`、Password、Email Verification、Invitation acceptを完了。
5. 新User IDを確定し、Phase C直前dry-runとhashを再取得。
6. Phase C：新Owner / eligibilityを再確認し、責任切替と旧所属suspendを同一Transactionで実行。
7. Phase D：高見本人が新旧Accountを別Profileで受入確認。
8. ここで停止。Phase Eは別承認まで実行しない。

各Phaseはbefore値・hash・Owner数・version・Tenant・Email・資格が違えば停止する。Phase B前にStandard Workspace不成立、Phase C前に本人verified / active Owner / single資格不成立なら旧Accountへ触れない。

## 8. Rollback / Compensation

- Phase A/B失敗：旧Ownerを変更しない。Invitation /本人Accountは正式状態として扱い、DB rollbackで消したことにしない。
- Phase C Transaction失敗：全rollback。
- Phase C commit後・Phase E前：`compensate`で旧Membership active、Workspace owner User 1、旧資格 legacy_multiへ戻す。新本人Account・verified状態・accepted Invitationは巻き戻さない。
- 失効済みCredentialは復活させない。
- Phase E後は自動resume禁止。

## 9. Verification

- 通常local default dry-run：PASS。実行前後DB SHA一致。
- apply flag=false負例：Phase Aはexit 1で拒否、DB SHA不変。
- Focused Test：`AccountSeparationCutoverTest` 2 tests / 28 assertions / failure 0。
- 検証内容：原子的責任切替、同一retry NOOP、receipt重複0、single化、Owner 0防止、suspended補償、Credential / accepted Invitation非巻戻し、補償済みcase再利用のfail closed、Phase E left境界。
- PHP lint / Pint：PASS。
- Full Test / Build / Browser：未実施。今回の実差分は一回限りbackend runnerで、Frontendや既存Closed Contractを変更していない。AS-G03 EvidenceとFocused Testが今回のRiskへ直接対応する。

## 10. 停止位置

実Cutover実行承認待ち。次の承認があるまでBackup、Admission変更、Phase A、実Invitation、Account作成、Membership / eligibility / Workspace変更を行わない。
