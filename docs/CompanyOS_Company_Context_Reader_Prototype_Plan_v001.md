# Company OS｜Company Context Reader Prototype Plan v001

## 0. Recommendation

`Prototype recommendation = YES`

CR-OQ01〜03とMotion A / Bは、schemaやpermissionを変更せずvisual comparisonで判断できる。一方、正式local appへ先に限定featureを入れると、未確定の名称・TOC・Motionをproduction-shaped codeへ固定し、permissionが完成したように誤解させる。したがって最初は**isolated static prototype**を推奨する。

本書はPrototype作成Authorityではない。別のHuman承認まで作成・実行しない。

## 1. Purpose

Humanが次だけを比較する。

- Human-facing名称（第一候補「会社の言葉」）。
- 4章連続長文のreading rhythm。
- DesktopのBreadcrumb / side TOC /本文幅。
- 390pxのTOC /固定領域 /本文優先。
- Motion A / B / OFF。
- Annualの情報順とperiod / lifecycleの伝わり方。

Permission correctness、Current Official correctness、DB query、Approval、Effective resolutionのPASS判定には使わない。

## 2. Isolation model

- Repository内のtest / design artifact領域に置く静的HTML / CSS /最小JS候補。
- Laravel route、DB、`.env`、authentication、writer、production asset buildへ接続しない。
- localhost file又はisolated test serverだけで開く。
- network request 0、external font / analytics 0。
- form submit、API、storage mutation 0。
- 画面内に`VISUAL PROTOTYPE / NOT OFFICIAL DATA`を明示する。

正式Readerと誤認させないため、実在するpublic ID、revision ID、permission名簿、raw production dataを使わない。

## 3. Fixture strategy

sanitized representative fixtureを3種類用意する。

1. Standard: 通常量の4章。
2. Long: 長いStatement / Explanation、多Section、多Theme / Priority / Department。
3. Partial: 認可後に受け取った体裁を模した「章が一部だけ存在する」fixture。ただし「User Xには権限がない」等の正式判定は主張しない。

Fixtureは架空の会社・文章を使い、UI密度だけを再現する。実際のCompany OS文章を使う必要がある場合は、別途Humanが明示承認したsanitized copyに限定する。

## 4. Variants

### 4.1 Shared static baseline

- DOM、本文、heading order、TOC、linksは全variantで同一。
- 本文は初期から可視。
- URL fragment、keyboard、printはJSなしで動く。
- Variant切替はpresentation classだけを変え、内容を変えない。

### 4.2 Motion A

- Chapter heading / separatorだけ。
- opacity + 最大4px程度、150〜200ms程度を比較開始値とする。
- Paragraph / Explanation / Priority staggerなし。

### 4.3 Motion B

- Chapter headingに加え、各章の重要Statement入口だけ。
- opacity + 最大8px程度、200〜250ms程度を比較開始値とする。
- Body、TOC、permission表現、LifecycleはAと同一。

### 4.4 Motion OFF / reduced

- animation / transition 0。
- 同じ本文、順序、TOC、focus、anchorを保持。
- OS reduced-motionを自動尊重し、手動OFFはその上で常にOFFにできる。

## 5. Viewport comparison

### Desktop

- 1440×1000。
- Global breadcrumb相当は通常flow。
- 細いleft TOCをcontent column内sticky candidateとして比較。
- first viewで会社名、Reader名、短いTOC、Philosophy冒頭が見える。

### Mobile

- 390×844。
- 1 column。
- A: TOCを通常flow disclosure。
- B: current chapterだけの短いsticky control。
- fixed UIがfocusや章見出しを隠さないことを比較する。

### Safety sizes

- 320 CSS px reflow。
- 200% zoom / text resize。
- long unbroken text / Japanese wrapping。

## 6. Human comparison script

順序を固定し、先入観を減らす。

1. Motion OFFでStandardを読み、Readerの目的をHuman自身の言葉で説明する。
2. Long fixtureでChapter 03から04へ直接移動する。
3. Past annualを選んだ体裁で、01〜03がcurrentだと理解できるか答える。
4. Desktop Motion A / Bを順不同で比較する。
5. 390pxでTOC A / Bを比較する。
6. keyboardだけでTOC、章、文書情報へ移動する。
7. reduced motion、200% zoom、320pxで同じ内容が読めるか確認する。
8. Readerと「この文書を管理」の役割を説明する。

Human evidenceは次を回答する。

- CR-OQ01: 名称を採用 / 再検討。
- CR-OQ02: 長文量でTOC /全文表示が成立 / 要調整。
- CR-OQ03: Breadcrumb / TOC / Motion / Mobile variantの選択。
- Motion: A / B / OFF、又は再設計。

## 7. Automated prototype checks

- external request 0。
- HTTP / file load error 0。
- 1440、390、320でhorizontal overflow 0。
- TOC hrefが全て存在anchorを指す。
- one h1、chapter h2、subsection h3 hierarchy。
- keyboard focus visible。
- reduced motionでanimation name / durationが無効。
- JS failure injection後も本文・TOC・linksが可視。
- A / B / OFFでtext content hash一致。
- screenshots: desktop A/B/OFF、mobile TOC A/B、reduced、long fixture。

## 8. What the prototype cannot approve

- DB / schema compatibility。
- current official selection。
- Annual Approved / Effective resolver。
- real permission / non-disclosure。
- management links authorization。
- production performance / caching。
- Human Product Review Formal Close。

これらはImplementation Handoff Phase 1以降のautomated verificationとHuman Reviewで確認する。

## 9. Gate

Prototypeを作成する場合も、成果は`VISUAL PROTOTYPE REVIEW`で停止する。Humanがvariantを選ぶまで正式Reader実装へ進まない。

Current state:

**COMPANY CONTEXT READER / DESIGN BINDING COMPLETE / IMPLEMENTATION WAITING**
