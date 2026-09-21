# Company OS PUX-B P0 Audit

監査日: 2026-09-21 JST

対象: PUX-B｜Company Context Presentation

判定: **PUX-C01〜03に該当する停止条件なし。PUX-B-P1〜P5へ進行可能。**

## 1. 固定点と正本

- Repository: C:\xampp\htdocs\rise-gate-os
- Branch: master
- P0開始HEAD / origin/master: 19f8d63c95082d0033f0fa00d57dc624a9b6bf63
- P0開始時worktree: clean
- PUX-A実装Commit: e092e33b2a82294fa44e1fa7766b6b6d02b4e678
- PUX-A Report Commit / PUX-B基準HEAD: 19f8d63c95082d0033f0fa00d57dc624a9b6bf63
- PUX-A Close: DC-01〜24 Done 24 / Conditional 0 / Not Done 0
- PUX-A Close Test: 469 tests / 3,879 assertions / failures 0
- Master PPT SHA-256: 08C5573F5D4522C8053C181E2A4B69EC4AEAC328698FE4159E736C7923AF01D3
- Master Excel SHA-256: 2B2D48EFDAC94CB2187325784B39C6A564386EC828788212E9E11258C54FA5F9
- PUX-B指示書 SHA-256: 921C0D5AC6A1E398C6D60ABFB090F21305F415911A68239365688463AD032983

PUX-A全体の再監査は行わず、PUX-A Final Close ReportとPUX-A → PUX-B InterfaceをClose Evidenceとして再利用した。

## 2. 差分確認

- PUX-A Report Commitとorigin/masterは一致した。
- PUX-A Close後の想定外commitおよび未commit差分はなかった。
- S1〜S7、PUX-AのClosed Contractを再Openする必要はない。
- Product資格、Admission、Resolver、Session、epoch、互換adapterにPUX-B変更は不要。
- 通常local DB / Production DBへの接続・Migration・Deployは今回の範囲外。

## 3. Business Domain接続境界

| 境界 | P0確認 | PUX-B方針 |
| --- | --- | --- |
| 正本 | business_domains、items、attributes、revisions、operations | コピーせずそのまま利用 |
| Read認可 | BusinessDomainAccess::authorizeView | active Organization Membership境界を維持 |
| Edit / Manage認可 | Ownerまたは明示editor | authorizeEditを変更せずManage GETへ適用 |
| History認可 | authorizeHistory = edit境界 | 詳細履歴をManage詳細だけで取得 |
| Writer | create/update/archive/reopen/move | payload・lock・Revision・Auditを変更しない |
| Query | Organization scoped、status/q/per_page、display_order | Read/Manageで同じQueryを再利用 |
| AI / 内部参照 | BusinessDomainReferenceService | DTO/category/Permissionを変更しない |
| PUX-A | Product資格は利用会社入口の正本 | Business Domain Permissionを派生させない |

## 4. 現行Presentation差分

P0時点の通常index/showには、読む体験と次の管理責務が同居していた。

- index: 追加、編集担当、並替Form
- show: Edit、archive/reopen Form、詳細Revision query / payload / list
- show: 空Sectionを「未登録」で常時表示
- show: 技術的なRevision番号を通常Staff向けHeaderへ表示

次の最小分離で既存Contractを維持できると判定した。

~~~text
Read
  GET /company/business-domains
  GET /company/business-domains/{businessDomain}
      └ authorizeView / current values only

Manage
  GET /company/business-domains/manage
  GET /company/business-domains/manage/{businessDomain}
      └ authorizeEdit / authorizeHistory
      └ create / reorder / editor grants / archive / reopen / revisionsへの導線

Edit / Writer
  既存Route、Form、request_id、expected_version、change_reasonを維持
  保存後はReadへ戻る
~~~

静的/manageは/{businessDomain}より前に定義する。

## 5. P0 fresh focused baseline

Command: C:\xampp\php\php.exe artisan test tests/Feature/BusinessDomainTest.php

Result:

- 19 tests
- 206 assertions
- failures 0
- 2.99s

PUX-AのFull Test件数を今回の実行結果として流用していない。変更されていないClosed ScopeのEvidenceは再利用し、PUX-B接続面はFocused Testから開始した。

## 6. 停止条件判定

| ID | 判定 | Evidence |
| --- | --- | --- |
| PUX-C01 | 非該当 | PUX-BはProduct資格writer / lockへ接続せず、PUX-A Close差分もない。 |
| PUX-C02 | 非該当 | authorizeViewとauthorizeEdit/Historyを緩和せずRead / Manageを分離可能。Tenant境界の変更不要。 |
| PUX-C03 | 非該当 | 新Schema、Dataコピー、Data削除、不可逆Migrationなし。 |

## 7. P0後のRisk-based verification

- Focused: Business Domain Read / Manage / Writer / History
- Related regression: Company Home / Navigation、Organization / Membership、PUX-A入口、S6完了後Home
- Permission negative: non-editor、inactive Membership、他Organization、non-member
- Browser: Desktop、390px、keyboard/focus、HTTP 5xx
- Browser Print: A4/PDF、本文保持、Nav / Edit / Manage / Form / History非印刷
- Code Close時: Full Testを1回、Buildを1回

PUX-RG01 / RG02はPUX-A Release Gateとして未実施のまま維持する。PUX-B実装、隔離Test、Browser Evidenceは通常local / Production適用を意味しない。
