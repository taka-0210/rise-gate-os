# Company OS PUX-B Final Close Report

作成日: 2026-09-21 JST

対象: PUX-B｜Company Context Presentation

状態: **Code Close**

## 1. Close summary

- PUX-B実装Commit: 80347fb56e93ccc636cbfc205be97e769779cd67
- 基準HEAD / PUX-A Report Commit: 19f8d63c95082d0033f0fa00d57dc624a9b6bf63
- Branch: master
- PUX-B-DC-01〜20: **Done 20 / Conditional 0 / Not Done 0**
- Full Test: **475 tests / 3,941 assertions / failures 0**
- Frontend Build: **成功**（Vite 7.3.6 / 58 modules / 883ms）
- Browser: **成功**（Desktop 1280px / mobile 390px / A4 Print PDF / HTTP 5xx 0）
- 新規Migration: **0**
- 通常local DBへのMigration適用: **未実施**
- Production DB / Migration / Deploy: **未実施・未接続**
- PUX-A Release Gate RG01 / RG02: **未実施のまま維持**
- Scope 8: **未着手**
- 未解決の実装重大問題: **0**

Code CloseはProduct有効化、Production Release、高見による実利用受入を意味しない。PUX-RG01〜04は後述のRelease Gateとして分離する。

## 2. できるようになったこと

1. Business Domainの通常入口を、active Staffが会社の事業を読むCompany Context Readへ変更した。
2. 事業名と概要を主役にし、WHAT / WHO / VALUE / WHERE / POSITION、強み、Direction、明細・属性を章として読める。
3. 空Sectionを省略し、名前だけの事業領域でも管理画面の空欄一覧に見えない。
4. 0件時は「事業領域の情報は準備中です」と表示し、通常利用を妨げない。
5. ReadとManageを別Route・別Viewへ分離した。
6. Manage一覧へ追加、表示順、保管済み一覧、編集担当導線を集約した。
7. Manage詳細へ保管 / 再開、詳細Revision履歴、Edit導線を集約した。
8. Edit保存後はReadへ戻り、現在値をCompany Contextとして確認できる。
9. archivedの現在値はReadでき、Editは再開まで409のまま維持した。
10. Browser PrintでNav、編集・管理導線、Form、Historyを除き、会社名・事業名・本文・保管表示をA4 PDFへ出力できる。
11. Desktopと390pxで長文・長いURL・明細・属性を折り返し、横overflowを防げる。
12. active Owner、editor、一般Staffの既存Permission境界を一切緩和せず利用できる。

## 3. KEEP / REFACTOR / ADD / DEPRECATE

### KEEP

- Business Domain、items、attributes、Revision、Operation、display_orderの既存正本とID
- BusinessDomainAccess / Query / Writer / GrantManager / ReferenceService
- request_id、expected_version、change_reason、stale、idempotency、transaction、Audit
- Direction 5区分とdirection_memo原文
- status / q / per_page / paginationとactive既定
- S1〜S7、PUX-AのPermission / Product資格 / Session Contract

### REFACTOR

- 既存index/showを通常ReadのPresentation責務へ限定
- 既存の並替、状態変更、詳細History表示をManageへ移動
- archive / reopen / move後の戻り先をManageへ変更
- 既存Scope 7 Browser acceptanceをRead / Manage分離後の導線へ更新

### ADD

- GET /company/business-domains/manage
- GET /company/business-domains/manage/{businessDomain}
- 固定Company Context Blade Pattern 7部品
- Company Context専用のresponsive / print CSS
- PUX-B固有Feature / Browser / Print / Permission Evidence

### DEPRECATE

- 通常Read内の並替Form、保管 / 再開Form、詳細Revision一覧
- 通常Read Headerの技術的Revision番号
- 空Sectionを「未登録」として全件表示するPresentation

Writer、DB正本、既存Route payloadは廃止していない。

## 4. Phase結果

### PUX-B-P0｜Evidence reuse / 接続監査

- PUX-A Close後のHEADとorigin/master一致、clean worktreeを確認した。
- PUX-A Close ReportとA → B Interfaceを再利用し、PUX-A全体再監査は行わなかった。
- Business Domain focused baseline: 19 tests / 206 assertions / failures 0。
- PUX-C01〜03は非該当。
- Evidence: docs/company-os-pux-b-p0-audit.md

### PUX-B-P1｜Route / Pattern

- 静的Manage Routeをvariable bindingより前に追加した。
- 固定Blade Patternを追加し、部品内にDB queryやrole判定を持たせなかった。
- company-context-read-pageだけへ効くPrint境界を追加した。

### PUX-B-P2｜Read

