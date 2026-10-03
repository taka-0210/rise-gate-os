# Company OS｜Company Context Reader Prototype Evidence v001

## Status

**COMPANY CONTEXT READER / PROTOTYPE READY / HUMAN VISUAL REVIEW WAITING**

- Date: 2026-10-03 JST
- Purpose: CR-OQ01〜03とMotion A / B / OFFのHuman Visual Review
- Prototype type: isolated static
- Data: sanitized fictional fixture
- Product route / Laravel / DB / `.env` / official data / Permission: not connected
- Production / Deploy: 0

## Deliverable

- Entry: `prototypes/company-context-reader-v001/review.html`
- Reader: `prototypes/company-context-reader-v001/index.html`
- Human guide: `prototypes/company-context-reader-v001/HUMAN_VISUAL_REVIEW.md`
- One-command launcher: `prototypes/company-context-reader-v001/Open-CompanyContextReaderPrototype.ps1`
- Automated check: `tests/Browser/company-context-reader-prototype-acceptance.mjs`

## Comparison contract

| Axis | Variants | Fixed conditions |
|---|---|---|
| Motion | A / B / OFF | Same DOM, text, typography and layout |
| Mobile TOC | A: normal-flow disclosure / B: current-chapter sticky control | Same iframe, text, motion and viewport |
| Viewport | 1440×900 / 390×844 / 320×800 | Same Reader source |

TOC A / BはCR-OQ03のHuman Review対象であり、本Evidenceは採用案を確定しない。

## Fixture coverage

- Overall Statement and long Explanation
- Philosophy sections: 3
- Vision sections with Horizon: 4
- Policy sections: 6
- Company Period: 第23期｜2026年度
- Approved / Effective lifecycle wording
- Annual Policy / Purpose / Background
- Themes: 3
- Priorities: 10
- Departments: 3
- Management links: visual placeholder only
- Breadcrumb: visual placeholder only

## Automated verification

Result: **PASS**

- JavaScript syntax: PASS
- Required four-chapter hierarchy: PASS
- Annual order: period/lifecycle → policy → purpose/background → themes/priorities → departments: PASS
- Motion A / B / OFF Reader text SHA-256 equality: PASS
- Reader text SHA-256: `7d602ef9391b5cc57a98845e8503eb51dcfc97582058c3c4350d2b6efa132ba7`
- Mobile TOC A / B Reader text SHA-256 equality: PASS
- TOC A computed position `static`: PASS
- TOC B computed position `sticky`: PASS
- 1440px horizontal overflow: 0
- 390px horizontal overflow: 0
- 320px horizontal overflow: 0
- reduced motion content equivalence: PASS
- JavaScript OFF content and anchor navigation: PASS
- keyboard first focus / visible focus: PASS
- external request: 0
- HTTP error: 0
- DB connection / mutation: 0 / 0

Initial automated check stopped twice due to test-observation issues, not Prototype defects:

1. `<br>`を含むStatementへexact text一致を要求していたため、visible partial text assertionへ修正。
2. 初期viewport外のStatementへIntersectionObserver発火を期待していたため、observed stateを明示してMotion CSS contractを確認。

Corrective後、同一full journeyはPASSした。

## Local visual evidence

Generated under ignored local storage:

- `storage/app/company-context-reader-prototype-v001-evidence/desktop-motion-a.png`
- `storage/app/company-context-reader-prototype-v001-evidence/desktop-motion-b.png`
- `storage/app/company-context-reader-prototype-v001-evidence/desktop-motion-off.png`
- `storage/app/company-context-reader-prototype-v001-evidence/mobile-toc-a.png`
- `storage/app/company-context-reader-prototype-v001-evidence/mobile-toc-b.png`
- `storage/app/company-context-reader-prototype-v001-evidence/narrow-320.png`
- `storage/app/company-context-reader-prototype-v001-evidence/reduced-motion.png`

Screenshots are local Human Review evidence and are not Product assets.

## Human Review pending

Automated PASS does not decide:

1. 「会社の言葉」という名称。
2. 4章が一冊のCompany Storyに感じられるか。
3. Statement / Explanation / Annual / Theme / Priorityの強弱。
4. Motion A / B / OFFの採用。
5. Mobile TOC A / Bの採用。
6. Breadcrumb / TOC /固定領域の落ち着き。

Human Review前に正式Reader実装へ進まない。
