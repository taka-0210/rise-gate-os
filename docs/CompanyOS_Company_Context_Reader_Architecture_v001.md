# Company OS｜Company Context Reader Architecture v001

## 0. Document status

- Status: `DESIGN BINDING COMPLETE / IMPLEMENTATION WAITING`
- Date: 2026-10-03 JST
- Motion Human Decision: 2026-10-04 JST / CR-PD04 CLOSED
- Repository commit: `958a7b12865b12cbd587e49735cdd00111b69461`
- Git tree: `70e304ebeca7b46ef8041eea8bdf490c87732bfd`
- Product Design source: `CompanyOS_Company_Context_Reader_Product_Design_v001.md`
- Product Design SHA-256: `B95FA152367642B7A4E3AF5DC55E121DEA76D36436C600EE1F4A26B7AC174156`
- Authority: Product DesignはProduct意図の正本、今回のHuman依頼はCR-PD01〜04の承認と本書作成の実行Authorityである。
- Scope: Repository照合、Architecture、Permission / Navigation / Motion contract、Implementation Handoff、Prototype判断のみ。
- Non-scope: Product code、Migration、DB、正式Reader、Breadcrumb Corrective、Scope 35〜37、Production、Deploy。

## 1. Product Design binding

本Architectureは次の承認済みDecisionを固定する。

| Decision | Binding |
|---|---|
| CR-PD01 | Philosophy、Vision、Policy、Annual Management Policyを4章の連続Readerとして構成し、TOC / anchorで直接移動できる。既存の各正本、Revision、Permission、管理画面は統合しない。 |
| CR-PD02 | 正式な本文・Explanation / Backgroundを連続表示する。Annualは「期間・Lifecycle → 年度経営方針 → Purpose → Background → Themeと対応Priority → Department Policy」の順で読む。 |
| CR-PD03 | Chapter 04の初期対象は現在の`Approved / Effective`。Upcoming / Pastは明示選択、Draftは管理側Previewだけ。Past選択時もChapter 01〜03は現在の正本であることを明示する。 |
| CR-PD04 | Motionは見出し、Statement、本文、説明を含む意味ブロック単位のone-shot Revealとする。Human採用値は表示開始位置75%、1350ms、translateY 40px、blur 2.5px。`prefers-reduced-motion`、JS / Observer failure、任意OFFでは同じ情報・階層・操作を初期表示し、scroll hijack、scroll snap、typewriter、本文待機を禁止する。 |

未確定のCR-OQ01〜03は実装Blockerに昇格させない。

- CR-OQ01: Human-facing名称。第一候補は「会社の言葉」。
- CR-OQ02: 実データ相当の長文量とTOCの読みやすさ。
- CR-OQ03: Breadcrumb、Sticky TOC、390px固定領域のバランス。Motion値はCR-PD04として解決済み。

## 2. Architectural decision

Readerは新しいDomain aggregateではなく、既存の正本をrequest時に合成するread-only presentationである。

```text
authenticated request + current organization
                  |
                  v
       CompanyContextReaderComposer
          /        |         \
         v         v          v
 MDC access   MDC official   Annual approved/effective
 per type     revision       resolution + access
         \         |          /
          \        v         /
           authorized chapter DTOs
                    |
          TOC is built after filtering
                    |
             Blade Reader view
```

Reader用のtable、Reader revision、Reader approval、Reader snapshot、Reader-wide content hashは作らない。4章の更新時刻が異なることを許容し、全章が一つの時点で承認されたように表現しない。

## 3. Current repository audit

### 3.1 Management Design