- index/showをEditorial Readへ変更した。
- Read showからRevision query、状態変更request ID、管理Formを除去した。
- 空Sectionを省略し、Directionと明細種別を日本語Presentationへ変換した。
- Contentコピー、新Schema、推測補完は追加していない。

### PUX-B-P3｜Manage

- Manage一覧に追加、並替、保管状態、編集担当を集約した。
- Manage詳細に状態変更と20件paginationのRevision履歴を集約した。
- 保存はRead、管理操作はManageへ戻るよう整理した。
- Writerとrequest payload Contractは維持した。

### PUX-B-P4｜Verification

- Read query / DOM / GET無変更、Permission matrix、Tenant負例をFocused Testへ追加した。
- Business Domain / Company Navigation / PUX-A / S4 / S6のRelated Regressionを実行した。
- 隔離SQLite + Laravel local server + Chrome headlessでDesktop、390px、keyboard、Print、Permission負例を確認した。
- 画面を目視確認し、奇数個の観点で生じる空き枠を全幅表示へ修正した。

### PUX-B-P5｜Code Close

- PUX-B-DC-01〜20をEvidenceへ対応付けた。
- 実装Commitを固定し、本Final Close Reportを作成した。
- Production、通常local Migration、PUX-A Release Gate、Scope 8へ進まない。

## 5. Test / Build / Browser Evidence

| 区分 | Command / 条件 | 結果 |
| --- | --- | --- |
| P0 focused baseline | BusinessDomainTest | 19 tests / 206 assertions / failures 0 / 2.99s |
| PUX-B focused final | BusinessDomainTest | 25 tests / 268 assertions / failures 0 / 4.42s |
| Related Regression | Business Domain + Navigation + PUX-A + S4 + S6 | 80 tests / 845 assertions / failures 0 / 14.17s |
| Full Test | artisan test | **475 tests / 3,941 assertions / failures 0 / 247.23s** |
| Format | Laravel Pint（変更PHP） | passed |
| Blade | artisan view:cache | success |
| Route | artisan route:list --name=business-domains | 15 routes / static manage衝突なし |
| Diff | git diff --cached --check | errorなし |
| Build | VS Code同梱Node + Vite 7.3.6 | exit 0 / 58 modules / 883ms / stderr 0 |
| Browser | isolated SQLite + Chrome headless | exit 0 / HTTP 5xx 0 / server stderr 0 |

Browserで確認した内容:

- Owner login、Read index、Read detail
- Edit保存後に同一Readへ戻る
- Desktop 1280 x 1000のEditorial hierarchy
- mobile 390 x 844の1 columnと横overflowなし
- 長文、長いURL、Item、Attributeの折返し
- keyboard focusとvisible outline
- Manage History / state Form
- archiveした現在値のReadと保管表示
- Browser PrintでNav / action / Form / History非表示
- A4 PDF生成、本文・保管表示保持
- reopen
- editor解除後もRead可、Manageは403

最終Browser Evidence:

- Desktop SHA-256: DF7544C94836967C46D0964E1D1144AB8C24CA2EAA822717A630E170436CA9DF
- Mobile SHA-256: 2ADCB6DC7778A7F09549ECC1EFFA10C17B74BE39CCEBAE0770B57595A103BDC6
- Print screenshot SHA-256: F48409CAFC6D417FE0B5D09A03C6ECFC5D3377CA01EDE65A326ACC4114ADC779
- A4 PDF: 164,470 bytes
- A4 PDF SHA-256: AD8CBA4158BCF14D3AAE77A2420A05DF549CD2CE7DA0D321FF83A60AC90B17A6

Browser fixture、SQLite、server log、screenshot、PDFはPUX-B専用temp prefixで生成した。通常local DBはBrowser確認に使用していない。

## 6. Migration / Data / rollback Evidence

### Schema / Data

- PUX-B新規Migration: 0
- 新Table / Column / Index: 0
- Contentコピー / backfill / 推測補完: 0
- 物理削除: 0
- Business Domain、item、attribute、Revision、Operationの既存ID / Relationをそのまま利用した。
- Read / Print GET無変更Testでversion、display_order、status、updated_at、Revision、Operation、Auditが増減しないことを確認した。

### 通常local DB

- migrate:status: Scope 1〜7はRan、PUX-A migration 2026_09_21_000008はPending。
- PUX-B用Migrationは存在しない。
- 通常local DBへartisan migrate、migrate:fresh、inventory applyを実行していない。
- Browserは専用temp SQLite、PHPUnitはsqlite :memory:または既存のguard付きtemp fixtureを使用した。
- Evidence checkpoint size: 1,261,568 bytes
- 論理fingerprint baseline SHA-256: 17C5B7B09F924D6F85BC34B44412ADDDAC912DC5192B516410668049AD720DCA
- Report Commit前後で82既存Tableを比較し、新Table 0、Business / Foundation Table差分0を確認した。
- 差分はruntimeのsessions 1行のpayload / last_activityだけだった。通常localを同時利用するSession更新として分離し、Business Data不変の判定対象から除外した。
- sessions更新によりSQLite物理hashは変化するため、物理hash同一性をData保持Evidenceには用いない。

