# Company OS PUX-A P0 Audit

監査日: 2026-09-21 JST

対象: PUX-A｜利用会社資格・入口UX

判定: PUX-C01〜03に該当する停止条件なし（承認済み最小adapterをEvidence化して継続）

## 1. 固定点と正本

- Repository: `C:\xampp\htdocs\rise-gate-os`
- Branch: `master`
- P0開始HEAD / `origin/master`: `1856a6daa9a25270dd4c409bb1ac3ad9312b66c0`
- 開始時worktree: clean
- 最新Master PPT SHA-256: `08c5573f5d4522c8053c181e2a4b69ec4aeac328698fe4159e736c7923af01d3`
- 最新Master Excel SHA-256: `2b2d48efdac94cb2187325784b39c6a564386ec828788212e9e11258c54fa5f9`
- 実装指示: `CompanyOS_PUX-A_Codex実装指示書_v001.md`
- 実装準備: `CompanyOS_Ver1_ProductUX差分_実装準備書_v001.md`
- S1〜S7 Final Close ReportをClosed Contractとして参照した。PUX-B / Scope 8は未着手。

## 2. 実行環境とfresh baseline

- PHP: 8.2.12
- Laravel: 12.63.0
- Composer: 2.10.2
- Application timezone: `Asia/Tokyo`
- Test DB: SQLite `:memory:`
- 通常local DB: SQLite 3.39.2 / `database/database.sqlite`
- Node.js: VS Code同梱 Node v24.18.1（PATH上のNode導入や環境変更は未実施）
- P0 fresh baseline: `php artisan test` = **433 tests / 3,602 assertions / 失敗0 / 144.25s**

過去Reportの件数は転記せず、上記を今回のRepositoryで取得した。

## 3. P0時点のDB / Migration

- 通常local DBはScope 1〜7のMigrationがRan。
- PUX-A開始時に資格表は存在しなかった。
- PUX-A Migrationはadditiveな新規1本として設計し、通常local DBへは適用しない。
- Production DBへの接続、照会、Migration、Deployは行わない。
- 実Data inventoryはRelease Gate PUX-RG01、本番相当engine競合はPUX-RG02として分離する。

## 4. runtime writer / entry inventory

| Entry | 既存責務 | PUX-A接続点 | lock / final check |
| --- | --- | --- | --- |
| 初回Bootstrap | 最初のUser / Organization / Workspace | single資格を同じtransactionで記録 | 資格行作成と業務作成をatomic化 |
| S4 Staff Invitation prepare | invited Membership準備 | 早期資格確認のみ。bindingしない | 最終acceptの代替にしない |
| S4 Staff Invitation accept | active化 / Group / standard Workspace / accepted | 資格行を最外側でlockして最終再判定 | 資格→Org→Invitation→Membership順 |
| S6 Owner prepare | 本人・同意・case確認 | 新Organization開始可否を早期確認 | 最終completeの代替にしない |
| S6 Owner complete | Org / Owner / standard Workspace / result | 資格行最外側lock、同一Org completed retryはNOOP | 資格→case→issuer→新Org順 |
| System Admin Workspace membership | 既存AccountのMembership管理 | 最初の社だけatomic bind。同一社管理を維持 | 別社、inactive、非active所属を拒否 |
| Client会社化 | Clientから新Organization作成 | PUX-A有効時の通常UI / POST / Serviceを停止 | 既存linkは保持 |
| Operation Seeder | 開発fixture作成 | testingまたは明示envの隔離fixtureだけ | 通常実利用DBの入口にしない |
| Login / companies / SA exit | current Company解決 | 共通Resolverを利用 | Membership status / epochを毎回再評価 |

検索対象にはController、Service、Seeder、Console command、Route、Testからの直接呼出を含めた。Model Observerによる暗黙grantは追加していない。

## 5. lock graph / atomicity

共通順序は次のとおり。

```text
ProductAccountEligibility(user) FOR UPDATE
  -> entry固有の既存lock
  -> 最新資格・対象User・対象Organizationを再判定
  -> Membership / Organization / Workspace等の業務write
  -> unstarted -> single の条件付きupdate
  -> private Account Audit
COMMIT
  -> Session / redirect
```

- S4: 資格 → Organization → Invitation → sponsor / User / Membership → Group / standard Workspace
- S6: 資格 → onboarding case → issuer → Organization / Owner Membership / standard Workspace
- System Admin: 資格 → target User / Organization Membership → Workspace Membership
- SQLiteではrow lockだけに依存せず、条件付きupdate、短いbounded retry、独立接続Testでfail-closedを確認する。
- actor / sponsorのProduct資格行は追加lockしない。

## 6. 互換分類方針

- `0 membership`、Membership外にWorkspace / Project / completed onboarding footprintがある不整合は `review_required`。
- statusにかかわらず所属履歴が1 Organizationだけなら `single`。suspended / left / invitedをactiveとして扱う意味ではない。
- 複数Organizationの所属履歴は `legacy_multi` とし、cutoff時のMembership IDだけを互換参照に固定する。
- active件数、会社名、Email domainだけでは分類しない。
- 行欠落を `unstarted` と推測しない。

## 7. 承認済み最小adapter Evidence

### S6 adapter（ユーザー承認）

1. S6 `prepare` で新Organization開始可否を早期確認する。
2. 完了済みOwner Onboarding再送は、同一Organizationへ収束するidempotent NOOPとする。

S6の本人確認、同意、Token、原子性、最小権限、Organization / Membership / standard Workspace作成Contractは維持する。最終`complete`は必ず資格行をlockして再判定し、異なるOrganizationをNOOP扱いしない。

### PUX-C02互換adapter（ユーザー承認）

`review_required`または資格行欠落の既存Userについて、既存のactive Organization Membershipだけを従来認可の範囲で解決する。

- active 1社: その会社へ進む。
- active 複数社: 既存active Membershipから明示選択する。
- active 0社: 確認・状態案内へ進む。
- invited / suspended / leftは利用可能会社に含めない。
- 新しいOrganizationの作成・参加は拒否する。
- Product資格からPermissionを付与しない。
- S4 / S6 / System Admin等の最終資格判定は緩和しない。
- 行欠落を`unstarted`と推測せず、既存Membershipを変更・削除しない。

このadapterにより既存認可利用を保護でき、通常multi商品を新規提供することなくPUX-C02を解消できると判定した。

## 8. 停止条件判定

| ID | 判定 | 根拠 |
| --- | --- | --- |
| PUX-C01 | 非該当 | 専用資格行を最外側でlockでき、SQLite独立接続でも条件付きwriteとretryにより1社へ収束可能。 |
| PUX-C02 | 承認済みadapterで解消 | 既存active Membershipだけを保護し、新規admissionとPermissionは開放しない。 |
| PUX-C03 | 非該当 | 既存Dataを更新・統合・削除せず、additive metadata 2表と保留状態で実現可能。 |

P0結果として重大互換Blockerはなく、P1〜P5へ進行可能とした。

## 9. P0で変更しないもの

- S1〜S7のDomain / Permission / History Contract
- Organization Role、Group、Workspace、Project Permissionの派生規則
- Cross-Organization Projectの既存認可
- S2 Account / credential generation、S5 membership epoch
- PUX-BのBusiness Domain Read / Manage UI
- Scope 8
- 通常local / ProductionのMigration適用状態
