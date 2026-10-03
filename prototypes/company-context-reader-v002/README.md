# Company Context Reader Visual Prototype v002

Human Visual Review専用の隔離静的Prototypeです。正式Product Code、Laravel、DB、Permission、Revision、Approval、Productionには接続しません。

## Open

Human比較の入口は`review.html`です。確実なsame-origin iframe比較のため、`Open-CompanyContextReaderPrototype.ps1`を使用してください。

## Compare

- Motion ON: 本文を含む全内容ブロックのReveal
- 4設定: 表示開始位置（25〜90%）/ フェード時間（200〜2000ms）/ 移動距離（0〜60px）/ ぼかし（0〜10px）
- Human採用初期値: 75% / 1350ms / 40px / 2.5px
- Slider変更はdraft。「設定を反映」で4項目を一括適用し、画面内本文を再生
- Motion OFF: 完全静的
- Mobile TOC: `A（通常flow disclosure）/ B（現在Chapterの短いsticky control）`
- Viewport: Browser幅 `1440px / 390px / 320px`
- Data: sanitized架空Dataのみ

画面内の`VISUAL PROTOTYPE / NOT OFFICIAL DATA`表示は、正式Dataや正式Readerと誤認しないための境界です。

## Stop condition

Revealは一度だけです。OFF、reduced motion、JS OFF、Observer非対応、初期化失敗、direct fragmentでは全文を即時表示します。

本PrototypeはCR-OQ01〜03のHuman Visual Reviewだけに使用します。Motionの採用案を自動決定せず、正式Reader実装へ進みません。