| Concern | Current implementation | Architecture binding |
|---|---|---|
| Types | `ManagementDesignItem::TYPES`: philosophy / vision / policy | Chapter 01〜03の固定順に対応する。 |
| Official identity | `management_design_items.current_revision_id` → immutable `ManagementDesignRevision` | Readerはcurrent mutable rowではなく、選択したcurrent revision snapshotを本文sourceにする。 |
| Snapshot | `ManagementDesignSnapshot` schema v2。statement、explanation、horizon、active sections、sort orderを保持 | Snapshot内の保存順をそのまま使う。Reader都合で並べ替えない。 |
| Access | `ManagementDesignAccess::authorizeView()`。active membership + view scope / explicit grant | typeごとに既存Accessを実行する。Owner bypassを追加しない。 |
| Management | show / edit / history / revisions / permissions / archive / reopen | すべて残す。Readerは認可済み章からだけ控えめな導線を出す。 |
| Current reader | `management-design.show`はitem current rowとactive sectionsを表示 | 既存画面はKEEP。統合Readerは専用Resolver / DTOを使い、画面partialを直接nestしない。 |

Archive / reopenも新しいimmutable revisionを作り`current_revision_id`を更新する。Readerはそのrevision statusを文書情報に保持し、archivedをactiveと偽らない。

### 3.2 Annual Management Policy

| Concern | Current implementation | Architecture binding |
|---|---|---|
| Period | `OrganizationManagementPeriod`。期数と年度名を別属性として保持 | `display_label`相当の「第○期｜年度」を章入口に使う。 |
| Approval | `current_approved_revision_id` → immutable annual revision snapshot | Approved本文はrevision snapshotだけを読む。Draft rowを混ぜない。 |
| Effective | `AnnualManagementPolicyLifecycle`が`Asia/Tokyo`でupcoming / effective / endedを判定 | ApprovedとEffectiveを別軸のまま表示する。 |
| Current | `ManagementPeriodResolver::current()`がJSTの日付を含む1期間を解決し、overlapをfail closed | Default Chapter 04はこのperiodにApproved revisionがあり、`effective`である場合だけ成立する。 |
| Access | `AnnualManagementPolicyAccess::authorizeApprovedView()`。all-active又はexplicit approved grant | 既存approved view境界をそのまま使う。Manage権限をview代替にしない。 |
| Snapshot | schema v2。period、Purpose、Background、Policy、Theme / Priority、Department Policy、sort orderを保持 | Approved snapshotの順序と文言を保持し、access binding等の内部metadataは本文へ出さない。 |
| Existing source provider | current / period / historical approved / explicit draftを解決可能 | Resolution知識は参考・再利用候補。ただしReaderはDraft modeを呼ばず、専用DTOへ変換する。 |

現在の`MODE_CURRENT`は「今日を含むperiodのcurrent approved revision」を解決するためDefault要件と互換である。ただしArchitecture上はLifecycle結果が`effective`であることも明示assertし、今後Resolverの意味が変わってもApprovedのみをCurrentと誤表示しない。

### 3.3 Shell / Breadcrumb / Motion

- Global shellは`layouts/app.blade.php`のheader / breadcrumbs / main gridである。
- Breadcrumbは同layout内のroute-name `match`で生成され、Management DesignとAnnual Management Policyは登録済みだが、Company Context Reader routeは未存在である。
- 現在のBreadcrumbはrouteごとの個別条件が増える構造であり、Readerだけのone-off修正はGlobal Breadcrumb Correctiveと競合する。
- `components/company-context/page.blade.php`にはfocus、print、responsive、reduced motion、IntersectionObserver等の資産があるが、Business Domain向けのvisual / interactionも含む。統合Readerへの全面再利用はしない。
- 現行revealはJS成功時にclassを足す方式で、本文自体は初期から可視である。このprogressive enhancement原則をReaderでも維持する。

## 4. KEEP / REFACTOR / ADD / OUT

### KEEP

- MDC / 35Aのtable、model、immutable revision、snapshot、writer、permission、history、management routes。
- `Asia/Tokyo`のAnnual lifecycle contract。
- 保存済みsection / theme / priority / departmentのsort order。
- Application Shell、既存個別Reader、管理画面、focus-visible、print / reduced-motionの基礎。

