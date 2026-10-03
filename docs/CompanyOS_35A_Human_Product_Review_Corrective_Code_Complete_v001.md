# Company OS｜35A Human Product Review Corrective Code Complete v001

Date: 2026-10-03 JST

Status: **35A HUMAN PRODUCT REVIEW CORRECTIVE / CODE COMPLETE**

Formal Close: **PENDING HUMAN PRODUCT REVIEW**

## 1｜Identity / Boundary

| Item | Evidence |
|---|---|
| Branch | `ce-p1-realtime-corrective` |
| Work-start HEAD | `8d18a61c8bb9e3e76d0be7ecea1a2fbd62186b09` |
| Corrective implementation commit | `baf03829d557773ef3b809853cd8e9f926986aa3` |
| Corrective implementation tree | `a6aa6439496d922bb89a1cd2e7e98ca9493ba779` |
| Approved Product Decisions | WR-35A-C01 / C02 / C03 |
| Lifecycle timezone | `Asia/Tokyo` fixed for Ver.1 |
| HR-10 | Resolved Investigation / Corrective対象外 |
| Production / Deploy / Scope 35–37 | 0 |

Three unrelated untracked files that existed before this work remain unmodified and untracked.

## 2｜Corrective Result

### HR-04｜Company OS共通Page Layout

Root Causeは、Application ShellがGlobal HeaderとMainの2-row gridである一方、Breadcrumbを暗黙grid itemかつnegative bottom marginで配置していたことです。Page固有wrapperの先頭marginや構造差により、Main開始位置が画面ごとに揺れるContractでした。

共通Shellを次の3領域へ固定しました。

- `header`
- `breadcrumbs`
- `main`

Breadcrumbのnegative marginを廃止し、Desktop / mobileのMain paddingを共通Contractで定義しました。個別Page margin patchは追加していません。

### HR-08｜Company Period期数

- `organization_management_periods.fiscal_term_number`: nullable unsigned integer
- Organization内unique
- immutable period versionにも保存
- 表示は `第23期｜2026年度`
- 既存期間名から期数を推測・backfillしない
- Approval Snapshot schema v2で期数を固定
- 過去Revision / Snapshotは変更しない

### HR-09｜Approval / Effective Lifecycle

ApprovalとEffectiveを独立評価します。

| Approval | Date relation | Human label |
|---|---|---|
| Draft | any | 作成中 / 未承認 |
| Approved | start前 | 承認済み / 開始前 |
| Approved | start–end | 承認済み / 現在有効 |
| Approved | end後 | 承認済み / 終了 |

- 評価timezoneは `Asia/Tokyo`
- Organization固有timezoneとは表現・実装しない
- `current_approved_revision_id`をEffective判定へ流用しない
- Source output schema v2へ `effective_status` と `fiscal_term_number` を追加

### HR-05｜明示的な並び替え

Theme / Priority / Department Policy / Department Statementへ ↑ / ↓ を追加しました。

- 既存 `sort_order` contractを利用
- Schema追加なし
- DOM移動後に全name indexを再構成
- public IDは維持
- Draft保存前にも再index
- 過去の承認済みSnapshotは変更しない

### HR-01 / 02 / 03 / 06 / 07｜Human UX

- 正式版の共有範囲と方針づくりの担当者を別sectionで表示
- Purpose / Background / Policyを日本語の問いと説明へ変更
- Theme / Priorityを重点テーマ / 優先方針として表示
- Group 0件時にEmpty Stateを表示
- Draft保存後に部署・グループ設定へ進み、固定された戻り先へ戻れるContractを追加
- redirect先はallowlist値だけを受理し、任意URLは拒否
- 内部Permission Model、Owner bypass、capability条件は変更なし

### HR-11｜Reader-first

正式Revisionを管理フォームではなく一つの経営指針として読むReaderを追加しました。

- 第○期 / 年度 / 期間
- Purpose
- Background
- Policy
- 重点テーマ
- 優先方針
- 部署方針

管理操作はReader後方の独立領域へ分離し、編集 / 承認 / 共有・担当者 / 履歴 / 関連付けへ遷移します。

