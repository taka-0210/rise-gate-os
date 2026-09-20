# Company OS Scope 6｜S6-P0 Audit

監査日時: 2026-09-20 JST
対象: Scope 6 Owner Onboarding
変更前基準Commit: `5f207136eadf9d7e2a7ca03a87bab53092213c97`

## 1. Repository基準

- Branch: `master`
- P0開始時HEAD: `5f207136eadf9d7e2a7ca03a87bab53092213c97`
- P0開始時`origin/master`: 同一
- Worktree: clean
- Repository `AGENTS.md`: 読取済み。JST、focused/full test、commit/push、Production Deploy分離を適用する。
- Scope 1〜5: Repository内Final Close Reportと上記HEADに包含。再Open・再設計しない。
- S5実装Migration `2026_09_20_000004_add_scope_five_membership_lifecycle`: 通常local DBではPending。Scope 6でも通常local DBへ適用しない。
- Production: 読取・Migration・Deployを実施しない。

## 2. 正本7点

| 資料 | SHA-256 |
| --- | --- |
| CompanyOS_Ver1_要件仕様書_v031_Scope5_FinalClose.xlsx | `79DC0FFE8293EB6DD6686E70E963AE3FA682DF5AE2FBD4224766AED2C258A990` |
| CompanyOS_v134_scope5_done.pptx | `35748C0F8E5DF48BEF2C03CFD434CC791FA93B5CD3BAD822DC0A4EA31DD6B1D2` |
| CompanyOS_Scope6_Codex実装指示書_v002_確定版.md | `0631C8A46BB1457E938AACCEFEB3E903EC6D8894C68C4EF41F58AB0D42C74236` |
| CompanyOS_Scope6_管理表_v002_確定版.xlsx | `92F362A7FAB4525CD0BE13D2B07DD7845D4B49D54D79AE9F5C69139AC8B6D4BB` |
| CompanyOS_Scope6_実装準備書_v002_確定版.md | `BCB43AA251FA6BDD6CF0C73FF0115B55D7A6368683B53CE407E6B09B3F86BA66` |
| CompanyOS_Ver1_RemainingScope_管理表_v006_Scope6確定版.xlsx | `9710074344D3279389C5A2D296D1C1C5E6A39AEEDA98BEF2180C8DA1B95F0EF1` |
| CompanyOS_Ver1_RemainingScope_Roadmap_v006_Scope6確定版.md | `0472A32294E1AD9B270D5E911149FB013C05597B53A484158DA4C2FB94F59984` |

XLSX/PPTXをZIP XMLとして読取り確認した。Scope 6管理表はScope 6関連198 hit、S6-DE-01〜04関連81 hit、Remaining Scope管理表は85/51 hit。最新MasterはScope 5 Final Close時点であり、Scope 6実装結果は未記録という資料上の位置づけと一致する。

## 3. 現行入口・Writer inventory

| Writer / Route | 現行責務 | Scope 6での扱い |
| --- | --- | --- |
| `RegisteredUserController` `/register` | 空DB最初のSystem Admin＋初期Org/Workspace Bootstrap | KEEP。User存在後404を維持し、Owner開始へ転用しない。 |
| `PromoteClientToCompanyAccount` | Clientを会社Accountへ昇格する既存業務経路 | KEEP・分離。Scope 6内部から呼ばない。 |
| S4 `OrganizationInvitation*` | 既存OrganizationへのStaff Invitation | KEEP。Scope 6 Token/Table/Endpointへ流用しない。 |
| `StandardWorkspaceService` | Owner明示操作によるshared/active/included標準Workspace初期化 | REUSE。Scope 6外側Transaction内で呼ぶ。 |
| `WorkspaceController::store` | shared/personal追加Workspace | KEEP＋新OrgだけPersonal gate。既存Orgは互換維持。 |
| `OwnerOnboardingJourney` | P1以降で追加する専用Writer | Scope 6で唯一の承認済みOwner開始Writer。 |

## 4. Contract確認

- Organization RoleはOwner/Admin/Member。Scope 6初期Ownerは`organization_role=owner`、legacy `role=member`、`company_role=member`、`permissions=[]`とする。
- `CompanyAccess`はlegacy ownerへFinancial権限を与えるため、Scope 6ではlegacy ownerを使用しない。
- `StandardWorkspaceService`は同一DB connectionのnested transactionとして外側Transactionへ合成可能。active Owner Membershipを先に作る必要がある。
- `OrganizationSessionContext`はcompany IDとaccess epochを選択し、workspaceを一度clearする。Scope 6はcommit後に標準Workspaceを選択する。
- S2 current Email Verification、active-user、credential generationを再利用可能。
- S4 Staff Invitationは新Organization作成後の任意導線として接続可能。
- S5 Cross-Organization Project認可はProject Member自身のWorkspace経路であり、Scope 6からProject所有Organization所属を追加要求しない。
- Organization Auditはorganization_id必須。会社作成前は専用pre-Org auditが必要。

## 5. DB / Data確認

- 通常local DB `migrate:status`: Scope 1〜4までRan、Scope 5 MigrationのみPending（P0時点）。
- 通常local DBに書込・Migration適用なし。
- Scope 6 schemaはadditiveで設計可能。既存OrganizationはPersonal作成を維持するdefault、Scope 6新Orgだけ明示OFFにできる。
- 既存ID、User、Organization、Membership、Workspace、Project、Historyを更新・backfillする必要なし。
- 推測Owner・標準Workspace補正、Data削除、破壊的renameは不要。

## 6. Fresh baseline

Command: `C:\xampp\php\php.exe artisan test`
Environment: PHPUnit SQLite `:memory:`、全Migration適用
Result: **401 tests / 3223 assertions / 0 failures / 138.97s**

過去Report値の転記ではなく、P0開始HEADで再実行した。

## 7. S6-C01 / S6-C02判定

- S6-C01: **未発生**。専用開始案件を追加し、Bootstrap・Client昇格・S4 Invitationを分離したまま、S2〜5 Contractと合成可能。
- S6-C02: **未発生**。additive schemaと利用操作Transactionで実現でき、既存Data削除・不可逆Migration・推測backfillは不要。

## 8. P0終了判定

S6-P0は完了。重大互換Blocker 0。S6-DE-01〜04および実装指示書に従い、確認を挟まずS6-P1〜P5へ進行する。

Pendingとして継続するもの:

- 正式Terms/Privacy公開と顧客入口有効化はRelease Gate。
- 本番Mail実送受信、通常local/Production Migration、Production Deployは別工程。
- Personal Workspace一般提供判断、Client昇格との販売入口統合はScope 6外。
