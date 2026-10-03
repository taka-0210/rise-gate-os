# Company Context Reader Prototype｜Human Visual Review

## Entry

`review.html`をChromeまたはEdgeで開く。

同一のReader本文に対して、次を切り替えられる。

- Viewport: Desktop 1440×900 / Mobile 390×844 / Narrow 320×800
- Mobile TOC: A（通常flow disclosure）/ B（現在Chapterだけの短いsticky control）
- Motion: Reader上部のA / B / OFF

TOC A / BはCR-OQ03のHuman Visual Review対象であり、本Prototypeは採用案を確定しない。

## Review boundary

- sanitized架空Dataのみ
- Laravel / DB / `.env` /正式Data / Permission非接続
- Management linkは見た目だけ
- Breadcrumbは視覚的同居確認用placeholder
- 正式Reader実装、Migration、Production、Deployなし

## Human questions

1. 4章は「別画面を縦に並べたもの」ではなく、一冊のCompany Storyに感じるか。
2. 重要な言葉が自然に目へ入り、Explanationとの距離が適切か。
3. ThemeとPriorityの所属関係を迷わず読めるか。
4. 管理Dashboardではなく、会社の言葉を読む場所に感じるか。
5. Motion A / B / OFFのどれが読む気持ちを助けるか。
6. 390pxでTOC A / Bのどちらが本文とNavigationを両立するか。
7. 「会社の言葉」という名称は自然か。

Human Review前に正式実装へ進まない。
