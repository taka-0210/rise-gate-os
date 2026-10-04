# Company OS｜Company Context Reader Formal Implementation Evidence v001

## 1. Outcome

**COMPANY CONTEXT READER / FORMAL CLOSE CANDIDATE**

- Final verification: 2026-10-04 JST
- Human-facing name: `会社の言葉`
- Human Product Review: **PASS**
- Compatibility Blocker: **0**
- Product Pending: **0**
- Product Decision追加: 0
- Schema / Migration / DB変更: 0
- Permission Contract変更: 0
- Breadcrumb Global Corrective: 未実装・別Correctiveを維持
- Production / Deploy / Scope 35〜37: 未実施

Humanが正式Readerを採用し、必要な最終回帰がPASSしたため、本EvidenceはFormal Close Candidateを記録する。

## 2. Human Product Review PASS

Human Product Reviewにより、次を正式採用した。

- Reader Concept / 名称: `会社の言葉`
- 4章連続Reader: 理念 → Vision → 方針 → 年度経営方針
- Prototype準拠のVisual Design
- Typography / Whitespace / Information Hierarchy
- Desktop Side TOC
- Mobile TOC B
- Desktop Canvas: `1440px`
- Reading measure: `720px`
- Motion正式値
- ROOT / FUTURE / DIRECTION / NOW Brand Visual
- Desktop / Mobile Brand Visual composition
- Human調整済みBrand Visual X / Size

## 3. Final adopted visual values

### Reader layout

- Desktop Canvas max-width: `1440px`
- Reading measure: `720px`
- Mobile Reader layout: 既存採用値を維持

### Statement motion

- Trigger: viewport下面から`25%`手前（Human表現: 画面上から`75%`）
- Duration: `1350ms`
- Distance: `40px`
- Blur: `2.5px`
- Target: 見出し・本文・説明を含む内容ブロック
- Runtime: one-shot。いったん表示した要素は再び隠さない
- Fail-open: Motion OFF / reduced motion / JS OFF / 初期化失敗 / Observer非対応 / direct anchorで全文可視

### Brand Visual composition

- Desktop X: `-200px`
- Desktop scale: `1.26`（Human tuning UI上のSize `125%`に相当）
- Mobile X: `-60px`
- Mobile scale: `1.008`
- Mobile opacity: Desktopと同じ正式Chapter opacityを使用し、mobile固有の減衰なし
- Chapter state transition: `2000ms`
- Intro slide duration: `2300ms`
- ROOT: 中心・核
- FUTURE: 中心から次のリングへ展開
- DIRECTION: 方針までのリング状態を維持し、放射状直線は抑制
- NOW: DIRECTION状態を維持したまま放射状直線を表示し、外周全体を強調

## 4. Temporary tool removal

正式Productから次のTemporary Tuning / Prototype Toolが残っていないことを静的検査とBrowser acceptanceで確認した。

- Canvas比較UI: なし
- Brand Visual X slider: なし
- Brand Visual Size slider: なし
- Motion parameter tuning UI: なし
- Motion A/B control: なし
- Prototype / Debug control: なし

正式Readerには、採用済みのアクセシビリティ／閲覧用`Motion ON / OFF`のみを残す。これはparameter tuning UIではない。

## 5. Product contract verification

### 5.1 Source of truth / immutable Revision

- MDC本文はmutable current rowを読まず、`current_revision_id`が指すimmutable revision snapshotを読む。
- Annual本文は`current_approved_revision_id`が指すapproved immutable snapshotを読む。
- DraftはReaderへ表示しない。
- Reader GETはRevision / Operation / Auditを作成しない。

### 5.2 Permission / non-disclosure

- MDCは既存Type別View authorizationを利用する。
- Annualは既存approved-view authorizationを利用する。
- Owner / Manage capabilityによるView bypassはない。
- 未認可Chapterから本文、名称、status、count、public ID、TOC、管理導線を生成しない。
- 未認可、別Organization、存在しない明示期間は同一404境界を維持する。