### REFACTOR（意味を変えない抽出）

- Controller private queryに留まるMDC current official resolutionを、Readerでも安全に使えるread-only resolverへ抽出する。
- Annualのapproved/effective selectionを、Source Providerの責務を壊さずReader selectorへ適合させる。
- chapter presentationを既存full-page Bladeの再利用ではなく、typed read DTOへ分離する。
- BreadcrumbはGlobal Corrective承認後にsemantic page context / registryへ接続できるinterfaceを用意する。今回もReader実装Phaseでも、未承認のGlobal Correctiveを先行しない。

### ADD（別途実装承認後）

- GET-only Reader route / controller。
- `CompanyContextReaderComposer`とMDC / Annual resolution adapter。
- 認可済み章だけを含むimmutable request DTO。
- 4章Reader Blade、scoped CSS、任意のprogressive-enhancement JS。
- Annual period selector（authorized approved optionsのみ）。
- permission non-disclosure、resolution、lifecycle、responsive、accessibility、Motionのtests。

### OUT

- Reader用schema / migration / persistence / read-progress保存。
- Reader全体のRevision / Approval / Snapshot / Effective / hash。
- Permission変更、Owner bypass、既存正本の同期・複製。
- Draftの通常Reader混在、4章一括編集・一括承認。
- Global Breadcrumb Correctiveの実装。
- Project / Action / Meeting / Company Memory / Management Numbers / CO relationの実装。
- Scope 35〜37、Vision Image、AI Write、Production、Deploy。

## 5. Reader composition contract

### 5.1 Request model

- MethodはGETのみ。
- Organizationは既存middlewareの`currentCompany`から取得し、request parameterのorganization IDを信用しない。
- Default requestにはAnnual selectorを持たせない。
- Explicit Annual選択はopaque public ID等の既存route-safe identifierを使い、必ずcurrent organizationへ再scopeする。
- Revision selectorを通常Readerへ露出しない。過去revision閲覧は既存history / revision screenを正規入口とする。

### 5.2 Composition order

1. active user / active organization membershipを確認する。
2. philosophy、vision、policyそれぞれについて既存`authorizeView()`を独立実行する。
3. 認可済みtypeだけcurrent revisionを解決し、そのimmutable snapshotをDTOへ変換する。
4. Default AnnualはJSTのcurrent periodを解決する。
5. policyとapproved revisionが存在するときだけ既存`authorizeApprovedView()`を実行し、Lifecycle=`effective`を確認する。
6. Explicit selectionではauthorized approved revisionだけを解決し、upcoming / endedを正しく表示する。
7. DTO確定後にだけTOC、anchor、管理導線を生成する。

### 5.3 Chapter DTO minimum

DTOはEloquent modelをViewへ渡さず、表示許可済みの値だけを持つ。

- `key`, `ordinal`, `label`, `direction`, `anchor`
- `document_status`, `revision_no`, `source_changed_at`
- `statement`, `explanation`, `horizon`, ordered `sections`
- Annualのみ`period_label`, `starts_on`, `ends_on`, `approval_status`, `effective_status`
- 認可済みUserにだけ`management_links`
- `source_public_id`等を使う場合も、認可済み文書のlink / anchor生成に限定する。

Grant、viewer count、permission hash、内部DB ID、未認可文書の存在情報はDTOへ入れない。

### 5.4 Annual hierarchy

Chapter 04は以下の順序を固定する。

1. 第○期 / 年度 / 期間 / JST / Approval / Effective
2. 年度経営方針（Policy）
3. 今期、何を実現したいのか（Purpose）
4. この方針を定める背景（Background）
5. ThemeごとにTheme statement / explanationと、そのTheme配下のPriority群
6. Department Policy群

これは表示順の決定であり、snapshot schemaや既存35A画面の保存順を変更しない。

### 5.5 Consistency and change during reading

