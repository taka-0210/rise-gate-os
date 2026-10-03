# Company Context Reader Visual Prototype v002

Human Visual Review専用の隔離静的Prototypeです。正式Product Code、Laravel、DB、Permission、Revision、Approval、Productionには接続しません。

## Open

Human比較の入口は`review.html`です。確実なsame-origin iframe比較のため、`Open-CompanyContextReaderPrototype.ps1`を使用してください。

## Compare

- Motion A v002: Subtle Reveal（Desktop 18px / 600ms、Mobile 14px / 520ms）
- Motion B v002: Expressive Reveal（Desktop 30px / 900ms、Mobile 22px / 760ms）
- Motion OFF: 完全静的
- Mobile TOC: `A（通常flow disclosure）/ B（現在Chapterの短いsticky control）`
- Viewport: Browser幅 `1440px / 390px / 320px`
- Data: sanitized架空Dataのみ

画面内の`VISUAL PROTOTYPE / NOT OFFICIAL DATA`表示は、正式Dataや正式Readerと誤認しないための境界です。

## Stop condition

Revealは一度だけです。OFF、reduced motion、JS OFF、Observer非対応、初期化失敗、direct fragmentでは全文を即時表示します。

本PrototypeはCR-OQ01〜03のHuman Visual Reviewだけに使用します。Motionの採用案を自動決定せず、正式Reader実装へ進みません。
