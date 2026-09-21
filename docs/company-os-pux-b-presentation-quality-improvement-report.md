# Company OS｜PUX-B Presentation Quality Improvement Report

## 1. Close判定

- Status: **Complete / Code Close**
- 実施日時: 2026-09-21 JST
- 基準Commit: `73875db95f188b25cecbea83b712c78bd982ebb8`（PUX-B Final Close）
- 実装Commit: `0e37f12b93d1301c9b5f8d4ee77ebcabdc507fc3`
- 対象: PUX-B Business Domain Read LayerのPresentation Quality
- Product / Permission / Data Contract変更: **なし**
- Migration追加・適用: **なし**
- 通常local DB: **未使用・未変更**
- Production Migration / Deploy: **未実施**
- Scope 8: **未着手**

PUX-B Final Close済みEvidenceを再利用し、今回変更したPresentation Layerと、そのRead / Manage接続境界だけを追加検証した。

## 2. 改善前との差分

改善前は、事業名、概要、5軸、強み、明細、Directionを整ったCardとして並べる構造だった。機能責務は正しかったが、情報の視覚的重要度が近く、Company Contextを「管理画面として閲覧する」印象が残っていた。

今回、保存済みBusiness Domain Dataだけを使い、次のStoryへ再構成した。

1. Immersive Hero
2. Key Message（VALUE）
3. Business Outline（WHAT / WHO / VALUE / WHERE / POSITION）
4. Self-Recognized Strengths
5. The Business, in Practice（items / attributes）
6. Where We Go Next（Direction / Direction Memo）

新しいCopy、AI生成文、保存Data、Content正本は追加していない。

## 3. 実装内容

### 3.1 Hero / Key Message

- 事業名を最大の視覚要素とした。
- 既存descriptionを事業概要、既存POSITIONを補助情報として表示した。
- 既存VALUEを独立したKey Messageとして大きく表示した。
- Dark teal、余白、Typography、抽象的なCSS atmosphereにより、Company OS固有のPresentationへ整理した。
- 保管状態と保存済みContentだけを表示し、推測Copyは生成しない。

### 3.2 Business Outline

- 均等な2列Card Gridを廃止した。
- 5軸を「ひとつの事業を異なる角度から見る」Rotary Card File型のPresentationへ変更した。
- Click / Touchに加え、Arrow / Home / End keyboard操作へ対応した。
- JavaScript実行前は全tabpanelがDOM上に通常表示される。
- JavaScript実行後だけ1視点ずつのProgressive Disclosureへ切り替える。
- Printでは5視点をすべて出力する。

### 3.3 Strength / Details / Direction

- 自社認識の強みを独立したStatement Sectionへ昇格した。
- itemsは元のdisplay orderを維持したまま、kindに応じて WHAT WE DO / OUR BRANDS / WHERE WE WORK 等の意味ラベルで提示した。
- attributesは既存のaxis / label / valueをそのまま表示した。
- Directionをページ終盤の「WHERE WE GO NEXT」として独立させ、現在値とMemoへ強い視覚階層を与えた。

### 3.4 Motion / Accessibility

- IntersectionObserverによる控えめなFade + Translate revealを追加した。
- Motionは初期ContentをDOMやCSSで隠す前提にせず、JavaScript失敗時は静的に全文を読める。
- `prefers-reduced-motion: reduce`ではrevealとambient animationを停止する。
- 390pxで横overflowせず、Touchで5視点を選択できる。
- Focus outline、tabpanel / tablist / aria-selected / aria-controlsを保持した。

### 3.5 Read上の管理導線

- 「一覧」「管理」「内容を編集」を常時強調表示する構造から、Hero右上のSecondary Action Menuへ後退させた。
- Edit / Manage / Historyへの既存Permissionは変更していない。
- 権限がないUserには従来どおり管理導線を出さない。

## 4. 維持したClosed Contract

以下はコード変更せず、既存PUX-B EvidenceとFocused / Related Regressionで維持を確認した。

- Business Domain正本Table / Model
- `BusinessDomainWriter`
- `BusinessDomainAccess`
- Revision / immutable History
- Direction / items / attributes / display_order
- Read / Edit / Manage責務分離
- Tenant境界
- PUX-A Product資格 / Admission / Resolver / Session / epoch
- Read時のRevision非取得
- Read / Print GETによるBusiness Data・History・Audit非変更

Controller、Service、Policy、Middleware、Route、Model、Migrationには今回差分がない。

## 5. Test / Build Evidence

| Evidence | Result |
|---|---|
| Blade compile | `artisan view:cache` 成功 |
| Focused | BusinessDomainTest: **26 tests / 282 assertions / failures 0** |
| Related Regression | CompanyNavigationTest + ProductOrganizationOperationalTest: **10 tests / 59 assertions / failures 0** |
| Full Test | **476 tests / 3,955 assertions / failures 0 / 165.07s** |
| Frontend Build | Vite 7.3.6 / 58 modules / **成功 / 1.26s** |
| Diff check | `git diff --check` 成功 |

Focused Testでは、Story順序、保存Contentのみの表示、HTML escape、空Section省略、Progressive Disclosureのserver DOM、reduced-motion定義、Read query、GET無変更、Permission、Tenant負例を確認した。

