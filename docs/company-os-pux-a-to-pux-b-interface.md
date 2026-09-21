# PUX-A → PUX-B Interface

作成日: 2026-09-21 JST

目的: PUX-BがPUX-Aの利用会社・Session・Layout境界を再実装せず、Business Domain表示だけを安全に接続するための引継ぎ。

## 1. Feature activation

- Flag: `config('product_ux.organization_admission_enabled')`
- Environment: `PRODUCT_ORGANIZATION_ADMISSION_ENABLED`
- Default: `false`
- PUX-RG01 / RG02通過前は有効化しない。
- 無効時はS1〜S7の既存active Membership解決を維持する。

## 2. Product資格の正本

- `product_account_eligibilities`: Userごとに1行。
- mode: `unstarted` / `single` / `legacy_multi` / `review_required`。
- `single`だけが`product_organization_id`を持つ。
- `product_organization_compatibilities`: legacy cutoff時のOrganization Membership参照。Permission grantではない。
- 資格行欠落は未開始を意味しない。

PUX-Bはこれらを表示権限、Business Domain editor権限、Organization Roleの代替として使わない。

## 3. Resolver contract

`ProductOrganizationResolver::resolve(User)`は以下を返す。

| key | 内容 |
| --- | --- |
| `mode` | Product mode。flag無効時は`legacy_passthrough`。 |
| `state` | `ready` / `selection` / `unstarted` / `suspended` / `left` / `review_required`。 |
| `organizations` | 現在利用可能な既存active Organizationだけ。 |
| `organization` | `ready`時の1社。その他はnull。 |
| `membership` | `ready`時のactive Organization Membership。 |
| `eligibility` | flag有効かつ行がある場合のmetadata。 |

`review_required`または行欠落では、既存active Membershipが1社なら`ready`、複数なら`selection`、0社なら`review_required`となる。これは既存利用保護adapterであり、新規admission許可ではない。

## 4. Login / entry order

```text
global is_active / credential_generation
  -> valid S6 Owner claim
  -> valid S4 Invitation claim
  -> ProductOrganizationResolver
  -> current Membership / access_epoch
  -> Company Home or /companies state page
```

- 通常single + activeは`/company`へ直行する。
- 通常singleに会社切替UIを出さない。
- legacy / review互換の複数activeだけ`/companies`で明示選択する。
- 0 activeでもAccount / Logoutへ到達可能で、redirect loopにしない。
- System Admin退出も同じResolverを利用する。

## 5. Session contract

PUX-Bが維持すべき主なSession key:

- `access_mode`
- `current_company_id`
- `current_workspace_id`
- `current_company_access_epoch`
- `credential_generation`

`OrganizationSessionContext`を経由して会社を選択・clearする。staleな非safe requestを別会社へ付け替えて続行してはならない。PUX-A有効時は409、safe GETは最新資格とMembershipで再評価できる。

## 6. currentCompany / Layout contract

`company` middleware通過後:

- Request attribute: `currentCompany`
- Shared view data: `currentCompany`
- Shared view data: `availableCompanyCount`
- Shared permission flags: `canViewCompanyFinance` / `canManageCompanyMembers` / `canManageOrganization` / `canViewCompanyDebt`

Headerは`availableCompanyCount > 1`の時だけ会社切替を表示する。PUX-BはLayout componentからDB queryやProduct資格判定を開始せず、middlewareが確定した`currentCompany`と既存`BusinessDomainAccess`を利用する。

## 7. Route / state interface

| Route | PUX-A責務 | PUX-Bでの扱い |
| --- | --- | --- |
| `login` | claim優先後に利用会社を解決 | 変更しない |
| `companies.index` | 選択または状態案内。readyならHomeへ正規化 | Domain入口に流用しない |
| `companies.switch` | legacy / review互換のactive範囲だけ | Domain権限を付与しない |
| `company.home` | 確定したcurrentCompanyのHome | Business Domain入口を置く既存面 |
| `business-domains.*` | S7の既存Route / Access / Query / Writer | PUX-BでRead / Manage UXを整理する対象 |
| `account.profile` | 0社・停止・終了でも到達可能 | 状態回復導線を隠さない |

## 8. Permission invariants

- Product資格はOrganization / Workspace / Project / Financial / AI / Business Domain Permissionを付与しない。
- Business DomainのRead / Edit / HistoryはS7 `BusinessDomainAccess`を正本とする。
- active Ownerは既存Contractどおりgrant不要で編集可能。
- Admin / Memberは明示editor grantが必要。
- invited / suspended / left / global inactive、他社Staff、共同Project参加だけのUser、非所属System AdminにはCompany Contextを与えない。
- Cross-Organization Projectは本人のProject Membershipと参加Workspace側のactive Organization Membershipで評価し、Project所有OrganizationをProduct社に強制しない。
- AI Key / context / category guardを広げない。

## 9. PUX-Bが変更してはならないもの

- 資格2表のmode・binding・互換参照
- admission lock順、S4 / S6 / System Adminの最終資格判定
- Session keyとepoch / credential generationの責務分離
- S7のBusiness Domain ID、Revision、Direction、items / attributes、display order
- 通常local / Production Migration状態
- PUX-A release flag

## 10. PUX-B開始時の確認事項

1. PUX-A実装CommitとFinal Close Reportを固定する。
2. `company` middleware後の`currentCompany`でRead / Manage routeを確認する。
3. S7 `BusinessDomainAccess`、`BusinessDomainQuery`、既存Writerを再利用する。
4. ReadでHistory payloadやwrite用Formを取得しない。
5. Desktop / 390px / Print / Permission負例を新しく取得する。
6. PUX-RG01 / RG02未達をPUX-B完了と混同しない。

PUX-Bのコードは本書作成時点では追加していない。