PUX-B開始前の通常local論理fingerprintは取得していないため、PUX-A時点からのbyte同一性は保証対象にしない。今回保証するのは、PUX-Bが通常local向けMigrationやBusiness Data writerを追加・実行せず、Browser / Testを隔離したこと、およびReport Commit前後でsessions以外の既存81 Tableが論理的に同一だったことである。

### rollback / 切戻し

- DB rollbackは不要。PUX-B Schema変更がない。
- Code切戻しはReport Commitを除外し、実装Commit 80347fb56e93ccc636cbfc205be97e769779cd67を通常のGit revert対象とする。
- 切戻しでBusiness Domain Data、Revision、display_order、Editor Grantを変換・削除しない。
- ProductionへのMigration / Deploy / rollback操作は行っていない。

## 7. Permission / Security

| User状態 | Read | Edit / Manage / History | Editor grant管理 |
| --- | --- | --- | --- |
| active Owner | 可 | grantなしで可 | 可 |
| active Admin + editor | 可 | 可 | 不可 |
| active Member + editor | 可 | 可 | 不可 |
| active Admin / Member grantなし | 可 | 403 | 不可 |
| invited / suspended / left | 不可 | 不可 | 不可 |
| global inactive | 不可 | 不可 | 不可 |
| 他Organization Staff | 対象Domain 404 | 404 | 不可 |
| Project共同参加のみ | 不可 | 不可 | 不可 |
| Organization非所属System Admin | 不可 | 不可 | 不可 |

- Product資格からBusiness Domain Permissionを付与していない。
- Readはactive Organization Membershipの既存認可だけを使用する。
- ManageとHistoryは既存BusinessDomainAccessのedit / history境界を使用する。
- ReadではRevision payload、state request ID、管理Formを取得・描画しない。
- 他社public IDの直接URLでもDomain名、件数、Historyを漏らさない。
- XSS対象文字列はBlade escapingを維持する。
- Auditの内容秘匿とJST表示を維持する。

## 8. 保証したこと / 保証していないこと

### Testで保証したこと

- Read / Edit / ManageのRouteと責務分離
- 通常Readに管理Formと詳細Historyがないこと
- OwnerのReadでもbusiness_domain_revisions queryを発行しないこと
- 既存Writer payload、Revision、stale、idempotency、transaction、Audit
- active / archived、count tabs、q、status=all、per_page、pagination、display_order
- 空Section、名前だけ、長文、記号、XSS escape、明細・属性
- Permission正例 / 負例とTenant境界
- GET / PrintのData無変更
- Desktop、390px、keyboard、Print PDF
- Company Home、PUX-A入口、S4 Invitation、S6 Owner Onboardingの関連回帰

### 今回保証していないこと

- 公開URL、guest閲覧、外部Client共有
- Server side PDF生成API
- Browser以外の独自帳票生成
- Vision / Philosophy / Strategy等へのCompany Context展開
- SWOT、分析、数値目標
- Product有効化、本番相当DB競合、本番Deploy
- 高見による実Data・実利用の最終受入

## 9. Release Gate / Pending

| Gate | 状態 | PUX-B Close時点 |
| --- | --- | --- |
| PUX-RG01 実Data互換棚卸し | 未実施 | PUX-A Release Gateとして維持 |
| PUX-RG02 本番相当DB競合 | 未実施 | PUX-A Release Gateとして維持 |
| PUX-RG03 Context UI実利用受入 | 未達 | 実装担当Browser Evidenceは完了。高見の実利用受入は別工程 |
| PUX-RG04 既存Release条件 | 継続 | Mail / 法務 / Production DB / S7本番DB競合 / Hardeningを維持 |

非Blocker Pending:

- 高見による実DataでのRead / Edit / Manage / 390px / Print受入
- Browser / OS / printer差によるPrint最終確認
- 将来、同じCompany Context Patternを理念等へ展開するかのProduct判断

PUX-B固有の未完実装、未解決Security / Permission問題、Data Migration課題はない。

## 10. Done Conditions

