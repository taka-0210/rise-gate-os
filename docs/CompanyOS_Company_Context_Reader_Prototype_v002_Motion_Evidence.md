# Company OS｜Company Context Reader Prototype v002 Motion Evidence

## Status

**COMPANY CONTEXT READER / PROTOTYPE v002 MOTION READY / HUMAN VISUAL REVIEW WAITING**

- Date: 2026-10-03 JST
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

| Variant | Desktop | Mobile | Reveal hierarchy |
|---|---:|---:|---|
| A v002 / Subtle | 14px / 360ms | 12px / 320ms | Chapter、Overall/Annual Statement、Theme Statement |
| B v002 / Expressive | 24px / 540ms | 18px / 460ms | A対象 + 主要Section Statement |
| OFF | none | none | 初期から全文表示 |

- opacity: 0 → 1
- translateY → 0
- BのStatement / Theme sequence: 70ms
- 一文字表示、長いstagger、Priority件数連動、scale、parallax、scroll hijack、scroll snap: 0
- 一度Revealした要素は通常scroll再入場で再び隠さない。
- A/B明示切替時だけ、現在viewportの対象を比較用に再生できる。

## Progressive enhancement / fail-open

Reveal前の非表示は、JavaScriptがObserverを正常に初期化し、A/Bが選択された場合だけ有効になる。

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
- A / B / OFF text hash equality: PASS
- Offscreen target待機状態: PASS
- Motion A Desktop 360ms: PASS
- Motion B Desktop 540ms: PASS
- A / B CSS contract差: PASS
- one-shot reveal / normal re-entry replay 0: PASS
- Motion OFF全文可視: PASS
- 390px Motion A 320ms / Motion B 460ms: PASS
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

Initial automated journeyでは3分類のSTOPをProduction-freeでCorrectiveし、同一full journeyをPASSした。

1. Anchor selectorがnative patch boundaryでquoteを失い、`CONTROL_LISTENERS`でfail-openした。quote非依存filterへ修正。
2. Browser test側の同種selectorを固定index locatorへ修正。
3. Observer非対応時のsafe reasonが後続同期でgeneric stateへ上書きされた。全文可視性は維持したまま、exact fail-open reasonも保持するよう修正。

どちらもfail-openによりReader本文は全文可視であり、正式Data・DB・Product Codeへの影響は0。

## Local visual evidence

Generated under ignored local storage:

- `storage/app/company-context-reader-prototype-v002-motion-evidence/desktop-motion-a.png`
- `storage/app/company-context-reader-prototype-v002-motion-evidence/desktop-motion-b.png`
- `storage/app/company-context-reader-prototype-v002-motion-evidence/desktop-motion-off.png`
- `storage/app/company-context-reader-prototype-v002-motion-evidence/mobile-motion-a-v002.png`
- `storage/app/company-context-reader-prototype-v002-motion-evidence/mobile-motion-b-v002.png`
- `storage/app/company-context-reader-prototype-v002-motion-evidence/mobile-toc-a.png`
- `storage/app/company-context-reader-prototype-v002-motion-evidence/mobile-toc-b.png`
- `storage/app/company-context-reader-prototype-v002-motion-evidence/narrow-320.png`
- `storage/app/company-context-reader-prototype-v002-motion-evidence/reduced-motion.png`

Screenshots are local Human Review evidence and are not Product assets.

## Human Review pending

Automated PASS does not decide:

1. Motion A / B / OFFの採用。
2. Revealが読む順番と会社の言葉の存在感を助けるか。
3. Mobileで距離・durationが自然か。
4. CR-OQ01〜03。

Human ReviewでMotionが決まるまでCR-PD04をFinal Closeせず、正式Reader実装へ進まない。