- 一request内では各chapterの選択revision IDを最初に固定し、同一responseでcurrent rowとsnapshotを混在させない。
- 4章共通のtransactional snapshotは主張しない。章ごとのrevision情報を文書情報として保持する。
- 読書中の自動polling / silent replacementは行わない。更新検知を後で追加する場合も、通知後の明示reloadにする。
- 権限失効時はcache済み本文を保持表示する機能を追加しない。

## 6. Permission and non-disclosure contract

### 6.1 Fail-closed rules

- Owner / Manage権限は本文View権限を代替しない。
- Chapter、TOC entry、section title、count、snippet、status、relation、management linkは同じview authorizationの後でのみ生成する。
- 未認可と未登録をReader上で区別して情報漏えいしない。
- ある章が未認可でも、他の認可済み章は読める。4章すべて不可の場合は本文由来情報を含まないgeneric 403 / safe empty experienceとする。
- Explicit Annual selectorがunauthorized / cross-organization / nonexistentのどれかを応答差で推測できないよう、既存policyに合わせたsafe not-found / forbidden contractをtestで固定する。
- Server-rendered HTMLにhidden本文、data attribute、JSON、CSS contentとして未認可情報を埋めない。

### 6.2 Empty states

- View authorization成立後にsourceがない場合だけ「まだ登録されていません」を表示できる。
- Authorization不成立時は章をTOC /本文から除外し、未登録とは表示しない。
- Annual current policyがない / approved revisionがない / approved view不可を、Reader本文上で詳細に区別しない。管理者は既存Annual管理画面で確認する。

## 7. Navigation / TOC / Breadcrumb

### 7.1 Reader TOC

- TOCは同一document内のnavigationであり、global breadcrumbの代替ではない。
- Desktopは本文横の細いsticky candidate。`position: sticky`はmain content内に閉じ、global header直下へ固定barを追加しない。
- 390pxは通常flowのcompact TOC / disclosureを第一候補とする。常時fixedは採用前にPrototypeで比較する。
- Anchor targetに`scroll-margin`を設け、breadcrumb / header / focusを隠さない。
- URL fragmentとkeyboard navigationがJSなしで成立する。現在章の視覚強調は補助であり、screen readerへscrollごとの過剰通知をしない。

### 7.2 Breadcrumb coexistence

目標のsemantic hierarchyは次である。

`Company OS / <Company name> / 会社の言葉（仮）`

ただしHuman-facing名称はCR-OQ01で未確定であり、現在のinline route matchへ仮称をhard-codeしない。Reader実装はpage contextを渡せる境界だけ用意し、Global Breadcrumb Correctiveが承認されたときに同じregistryへ接続する。

Breadcrumbはpage hierarchy、TOCはdocument hierarchyを表す。双方をsticky top barとして積み重ねない。Reader prototypeでは次を比較する。

- Breadcrumbはglobal shellの通常位置。
- Desktop TOCは左column内sticky。
- Mobile TOCは通常flow又は短いdisclosure。

## 8. Motion contract

共通条件:

- HTML / CSSだけで本文は初期から可視。
- JS、IntersectionObserver、animationが失敗しても内容・順序・操作は同一。
- Anchor jump、browser back、focus移動をanimationで妨げない。
- `prefers-reduced-motion: reduce`とReader内の任意OFFで追加motionを無効化する。
- Motion preferenceを保存する場合はlocal presentation preferenceに限定し、Domain data / read completionにしない。v1ではsession中のtoggleでもよい。
- Reveal対象はChapter heading、Statement、Explanation、Section、Priority、Department Policyを意味ブロック単位とする。
- 表示開始位置はviewport上端から75%のline、durationは1350ms、開始transformは`translateY(40px)`、開始blurは`2.5px`。
- 一度Revealしたblockは同じsession中に再び隠さず、通常scroll再入場でreplayしない。
- 文字単位animation、件数連動stagger、scale、parallax、forced dwellを使わない。
- Anchor移動は対象を即時可視にし、native `auto`をbaselineとしてsmooth scrollを必須にしない。
- Prototypeの4 SliderはHuman Decision用である。正式Readerで管理設定として公開・永続化せず、採用値をpresentation tokenとして実装する。