| DC | 判定 | Evidence |
| --- | --- | --- |
| PUX-B-DC-01 | Done | A Close Commit / Report / A→B Interfaceを再利用し、開始HEAD・差分・Access / LayoutをP0 Auditへ固定。 |
| PUX-B-DC-02 | Done | 既存index/showをRead化。一般Staff / Owner / editor Browser・Feature確認。 |
| PUX-B-DC-03 | Done | Manage一覧・詳細へ追加、順序、状態、editor、Revisionを集約。既存Writer到達Test。 |
| PUX-B-DC-04 | Done | Readから並替・状態Form・詳細History query / payloadを除外。Feature / Browser / Print確認。 |
| PUX-B-DC-05 | Done | 既存write Routeとpayloadを維持。静的manage順序をroute:list / Testで確認。 |
| PUX-B-DC-06 | Done | Owner / editor / grant管理matrix、降格・解除回帰を確認。 |
| PUX-B-DC-07 | Done | inactive / 他社 / non-editorの直接URL負例。名称・History非漏洩を確認。 |
| PUX-B-DC-08 | Done | save→Read、Cancel無変更、archived Read、Edit 409、reopenを確認。 |
| PUX-B-DC-09 | Done | 既存正本・ID・Revision・Direction・items / attributes・display_orderのみ使用。新Schemaなし。 |
| PUX-B-DC-10 | Done | Direction 5区分の既存modelを維持し、label / description / memoを表示。exit_plannedとarchivedを分離。 |
| PUX-B-DC-11 | Done | active既定、archived、count tabs、q / all / per_page / pagination、display_order回帰。 |
| PUX-B-DC-12 | Done | 空Section省略、0件、名前だけ、長文、日本語、記号、明細、XSSを確認。 |
| PUX-B-DC-13 | Done | ReferenceService / AI DTO / category / search Permissionを無変更。既存回帰成功。 |
| PUX-B-DC-14 | Done | 固定Blade Pattern 7部品とscoped CSS。部品にDB query / role判定なし。 |
| PUX-B-DC-15 | Done | 390px横overflowなし、1 column、長文折返し、keyboard focus / 見出し順をBrowser確認。 |
| PUX-B-DC-16 | Done | 認証済みReadからA4 PDF生成。Nav / action / Form / History非印刷、本文・保管表示保持。 |
| PUX-B-DC-17 | Done | Read / Print GETのDB fingerprint無変更、既存Audit / JST回帰。 |
| PUX-B-DC-18 | Done | Company Home / S6 / Invitation / PUX-A関連回帰成功。Domain入力必須化・Personal一般化・S8なし。 |
| PUX-B-DC-19 | Done | Full Test 475 / 3,941、Build、Desktop / 390px / Print Evidence。RG03 / RG04を別記。 |
| PUX-B-DC-20 | Done | DC-01〜19をEvidenceへ対応付け、実装CommitとFinal Close Reportを作成。 |

Final: **Done 20 / Conditional 0 / Not Done 0**

## 11. 主要ファイルと責務

| File | 責務 |
| --- | --- |
| app/Http/Controllers/BusinessDomainController.php | Read payload削減、Manage GET、管理操作redirect |
| routes/web.php | 静的Manage Routeをbinding前へ追加 |
| resources/views/business-domains/index.blade.php | Editorial Read一覧 |
| resources/views/business-domains/show.blade.php | Editorial Read詳細 |
| resources/views/business-domains/manage.blade.php | 管理一覧、並替、追加、editor導線 |
| resources/views/business-domains/manage-show.blade.php | 状態変更、Edit、Revision履歴 |
| resources/views/business-domains/partials/form.blade.php | 既存Edit Form Contractを継続利用 |
| resources/views/components/company-context/*.blade.php | 固定Presentation Patternと局所CSS / Print |
| resources/views/layouts/app.blade.php | Readページだけを識別するPrint class |
| tests/Feature/BusinessDomainTest.php | Read / Manage / Permission / query / Data不変 |
| tests/Browser/company-context-presentation-acceptance.mjs | Desktop / 390px / keyboard / Print / Permission |
| tests/Support/business_domain_presentation_browser_setup.php | guard付き隔離Browser fixture |
| tests/Browser/business-domain-acceptance.mjs | Scope 7 Browser journeyのPUX-B導線追従 |
| docs/company-os-pux-b-p0-audit.md | P0差分監査、停止条件、検証方針 |

## 12. Final conclusion

Business Domainは、既存の厳密な正本・Permission・Writerを維持したまま、通常Staffが会社の事業を「次世代の経営指針書」として読むCompany Context Presentationへ移行した。

Read / Edit / Manageの責務を分離し、通常Readを軽く保ちながら、管理機能・History・Revision・Auditを失っていない。PUX-AおよびS1〜S7のClosed Contractは維持され、PUX-BはCode Closeできる。

次工程は高見のPUX-RG03実利用受入または別途指示されたRelease工程であり、この作業ではProductionおよびScope 8へ進まない。
