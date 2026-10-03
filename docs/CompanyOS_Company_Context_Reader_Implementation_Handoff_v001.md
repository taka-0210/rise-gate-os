# Company OS｜Company Context Reader Implementation Handoff v001

## 0. Handoff status

- Status: `IMPLEMENTATION WAITING`
- Architecture: `CompanyOS_Company_Context_Reader_Architecture_v001.md`
- Product Design: `CompanyOS_Company_Context_Reader_Product_Design_v001.md`
- Bound repository commit: `958a7b12865b12cbd587e49735cdd00111b69461`
- Bound Git tree: `70e304ebeca7b46ef8041eea8bdf490c87732bfd`
- This document does not authorize implementation, migration, DB mutation, Breadcrumb Corrective, Scope 35〜37, production, or deploy.

## 1. Delivery objective

別途Human承認後、既存の4正本をpermission-safeに合成し、「会社の言葉」を一冊として読めるGET-only presentationを実装する。既存Domain contractは変更しない。

Implementation Definition of Done:

- Chapter 01〜03は閲覧可能な各Current Official immutable revision。
- Chapter 04 defaultはJSTで現在のApproved / Effective annual revision。
- Upcoming / Pastは明示選択し、Chapter 01〜03がcurrentであることを表示。
- Draftは通常Readerに出ない。
- TOC / HTML / links / metadataにpermission leakがない。
- Motionなし、JSなし、reduced motionでも全文と操作が同等。
- Desktop / 390px / 320 reflow / keyboard / 200% zoomが成立。
- Human visual review前にFormal Closeしない。

## 2. Invariants — change prohibited

1. Reader用の正本、revision、approval、snapshotを作らない。
2. `ManagementDesignAccess` / `AnnualManagementPolicyAccess`を弱めない。
3. Owner / Manage view bypassを追加しない。
4. Existing snapshots / past revisionsを更新しない。
5. `current_approved_revision_id`をEffective判定へ流用しない。
6. Lifecycle timezoneは`Asia/Tokyo`。
7. 保存済みsort orderをReader側で評価・再排序しない。
8. Reader responseにDraftを混在させない。
9. Global Breadcrumb CorrectiveをReader実装に便乗して行わない。
10. Scope 35〜37、AI Write、Vision Image、Production、Deployへ進まない。

## 3. Proposed implementation map

名称はimplementation時の推奨であり、Product上のHuman-facing名称を固定しない。

### 3.1 Route / controller

- `GET /company/company-context`
- route name candidate: `company-context-reader.show`
- controller candidate: `App\Http\Controllers\CompanyContextReaderController`
- query candidate: `annual=<period-public-id>`。未指定はcurrent effective。raw DB IDは禁止。

Controller責務はrequest selection validation、Composer呼出し、View返却だけとする。Eloquent query、permission分岐、TOC生成をControllerへ置かない。

### 3.2 Application services

- `App\Services\CompanyContextReader\CompanyContextReaderComposer`
- `...\ManagementDesignOfficialResolver`
- `...\AnnualPolicyReaderResolver`
- `...\CompanyContextReaderViewData`又はarray shapeを固定するDTO群

Resolver contract:

- organization-scoped query。
- authorization before exposure。
- selected revision snapshotをreturnし、mutable row本文を混ぜない。
- not found / unauthorizedの差をpresentationへ漏らさない。
- write、lockForUpdate、audit、operation recordを実行しない。

### 3.3 Views / assets

- `resources/views/company-context-reader/show.blade.php`
- `_toc.blade.php`, `_management-links.blade.php`
- chapter partials: philosophy / vision / policy / annual 又はtyped共通section
- scoped stylesheet。既存Application Shellのmain spacing contractを使用し、page個別top-margin patchを追加しない。
- JSはactive chapter enhancementとMotion toggleに限定。本文生成、permission filtering、TOC existenceをJSへ委ねない。

### 3.4 Tests

- `tests/Feature/CompanyContextReaderTest.php`
- `tests/Browser/company-context-reader-acceptance.mjs`
- Prototype採用時はproduction routeと分離したvisual fixture / screenshot artifacts。

## 4. Resolution pseudocode