## 6. Browser Evidence

guard付き一時SQLite `company-os-pux-b-browser-pq.sqlite` とlocal testing serverを使用し、通常local DBから分離した。

Result:

- Chrome headless exit 0
- HTTP 5xx: 0
- Desktop: 1280 × 1000
- Mobile: 390 × 844
- A4 Browser Print PDF: 826,932 bytes

確認Journey:

- Owner Login / Read index / Read detail
- Edit保存後にReadへ戻る
- Immersive Hero / Story hierarchy
- 5視点Click / Keyboard切替
- Desktop motion
- 390px表示 / Touch相当操作 / overflowなし
- reduced-motionで静的に全文表示
- Manage / Revision History
- archive / archived Read / reopen
- Browser Print
- editor revoke後のRead許可 / Manage拒否

Local artifact（gitignored）:

| File | Size | SHA-256 |
|---|---:|---|
| `storage/app/pux-b-pq-desktop.png` | 1,379,738 | `8EE914A61B11A5CAA4CC962E1A0BECFAF0305C8A99DE5FF4CBA529A7153422CB` |
| `storage/app/pux-b-pq-mobile.png` | 539,998 | `00668513CDD9B23B84914CBA42574B12F790D15C679E45581DB4C0A119902B9A` |
| `storage/app/pux-b-pq-print.png` | 540,686 | `23D8BB86AD95D3A5A34D18D9C101C77FD3E3D856F9318B4178C68721F323BD01` |
| `storage/app/pux-b-pq-print.pdf` | 826,932 | `34B8B89134D43283370C485DB4510651C3FAA5E371A65D8F4123E973B6D4025D` |

## 7. Presentation Quality判定

| Requirement | 判定 | Evidence |
|---|---|---|
| 情報画面からCompany Storyへ | Done | Hero → Value → Outline → Strength → Details → Direction |
| Large Typography / Whitespace / Focus | Done | Desktop / 390px screenshot目視、Browser computed style |
| 保存済みDataのみ | Done | View mapping、Focused Test、Writer差分なし |
| 5視点の象徴的Presentation | Done | accessible tabs / stacked no-JS fallback / Print全表示 |
| 強みを核となるStatement化 | Done | 独立full-width Section、Browser visual |
| itemsを管理CardからPresentation化 | Done | kind意味ラベル、display order維持 |
| Directionを重要Section化 | Done | WHERE WE GO NEXT、label / memo階層 |
| 管理UIをSecondary化 | Done | details Menu、Permission維持 |
| Motion / reduced-motion | Done | Browser default / reduce両方確認 |
| Desktop / 390px / Print | Done | Browser Evidence、HTTP 5xx 0 |

## 8. Guarantee / Non-Guarantee

### 保証したこと

- PUX-Bの閉じたData / Permission / Writer / Revision Contractを変更せず、Read Layerだけを改善した。
- 保存内容があるSectionだけをStoryとして表示する。
- JavaScriptが使えない場合もContentはDOMに存在し、5視点を含めて読める。
- reduced-motion、Keyboard、Touch相当、390px、Printが成立する。
- Full TestとBuildで既存機能への回帰失敗がない。

### 保証していないこと

- 保存Dataそのものの文章品質
- Organizationごとの自由Layout / Theme Builder / CMS
- AIによるCopy生成
- 全Browser / 全Printerでのpixel同一性
- ProductionでのRelease確認
- 高見による最終Product UX受入

## 9. Pending / Release Gate

- Contentが名称のみの場合は、推測補完せず名称中心のHeroとして表示する。
- IntersectionObserver非対応BrowserではMotionなしの静的Presentationとなる。
- 今後、理念 / Vision / Value / 方針 / 会社の歴史へ展開する際は、今回の固定Design Languageを再利用できるが、本対応ではそれらを実装していない。
- 既存PUX Release Gateの状態は変更していない。

## 10. 主要ファイル

| File | Responsibility |
|---|---|
| `resources/views/business-domains/show.blade.php` | 保存済みBusiness DomainをStory順で構成 |
| `resources/views/components/company-context/page.blade.php` | Company Context Design Language / responsive / motion / print / deck behavior |
| `resources/views/components/company-context/hero.blade.php` | Immersive Hero |
| `resources/views/components/company-context/perspectives.blade.php` | 5視点のaccessible Progressive Disclosure |
| `resources/views/components/company-context/tools.blade.php` | Secondary Action Menu |
| `tests/Feature/BusinessDomainTest.php` | Content / DOM / Contract / non-mutation Evidence |
| `tests/Browser/company-context-presentation-acceptance.mjs` | Desktop / 390px / Motion / Keyboard / Print / Permission Evidence |
| `tests/Browser/business-domain-acceptance.mjs` | Scope 7 journeyのSecondary Action Menu追従 |

## 11. Final

PUX-BのRead / Edit / Manage、Permission、Data、Writer、Revisionを再設計せず、Business Domain Readを「登録Dataを見る画面」から「会社の事業を体験しながら理解する画面」へ改善した。

GitHubへのPushはSource公開のみであり、通常local DB、Production server、Production DBには変更を加えていない。
