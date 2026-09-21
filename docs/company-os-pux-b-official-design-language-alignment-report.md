# Company OS｜PUX-B Official Design Language Alignment Report

## 1. 判定

- Status: **Complete / PUX-B Presentation Layer follow-up**
- 実施日: 2026-09-21 JST
- 基準HEAD: `a1552ce073296a6b8964a00a5f99d677bf715e56`
- 対象: Business Domain Read Layerの公式Company OS Design Language整合
- Product / Permission / Data / Writer / Revision Contract変更: **なし**
- Migration追加・適用: **なし**
- 通常local DB: **未使用・未変更**
- Production Migration / Deploy: **未実施**
- PUX-A / S1〜S7 / Scope 8: **変更・着手なし**

PUX-B Close済みEvidenceを再利用し、今回変更したPresentation LayerとBusiness Domain Read接続境界だけを検証した。

## 2. 正式Visual Reference

ユーザー指定に基づき、次の公式サイト実装を正本Visual Referenceとして確認した。

| Reference | 確認内容 |
|---|---|
| `C:\xampp\htdocs\company-os\index.html#top` | Hero、Company OSブランドシンボル、PAST / NOW / FUTURE、Scroll Story |
| `C:\xampp\htdocs\company-os\styles.css` | Paper / Ink / Teal / Progress Green、Ring / Arc / Dot、Typography、余白、Story rail、reduced-motion |
| `C:\xampp\htdocs\company-os\script.js` | IntersectionObserver、Scroll progress、phase切替、requestAnimationFrame |
| `C:\xampp\htdocs\company-os\assets\images\company-core-transparent-web.svg` | 公式ブランドシンボル透明版、Layer rotation、active flow、reduced-motion |
| `C:\xampp\htdocs\company-os\docs\design-handoff.md` | ブランド定義、静かで編集的な表現、構造と時間、Motion原則 |

最終実装はRepository内Welcome画面を公式サイトと見なしていない。上記 `company-os` ディレクトリの実装を読み取り専用Referenceとして使用し、公式サイト側のファイルは変更していない。

## 3. Asset provenance

- Source: `company-core-transparent-web.svg`
- Source SHA-256: `E8F8CF839E26DF052954711EAC65FF178F627227527921F95F00C8EC1BF9AB22`
- Product asset: `public/images/company-os-brand-symbol.svg`
- Product asset SHA-256: `EE261D44C8CF24CAC20959823FC4EEC8466025FAC1901CD55E0FD12FD98DD907`
- XML: parse成功
- Sourceとの比較: tag間改行を正規化した描画内容が一致
- Script: 含まない
- Motion: Asset内部に `prefers-reduced-motion: reduce` を保持

raw hashの差はtag間改行の正規化による。Shape、Color、Path、Animation、viewBox等のVisual内容は変更していない。

## 4. 実装内容

### 4.1 Hero

- 公式Company OSブランドシンボルを実Assetとして配置した。
- 公式Heroをそのまま複製せず、保存済みOrganization名、Business Domain名、description、POSITIONを前景にしてCompany Context用途へ展開した。
- Desktopは左に事業情報、右にCompany OS構造を配置した。
- 390pxはVisualから事業情報へ自然に読める縦構成とした。
- 写真、Stock Photo、Upload / Media機能は追加していない。

### 4.2 Business Outline

- WHAT / WHO / VALUE / WHERE / POSITIONを、公式サイトのRing / Arc / Line / Dot / Progress Greenで構成した。
- 各軸は保存済みDataに対応し、存在しない軸の文章やCopyを生成しない。
- Scroll中の現在軸に応じてnode、radial line、focus arc、Story rail、Panelが連動する。
- 保存されている軸が5件未満でも、各code固有の角度を維持する。
- Click / Touch / Arrow / Home / Endを使用できる。
- 5軸本文は常にDOMに存在し、JavaScript未実行時は全Panelを100% opacityで読める。

### 4.3 Motion / Responsive / Print

- MotionはReading orderを示す用途に限定した。
- `prefers-reduced-motion`ではPage motionとAsset motionを停止する。
- DesktopではVisualをSticky表示し、本文Scrollと同期する。
- 390pxではVisualと横Scroll可能な明示controlsを表示する。
- PrintではブランドVisualと操作UIを外し、保存Contentを全件静的表示する。