```php
compose(actor, organization, selector): ReaderViewData
    authorize active membership

    chapters = []
    foreach [philosophy, vision, policy] as type:
        if managementDesignAccess.canView(actor, organization, type):
            revision = managementDesignOfficialResolver.current(organization, type)
            chapters += mapAuthorizedOfficial(type, revision)

    annual = annualPolicyReaderResolver.resolveApproved(
        actor, organization, selector ?? currentEffectiveAt(now Asia/Tokyo)
    )
    if annual is authorized and resolved:
        chapters += mapAnnualSnapshot(annual)

    return ReaderViewData(
        chapters = chapters,
        toc = buildFrom(chapters),
        selection = safeSelectionMetadata(annual),
    )
```

`canView()`後にもう一度非認可sourceをqueryしてresponse差を作らない。実装ではN+1を避けてもよいが、authorizationとexposureの順序は維持する。

## 5. Phase plan

各Phaseは`Implement → Focused Test → Corrective → Regression → Evidence`で完結させる。次Phaseへ移る前に前Phaseのinvariantを再確認する。

### Phase 0｜Visual Prototype（推奨、別承認）

- isolated static prototypeでCR-OQ01〜03、Motion設定、Desktop / 390pxを比較する。Motionは2026-10-04 Human Reviewで採用値決定済み。
- 正式data / permission検証とは明確に分離する。
- Humanが名称、長文密度、TOC、Motionをreviewする。

Gate: Prototype Human Review。正式実装へ自動移行しない。

### Phase 1｜Read model / permission composition

- MDC current official resolverを追加。
- Annual approved/effective resolverを追加。
- DTOを追加。
- HTML実装前にpermission matrixとresolution unit / feature testを完成する。

Acceptance:

- Owner without explicit MDC viewは該当章を取得できない。
- Annual manager without approved viewはChapter 04を取得できない。
- 一章だけ許可されたUserはその章だけのDTO / TOCを得る。
- cross-organization selectorはfail closed。
- query count、title、snippet、public IDのleakがresponseにない。

Gate: no schema / no writer / no permission contract delta。

### Phase 2｜Static Reader

- JS / Motionなしで4章Readerを実装。
- Approved text、Explanation、Background、sort orderを完全表示。
- Annual hierarchyをProduct Decisionの順にする。
- chapterごとの文書情報と管理導線を付ける。

Acceptance:

- JS disabledでTOC anchor、本文、history / management linksが利用可能。
- past annual選択時に「01〜03は現在の正本、04だけ選択期間」と明記。
- Draft textがHTML / JSON / data attributesに0。

### Phase 3｜Navigation / responsive / print

- Desktop side TOC、390px normal-flow compact TOC。
- anchor focus / scroll margin、URL fragment、browser backを検証。
- printで本文とsource identityを保持。
- Breadcrumbは既存Global shellをKEEPし、未承認Correctiveをしない。

Acceptance:

- 1440×1000、390×844、320 CSS px、200% zoomでoverflow 0。
- Sticky elementがfocus / headingを完全に隠さない。
- h1 / h2 / h3 hierarchyとlandmarkが正しい。

### Phase 4｜Selected Motion binding

- Human採用値（表示開始75%、1350ms、40px、blur 2.5px）をpresentation tokenとして正式実装する。
- 見出し、Statement、本文、説明を含む意味ブロック単位のone-shot Revealとする。
- 本文初期可視、progressive enhancement、reduced motion、任意OFF。
- timing / distance / blur / triggerはCSS / JS presentation token化し、Domain dataへ保存しない。Prototype Sliderは正式Readerへ持ち込まない。

Acceptance:

- JS failure、Observer未対応、reduced motion、Motion OFFで同一内容・順序・操作。
- no scroll hijack / snap / typewriter / forced dwell。

### Phase 5｜Connected regression / Human evidence

- Existing MDC / Annual show、edit、permission、approval、historyを回帰。
- Application Shell spacingとglobal breadcrumb DOMを回帰。
- Browser screenshotsをDesktop / 390px、selected Motion / OFF / reduced、long fixtureで取得。
- Human review evidenceを作成し、Human自身の判定を待つ。

Gate: `CODE COMPLETE / HUMAN PRODUCT REVIEW WAITING`。CodexがHuman UXをPASS扱いしない。

## 6. Required automated verification

### 6.1 Feature matrix

