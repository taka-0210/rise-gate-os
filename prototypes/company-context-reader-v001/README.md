# Company Context Reader Visual Prototype v001

Human Visual Review専用の隔離静的Prototypeです。正式Product Code、Laravel、DB、Permission、Revision、Approval、Productionには接続しません。

## Open

Human比較の入口は`review.html`です。確実なsame-origin iframe比較のため、`Open-CompanyContextReaderPrototype.ps1`を使用してください。

## Compare

- Motion: `A / B / OFF`
- Mobile TOC: `A（通常flow disclosure）/ B（現在Chapterの短いsticky control）`
- Viewport: Browser幅 `1440px / 390px / 320px`
- Data: sanitized架空Dataのみ

画面内の`VISUAL PROTOTYPE / NOT OFFICIAL DATA`表示は、正式Dataや正式Readerと誤認しないための境界です。

## Stop condition

本PrototypeはCR-OQ01〜03のHuman Visual Reviewだけに使用します。正式Reader実装へ自動的に進みません。