## 5. 維持したContract

- Business Domain正本
- `BusinessDomainWriter`
- `BusinessDomainAccess`
- Direction / items / attributes / display_order
- Revision / immutable History
- Read / Edit / Manage責務分離
- Read時のRevision非取得
- Tenant / Permission境界
- PUX-A interface
- Read / Print GETの非変更性

Controller、Service、Policy、Middleware、Route、Model、Migrationに差分はない。

## 6. Test / Build Evidence

| Evidence | Result |
|---|---|
| Blade compile | `artisan view:cache` 成功 |
| Focused | BusinessDomainTest: **26 tests / 290 assertions / failures 0** |
| Related Regression | CompanyNavigationTest + ProductOrganizationOperationalTest: **10 tests / 59 assertions / failures 0** |
| Full Test | **476 tests / 3,963 assertions / failures 0 / 165.63s** |
| Frontend Build | Vite 7.3.6 / 58 modules / **成功 / 1.21s** |
| SVG validation | XML parse成功 / reduced-motion保持 / scriptなし |
| Diff check | `git diff --check` 成功 |

## 7. Browser Evidence

Guard付き一時SQLite `company-os-pux-b-browser-official.sqlite` とlocal testing serverを使用した。通常local DBは使用していない。

- Chrome headless exit: 0
- HTTP 5xx: 0
- Desktop: 1280 × 1000
- Mobile: 390 × 844
- Browser Print: A4 PDF / 1,041,072 bytes
- Permission負例: editor revoke後もRead可 / Manage 403
- archive / reopen: 成功
- reduced-motion: Content表示維持
- official SVG: HTTP取得・naturalWidth確認
- photo dependency: Company Context body内0件

Local artifact（gitignored）:

| File | Size | SHA-256 |
|---|---:|---|
| `storage/app/pux-b-official-desktop.png` | 2,522,340 | `3EFDB0BD3D121A482496F728D468099D3A5423C5373B7D43E28330485DD735E3` |
| `storage/app/pux-b-official-desktop-outline.png` | 279,611 | `026E0DF8A3E48A33424DB6325F674EA81EAC70E4954FBEA338ECB9FE71C6869E` |
| `storage/app/pux-b-official-mobile.png` | 1,118,552 | `8FE92120917B72AA01FBF5144A7683BF06DE21036CCC51EA8BF2A82B93CD97E5` |
| `storage/app/pux-b-official-mobile-outline.png` | 114,648 | `4022A8C83C366948DF8CA9A46E647ECC5CE826944C1BA9C52886EB668BF198F8` |
| `storage/app/pux-b-official-print.png` | 576,404 | `524EBE96D76D1771BBCFDA1D4FB2FF3854CB497ACF0DAC9C351F02C7A0346639` |
| `storage/app/pux-b-official-print.pdf` | 1,041,072 | `C82564AC33D81469246734E21A1B2F8590AE055DBDF76FD25B8D388498DC0B3E` |

## 8. Guarantee / Non-Guarantee

### 保証したこと

- 公式ブランドシンボルを原本由来Assetとして表示する。
- 公式サイトと同じPaper / Teal / Green / Ring / Line / Dotの世界観でBusiness Domainを読む。
- 5軸Visualが保存済みDataと対応し、Scroll / Touch / Keyboardへ追従する。
- JavaScript、Motion、Printの状態にかかわらず本文を読める。
- 既存Data、Permission、History、Writerを変更しない。

### 保証していないこと

- Organizationごとの自由Theme / Layout Builder
- 写真・Media管理
- AIによるCopy生成
- Production表示
- 公式サイトAssetの自動同期

公式サイトAssetが将来更新された場合、Product側Assetは意図せず自動変更されない。差分を確認して明示的に更新する。

## 9. Final

Business Domain Readは、公式Company OSブランドシンボルを共有しながら、保存済み事業Dataを5軸で読むCompany Context固有のPresentationとなった。

通常local DB、Production DB、Production server、公式サイトRepositoryへ変更は加えていない。Production Deployは実施していない。
