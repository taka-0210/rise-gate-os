# Company Context Reader Prototype v002 Motion｜Human Visual Review

## Entry

`Open-CompanyContextReaderPrototype.ps1`を実行し、ChromeまたはEdgeで開く。v002専用portとidentity検証により旧Prototypeとの混同を防ぐ。

同一のReader本文に対して、次を切り替えられる。

- Viewport: Desktop 1440×900 / Mobile 390×844 / Narrow 320×800
- Mobile TOC: A（通常flow disclosure）/ B（現在Chapterだけの短いsticky control）
- Motion ON: 見出し・Statement・本文・説明・Priority・部署方針を意味ブロック単位でReveal
- 4設定: 表示開始位置 / フェード時間 / 移動距離 / ぼかし
- Slider変更中は未反映。「設定を反映」で4項目を一括適用し、画面内本文を再生
- Motion OFF: 初期から全文表示する完全静的baseline

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
5. スクロールして言葉の場所へ来たとき、本文を含むRevealを明確に認識できるか。
6. 4設定のどの組合せが読む気持ちを助けるか。
7. 390pxでTOC A / Bのどちらが本文とNavigationを両立するか。
8. 「会社の言葉」という名称は自然か。

Human Review前に正式実装へ進まない。