### 5.3 Approved / Effective / JST

- Default Annual ChapterはApprovedかつEffectiveの期間だけを表示する。
- Upcoming / Endedは認可済みselectorから明示選択する。
- Lifecycle timezoneは`Asia/Tokyo`。
- `current_approved_revision_id`をEffective判定へ流用しない。

## 6. Final verification evidence

### Static verification

- Reader PHP syntax: PASS
- Reader JavaScript syntax: PASS
- Blade compilation (`php artisan view:cache`): PASS
- Temporary tuning control absence: PASS
- `git diff --check`: PASS（Evidence更新後に再確認）

### Focused / connected regression

```text
ManagementDesignCoreP1Test
AnnualManagementPolicyTest
CompanyContextReaderTest

43 passed (292 assertions)
```

確認範囲:

- Reader focused composition
- MDC / Annual connected behavior
- immutable revision / read-only behavior
- Permission / unauthorized Chapter non-disclosure / Owner bypassなし
- Approved / Effective / `Asia/Tokyo`
- Explicit Upcoming / Ended selection
- Cross-organization 404 boundary
- Revision / Operation / Audit増加なし

### Browser verification

隔離SQLiteと一時local serverだけを使用し、通常local DBへ接続していない。

```json
{
  status: passed,
  desktop: 1440x1000,
  mobile: 390x844,
  narrow: 320x800,
  textResize: 200%,
  http5xx: 0,
  externalRequests: 0,
  checks: [
    four-chapter-story,
    desktop-toc,
    mobile-toc-b,
    motion-one-shot,
    motion-off,
    reduced-motion,
    js-off,
    direct-anchor,
    brand-chapter-states,
    print-revision-identity
  ]
}
```

Local visual evidence（Git管理外）:

- `storage/app/company-context-reader-formal-evidence/desktop-1440x1000.png`
- `storage/app/company-context-reader-formal-evidence/mobile-390x844.png`
- `storage/app/company-context-reader-formal-evidence/narrow-320x800.png`

Browser確認には次を含む。

- Desktop Side TOC / Mobile TOC B
- Motion one-shot / OFF / reduced motion / JS OFF / direct anchor
- ROOT / FUTURE / DIRECTION / NOW Brand Visual state
- Desktop 1440px / 390px / 320px / 200% text resize
- horizontal overflow 0
- HTTP 5xx 0
- external request 0

### Repository full-suite baseline

Formal implementation時に取得済みのRepository全体baseline:

```text
754 passed, 17 skipped, 8 failed (6419 assertions)
```

8 failureはReader境界外の既知状態であり、Reader / MDC / AnnualはPASSしている。最終Visual調整はReader CSS / browser acceptanceの範囲であり、影響境界は上記43件のConnected RegressionとBrowser Verificationで再確認した。

- `CompanyNavigationTest` 1件: stale forbidden intended URLのlogin redirect期待値不一致
- `Ir1G2PreflightV2ExecutionTest` 7件: 既存release-audit evidence directoryによるprecondition停止

## 7. Exact revision / repository state

- Final product code under test: `160a3a4` (`style: balance mobile reader brand visual`)
- Evidence finalization commit: 本文書を含む最終commitとして別記録
- Branch: `ce-p1-realtime-corrective`
- Push target: `origin/ce-p1-realtime-corrective`
- Task-related unstaged / uncommitted change: 0（最終commit後に確認）
- Unrelated user-owned untracked files: 3件。変更・stage対象外

## 8. Formal close boundary

- Compatibility Blocker: **0**
- Product Pending: **0**
- Human Product Review: **PASS**
- Final Regression: **PASS**
- Status: **COMPANY CONTEXT READER / FORMAL CLOSE CANDIDATE**

Scope 35〜37、Breadcrumb Global Corrective、Production、Deployへは進まない。
