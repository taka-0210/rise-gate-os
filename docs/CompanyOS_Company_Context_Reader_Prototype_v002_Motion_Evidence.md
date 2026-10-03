# Company OS｜Company Context Reader Prototype v002 Motion Evidence

## Status

**COMPANY CONTEXT READER / PROTOTYPE v002 MOTION READY / HUMAN VISUAL REVIEW WAITING**

- Date: 2026-10-04 JST
- Scope: Motion Corrective only
- Human-approved KEEP: four-chapter Reader, IA, typography, whitespace, content, Annual hierarchy, TOC and Reader/Management boundary
- Prototype type: isolated static
- Data: sanitized fictional fixture
- Product route / Laravel / DB / official data / Permission: not connected
- Production / Deploy: 0

## Deliverable

- Entry: `prototypes/company-context-reader-v002/review.html`
- Reader: `prototypes/company-context-reader-v002/index.html`
- Human guide: `prototypes/company-context-reader-v002/HUMAN_VISUAL_REVIEW.md`
- One-command launcher: `prototypes/company-context-reader-v002/Open-CompanyContextReaderPrototype.ps1`
- Automated check: `tests/Browser/company-context-reader-prototype-acceptance.mjs`

## Motion contract

| Control | Range | Default | Apply contract |
|---|---:|---:|---|
| 表示開始位置 | 画面上から25〜90% | 50% | IntersectionObserverを確定時に再生成 |
| フェード時間 | 200〜2000ms | 1200ms | 全内容ブロックと確認Previewへ適用 |
| 移動距離 | 0〜60px | 14px | Desktop / Mobile共通 |
| ぼかし | 0〜10px | 1.5px | Desktop / Mobile共通 |

- opacity: 0 → 1
- translateY → 0
- Motion ON / OFFを維持し、A/B presetは廃止。
- Slider変更中はdraftでありMotionへ即時反映しない。`設定を反映`で4項目をatomicに適用する。
- 確定時は同じ値で確認Previewを必ず再生し、以降のReader本文へ適用する。
- 一文字表示、長いstagger、Priority件数連動、scale、parallax、scroll hijack、scroll snap: 0
- 一度Revealした要素は通常scroll再入場で再び隠さない。
- 見出し、Statement、Explanation、Section、Priority、部署方針を意味ブロック単位でRevealする。

## Progressive enhancement / fail-open

Reveal前の非表示は、JavaScriptがObserverを正常に初期化し、Motion ONの場合だけ有効になる。

次はAnimationを待たず全文表示する。

- Motion OFF
- `prefers-reduced-motion`
- JavaScript OFF
- IntersectionObserver非対応
- Motion初期化失敗
- 初期direct fragment
- print

内部anchor移動は、移動前に対象Chapterのheading / primary statementを即時表示する。

## Automated verification

Result: **PASS**

- Reader text SHA-256: `7d602ef9391b5cc57a98845e8503eb51dcfc97582058c3c4350d2b6efa132ba7`
- v001と同一Reader text hash: PASS
- Motion ON / 調整後 / OFF text hash equality: PASS
- Offscreen target待機状態: PASS
- 初期設定 50% / 1200ms / 14px / blur 1.5px: PASS
- Slider変更中のapplied値不変 / 未反映表示: PASS
- 確定操作 75% / 900ms / 30px / blur 4px atomic適用: PASS
- 確定確認Previewを適用値900msで再生: PASS
- Explanation / Priority / Departmentを含む全内容ブロック対象化: PASS
- one-shot reveal / normal re-entry replay 0: PASS
- Motion OFF全文可視: PASS
- 390px適用済みMotion 900ms: PASS
- 1440 / 390 / 320 horizontal overflow: 0
- 200% effective viewport（720×450）horizontal overflow: 0
- direct fragment即時可視: PASS
- internal anchor即時可視: PASS
- reduced motion全文可視: PASS
- JavaScript OFF全文可視: PASS
- Observer非対応fail-open: PASS
- 初期化失敗fail-open: PASS
- keyboard / visible focus: PASS
- external request: 0
- HTTP error: 0
- DB connection / mutation: 0 / 0
- Launcher v002固有port `41802` / artifact identity検証: PASS

Initial automated journeyでは3分類のSTOPをProduction-freeでCorrectiveし、同一full journeyをPASSした。

1. Anchor selectorがnative patch boundaryでquoteを失い、`CONTROL_LISTENERS`でfail-openした。quote非依存filterへ修正。
2. Browser test側の同種selectorを固定index locatorへ修正。
3. Observer非対応時のsafe reasonが後続同期でgeneric stateへ上書きされた。全文可視性は維持したまま、exact fail-open reasonも保持するよう修正。

どちらもfail-openによりReader本文は全文可視であり、正式Data・DB・Product Codeへの影響は0。

## Local visual evidence

Generated under ignored local storage:

- `storage/app/company-context-reader-prototype-v002-motion-evidence/desktop-motion-on.png`
- `storage/app/company-context-reader-prototype-v002-motion-evidence/desktop-motion-tuned.png`
- `storage/app/company-context-reader-prototype-v002-motion-evidence/desktop-motion-off.png`
- `storage/app/company-context-reader-prototype-v002-motion-evidence/mobile-motion-on-v002.png`
- `storage/app/company-context-reader-prototype-v002-motion-evidence/mobile-toc-a.png`
- `storage/app/company-context-reader-prototype-v002-motion-evidence/mobile-toc-b.png`
- `storage/app/company-context-reader-prototype-v002-motion-evidence/narrow-320.png`
- `storage/app/company-context-reader-prototype-v002-motion-evidence/reduced-motion.png`

Screenshots are local Human Review evidence and are not Product assets.

## Human Review pending

Automated PASS does not decide:

1. 4項目の採用値。
2. Revealが読む順番と会社の言葉の存在感を助けるか。
3. Desktop / Mobileで同じ設定が自然か。
4. CR-OQ01〜03。

Human ReviewでMotionが決まるまでCR-PD04をFinal Closeせず、正式Reader実装へ進まない。