| Case | Expected |
|---|---|
| inactive user / membership | Reader denied |
| Owner, no MDC explicit view | MDC chapter absent; no title/count/snippet leak |
| all-active MDC scope | active member can read only that type |
| MDC current revision exists | exact snapshot revision/content/order |
| MDC archived current | archived identity shown; not presented as active |
| no MDC source after authorized access | safe registered-empty state |
| Annual explicit viewer | Approved chapter visible |
| Annual draft-only viewer | Chapter 04 absent; Draft never exposed |
| Annual manager without approved view | Chapter 04 absent; no bypass |
| current approved/effective | default Chapter 04 |
| approved/upcoming | defaultでは非current、明示selectionでupcoming表示 |
| approved/ended | 明示selectionでpast表示 |
| overlapping current periods | fail closed; no arbitrary selection |
| selected past annual | Chapters 01〜03 remain current and disclaimer visible |
| unauthorized period selector | no existence oracle |
| empty Theme / Priority / Department | valid, no invented content |

### 6.2 Static response assertions

- unauthorized text / title / section count / public ID / hash absent。
- `draft_` fields and access binding metadata absent。
- one h1、ordered chapter headings、TOC links only to existing authorized anchors。
- Annual order: policy before purpose before background before theme/priority before department。
- timezone text and lifecycle wording present。

### 6.3 Browser verification

- Chrome isolated local server、external requests 0、HTTP 5xx 0。
- 1440×1000、390×844、320 reflow。
- keyboard-only、visible focus、skip link、anchor/back。
- zoom / text resize 200%。
- `prefers-reduced-motion: reduce`。
- JS disabled又はenhancement failure injection。
- long statement / long explanation / many sections / many themes / many priorities fixture。
- print preview / PDF。

### 6.4 Repository regression

Focused testsの後、環境が許す限り`php artisan test`を実行する。既存Browser suitesのMDC / Annual / Application Shellを含める。Migrationは想定しない。schema requirementが発生した場合は実装を止め、Product / Human Reviewへ戻す。

## 7. Human Review evidence

自動testは次をHuman PASSに代替しない。

1. 「会社の言葉」という名称が自然か。
2. 実際の長文で4章を一冊として追えるか。
3. Explanationが本文を圧迫せず、隠されてもいないか。
4. AnnualのPolicy → Purpose → Background → Theme / Priority → Departmentが自然か。
5. Past選択時にChapter 01〜03のcurrent性を誤解しないか。
6. Desktop breadcrumb + TOCが重くないか。
7. 390px TOCが本文を圧迫しないか。
8. 採用Motion（75% / 1350ms / 40px / blur 2.5px）がDesktop / 390pxで読む体験を助けるか。
9. Readerと管理画面の役割が混同されないか。
10. Permission差のあるUserで「見えない内容の存在」を推測できないか。

Evidence package candidate:

- exact commit / tree / test commands / results。
- permission matrixとsanitized fixture identity。
- Desktop / 390px screenshots。
- reduced motion / no-JS / print evidence。
- Human checklist（PASS欄は空欄）。

## 8. Compatibility / pending / escalation

### Compatibility Blocker

`NONE IDENTIFIED`

### Technical pending（Product Decisionではない）

- internal route / class / DTO naming。
- Global Breadcrumb registryとの最終接続時期。
- active chapter indicationにIntersectionObserverを使うか否か。
- Reader内Motion OFFをsession-onlyにするかlocal preferenceにするか。
- current revision更新通知をv1へ含めるか後続へ送るか。

これらは既存Product Boundary内で実装時に安全側へ解決できる。ただしpermission、official resolution、lifecycle、schemaを変える必要が出た場合はTechnical DifferenceではなくHuman + ChatGPT Gateへ戻す。

### Product pending

- CR-OQ01〜03だけ。追加Product Decisionは0。

## 9. Git / authority / stop contract

- 実装時も無関係な変更をreset / stash / clean / checkoutしない。
- task関連fileだけをstageする。
- coherent changeのvalidation後はrepository workflowに従いcommit / pushする。pushはdeployではない。
- deployment workflowは`workflow_dispatch` onlyを維持する。
- Production / Deployは別の明示Authorityが必要。

本Handoffを読んだだけで実装を開始してはならない。次の状態で停止する。

**COMPANY CONTEXT READER / DESIGN BINDING COMPLETE / IMPLEMENTATION WAITING**

