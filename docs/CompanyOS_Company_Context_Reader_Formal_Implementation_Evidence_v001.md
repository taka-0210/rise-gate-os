# Company OS｜Company Context Reader Formal Implementation Evidence v001

## 1. Outcome

**COMPANY CONTEXT READER / CODE COMPLETE / HUMAN PRODUCT REVIEW WAITING**

- 実装日: 2026-10-04 JST
- Human-facing name: `会社の言葉`
- Product Decision追加: 0
- Compatibility Blocker: 0
- Schema / Migration / DB変更: 0
- Permission Contract変更: 0
- Breadcrumb Global Corrective: 未実施
- Production / Deploy / Scope 35〜37: 未実施

本EvidenceはCode Complete判定であり、Human UX PASSまたはFormal Closeではない。

## 2. Implemented surface

- Company HomeからReaderへの入口
- GET-only Company Context Reader route / controller
- Philosophy / Vision / Policyの`current_revision_id`に紐づくimmutable snapshot Reader
- Annual Management Policyのapproved revision snapshot Reader
- Approved / Effective / JSTを分離した現在・来期・過去の選択
- 認可済みChapterだけから生成するDesktop TOC / Mobile sticky TOC
- Readerから既存の管理・改定・履歴画面へ戻る導線
- 正式固定値による全内容ブロックのone-shot reveal
- JS OFF / initialization failure / reduced motion / Motion OFF / direct anchorのfail-open
- 公式`company-os-brand-symbol.svg`を用いたROOT / FUTURE / DIRECTION / NOWのChapter state
- Desktop / 390px / 320px / 200% text size / print presentation

Prototype用のA/B、viewport、range slider、tuning/debug UIは正式画面へ持ち込んでいない。

## 3. Contract verification

### 3.1 Source of truth

- MDC本文はmutable current rowから読まず、既存`current_revision_id`からimmutable revision snapshotを読む。
- Annual本文は`current_approved_revision_id`のapproved snapshotを読む。DraftはReaderへ出さない。
- Reader GETはRevision / Operation / Auditを作成しない。

### 3.2 Permission boundary

- MDCは既存type別View authorizationを利用する。
- Annualは既存approved-view authorizationを利用する。
- Owner / Manage capabilityによるView bypassは追加していない。
- 未認可Chapterは本文、名称、status、count、public ID、TOC、管理導線を生成しない。
- 明示期間selectorの不存在、別Organization、未認可は同一404境界とする。

### 3.3 Lifecycle

- Default Annual ChapterはApprovedかつEffectiveの期間だけを表示する。
- Upcoming / Endedは認可済みselectorから明示選択する。
- Lifecycle timezoneは既存Contractどおり`Asia/Tokyo`。
- `current_approved_revision_id`をEffective判定へ流用していない。

## 4. Verification evidence

### Focused / connected regression

```text
ManagementDesignCoreP1Test
AnnualManagementPolicyTest
CompanyContextReaderTest

43 passed (292 assertions)
```

Reader focused testは次を含む。

- immutable MDC snapshot / mutable row非参照
- unauthorized Chapter omission
- Owner / ManageのView bypassなし
- Annual manageのみのUserへchapter / selector / bodyを非開示
- Approved / Effective / JST defaultとDraft非表示
- Upcoming / Ended明示選択とcross-organization 404
- read-only composition（Revision / Operation / Audit増加0）

### Browser verification

隔離SQLiteと一時local serverだけを使用し、通常local DBへ接続していない。

```json
{
  "status": "passed",
  "desktop": "1440x1000",
  "mobile": "390x844",
  "narrow": "320x800",
  "textResize": "200%",
  "http5xx": 0,
  "externalRequests": 0,
  "checks": [
    "four-chapter-story",
    "desktop-toc",
    "mobile-toc-b",
    "motion-one-shot",
    "motion-off",
    "reduced-motion",
    "js-off",
    "direct-anchor",
    "brand-chapter-states",
    "print-revision-identity"
  ]
}
```

Local visual evidence（Git管理外）:

- `storage/app/company-context-reader-formal-evidence/desktop-1440x1000.png`
- `storage/app/company-context-reader-formal-evidence/mobile-390x844.png`
- `storage/app/company-context-reader-formal-evidence/narrow-320x800.png`

### Static verification

- Reader PHP syntax: PASS
- Reader JavaScript syntax: PASS
- Blade compilation: PASS
- `git diff --check`: PASS
- Prototype tuning control absence: PASS

### Repository full suite

```text
754 passed, 17 skipped, 8 failed (6419 assertions)
```

Reader / MDC / Annual Management PolicyはPASS。残る8件はReader差分外で、以下の既存状態として分離した。

1. `CompanyNavigationTest` 1件: stale forbidden intended URLのlogin redirect期待値不一致。単独実行でも再現し、Reader route / controllerを経由しない。
2. `Ir1G2PreflightV2ExecutionTest` 7件: Test開始前から既存の`storage/app/release-audit/production-g2-preflight-v2-execution-924af91188cc60d33ff87c91b94ecc1d539566e6-corrective-2`が存在するため、preconditionで停止。既存証跡は削除・変更していない。

## 5. Human Product Review entry

Human自身が次を確認するまでFormal Closeしない。

- 4章が一つのCompany Storyとして読めること
- 正式DataでのTypography / Whitespace / 長文可読性
- 固定Motion値とone-shot revealの体感
- ROOT / FUTURE / DIRECTION / NOWのBrand Visual遷移
- Desktop TOC / Mobile TOC B / anchor移動
- Readerから管理・改定・履歴へ進む導線
- 権限別のChapter表示とAnnual period selector
- Print時のRevision identity

通常local DB、Production、Deploy、Scope 35〜37には進んでいない。