## 3｜Migration

New migration:

`2026_10_03_000003_add_fiscal_term_number_to_management_periods.php`

判定: **additive**

- nullable column 2件
- current periodへのOrganization scoped unique index 1件
- existing row update 0
- inferred backfill 0
- destructive data conversion 0

通常local SQLiteへの適用: **0 / SEPARATE HUMAN APPROVAL REQUIRED**

## 4｜Automated Verification

| Verification | Result |
|---|---|
| 35A focused SQLite | **24 tests / 129 assertions PASS** |
| Connected Regression | **69 tests / 627 assertions PASS** |
| Release Hardening targeted | **4 tests / 29 assertions PASS** |
| MariaDB | **10.11.19 / utf8mb4 / utf8mb4_unicode_ci PASS** |
| MariaDB workflow | full migrate, 35A workflow, 3 Migration rollback, baseline preservation PASS |
| Blade compile | PASS |
| Annual Policy routes | 17 |
| New PHP files Pint | PASS |
| PHP / JS syntax | PASS |
| diff check | PASS |

MariaDBは専用loopback・GUID-scoped TEMP datadir・専用schemaで検証しました。Production connection / mutationは0です。

## 5｜Desktop / 390px Browser Verification

隔離SQLiteとlocalhostだけを使用して実Browser Verificationを実施しました。

| Screen | Desktop content gap | 390px content gap |
|---|---:|---:|
| Company Home | 24px | 16px |
| Quick Capture | 24px | 16px |
| Capture Inbox | 40px | 32px |
| Annual Policy Directory | 24px | — |
| Annual Policy Reader | 24px | 16px |
| Annual Policy Editor | 24px | 16px |
| Annual Policy Permission | — | 16px |

Capture Inboxの値は既存先頭paragraph marginを含み、共通Main位置自体は同一です。

確認済み:

- shared Shell 3-area grid
- horizontal overflow 0
- HTTP 5xx 0
- external request 0
- `第23期｜2026年度`
- `承認済み / 開始前`
- Reader before management tools
- Human-language labels
- Theme reorder persistence
- Draft reorder後もapproved immutable Revision不変

## 6｜Full Suite Classification

Full suite observed:

- **744 PASS**
- **17 SKIP**
- **12 FAIL**
- **6,372 assertions**

今回のMigrationによりrepository countが112から113へ増えた1件は期待値を正当に更新し、targeted Release HardeningはPASSしました。残る11件は次の既存・外部状態です。

1. IR-1 G2 v2 execution: 7件。既に実行済みのone-shot Production Evidence directoryが存在するため、未実行前提testがfail closed。
2. Company Navigation: 1件。stale intended URLの既知baseline。
3. Scope 9 Action Execution: 3件。現在日付に依存する既知fixture drift。
4. 17 SKIP: approved isolated MariaDB RG02 profileまたはclosed real-provider gate。

これらのEvidence directory削除、既存Scope 9修正、Navigation変更は本Correctiveのscope外であり、実施していません。35A Focused / Connected / Browser / MariaDB境界へ入るfailureは0です。

## 7｜Preserved Boundaries

- Permission Contractを弱めない
- Owner bypass追加0
- 過去Revision / Snapshot変更0
- 期数推測backfill 0
- Scope 35 Retrieval実装0
- Scope 36 / 37実装0
- Production connection / Deploy / Migration 0
- 通常local DB migration 0
- HR-10 corrective 0
- H01–H12を自動testでHuman PASS扱いしない

## 8｜Human Review Remaining

通常local DBへ新Migrationを適用する別承認後、Humanが少なくとも次を再確認します。

1. Reader-firstの読みやすさ
2. Editorの日本語ラベルと入力導線
3. 正式版共有 / 方針担当者の理解しやすさ
4. 期数表示
5. Approved / Upcoming / Current / Past
6. Theme / Priority / Departmentの↑ / ↓
7. Department 0件のDraft保全と戻り導線
8. Desktop / 390pxの縦余白

Human確認まではFormal Closeしません。

# **35A HUMAN PRODUCT REVIEW CORRECTIVE / CODE COMPLETE**