## 9. Responsive / accessibility

- Desktop target: 1440×1000を代表。本文measure、細いTOC、本文columnを分離する。
- Mobile target: 390×844を代表。1 column、本文優先、横overflow 0。
- Reflow safety: 320 CSS px相当でも本文と操作を失わない。
- Text resize / zoom: 200%でTOC、章見出し、管理linkが重ならない。
- Semantic order: `nav` → `main/article`内の`section`、h1は1つ、chapterは順序あるh2、subsectionはh3以下。
- Skip link、focus-visible、anchor focus / scroll-margin、keyboard-only TOCを検証する。
- Lifecycleはbadge色だけでなく文章で伝える。
- Motion OFF、JS OFF、reduced motionでも同じ本文、順序、linkを提供する。
- Printでは正式本文、period / lifecycle、章ごとのrevision identityを残し、TOC操作とmanagement toolsを除外する。全章一括承認済みと誤解させる表題を付けない。

## 10. Management boundary

- Readerは読む場所、既存画面は作成・編集・承認・履歴・権限管理の場所である。
- 章ごとの「この文書を管理」「履歴」だけを認可状態に応じて表示する。
- Reader全体の「編集」「承認」は作らない。
- Draft PreviewはAnnual management flowの中だけで、Draftの明示、draft permission、approval操作との分離を維持する。
- Readerを開く、scrollする、最後まで読む行為は承認・同意・監査eventにしない。

## 11. Future relation boundary

Project、Action、Meeting、Company Memory、Management Numbers、COへの接続余地はDTO / chapter footerのextension slotとしてのみ保持する。v1ではempty slotも表示しない。

- Relationが将来追加されてもsource permissionを継承したことにはしない。
- relation先も独立authorization後にだけ表示する。
- COは明示User actionと明示Context selectionを要求し、Reader閲覧だけでAI requestを送らない。
- Shared COはparticipantsのpermission intersectionを別Contractで守る。

## 12. Compatibility assessment

### 12.1 Blocker

`Compatibility Blocker = NONE IDENTIFIED`

承認済みProduct Decisionを変更しないと実装不能になる差分は確認されなかった。Migrationも現時点では不要である。

### 12.2 Issues to resolve in implementation

| Issue | Classification | Resolution |
|---|---|---|
| MDC current screenはmutable item rowを表示 | Technical compatibility issue | Reader専用resolverは`current_revision_id`のimmutable snapshotを使う。既存screen変更は別判断。 |
| Annual Source ProviderはAI/source export向けunit形状 | Technical design | access / resolution semanticsは再利用し、Reader presentation DTOは別adapterにする。 |
| Existing company-context componentはBusiness Domain表現を含む | Reuse risk | focus / print / progressive enhancement patternだけ参考にし、Reader scoped componentをADDする。 |
| Breadcrumbはlayout内route match | Cross-cutting pending | Readerのone-off patchを避け、未承認のGlobal Correctiveと接続可能なpage context boundaryを設計する。 |
| Mobile sticky UIとMotion値 | Human UX pending | Prototype A/Bで決める。Product Contract追加ではない。 |

### 12.3 Additional Product Decisions

`Additional Product Decision required now = 0`

CR-OQ01〜03はHuman visual reviewで決める既存Open Questionであり、Architectureを止めない。schema、permission、lifecycle、official resolutionを変える新Decisionは提案しない。

## 13. Gate

次のいずれも別の明示承認まで開始しない。

1. Prototype作成
2. Product code実装
3. Breadcrumb Corrective
4. DB / Migration
5. Scope 35〜37
6. Production / Deploy

本Architectureの終了状態:

**COMPANY CONTEXT READER / DESIGN BINDING COMPLETE / IMPLEMENTATION WAITING**

