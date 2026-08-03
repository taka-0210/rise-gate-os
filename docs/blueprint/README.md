# Company OS Blueprint

## Blueprintの位置付け

Company OS Blueprintは、営業資料でも、画面・データベースの設計書でもありません。

Company OSの思想、構造、価値、体験、プロダクト全体を、人が理解する順番に再編集するマスター資料です。

既存のREADMEと設計ドキュメントを正本とし、Blueprintはそれらを置き換えません。各ページから参照元をたどれる状態を保ち、既存資料にない内容を補う場合は、そのことを明示します。

想定する主な読者は次のとおりです。

- Company OSを初めて知る利用企業
- 導入と運用を支援するパートナー
- Company OSを運営するRISE GATE
- 設計・実装・資料制作に関わるメンバーとAI

## 編集原則

1. 機能一覧ではなく、会社が進化し続ける理由から読む。
2. 既存文書を新しく言い換えるのではなく、理解の順番に再編集する。
3. Core Modelなどの正本が持つ意味を、説明の都合で変えない。
4. 思想、確定した設計、現在の実装、将来構想を混在させない。
5. 一つのページでは、一つの中心メッセージだけを扱う。
6. 図解だけで意味を完結させず、参照元と短い説明を添える。
7. Blueprint自体も、会社の観察と判断を受けて育てる。
8. 読み手がその時点でまだ知らない用語を、説明より先に使わない。
9. `思想 → Concept → Relationship → Product → 実装`の理解順序を守る。

既存文書の内容が矛盾する場合は、原則として後から確定した上位文書を優先します。特にCompany OSの思想については、`company-os-core-model.md`を最上位の規範として扱います。

### 理解の順序

```text
Chapter 2  思想
    ↓
Chapter 3  Concept
    ↓
Chapter 4  Relationship
    ↓
Chapter 5  Product
    ↓
Chapter 8  現在の実装とこれから
```

各Chapterは、前のChapterで理解した言葉だけを使って次の考え方を説明します。正式な用語が必要な場合は、そのChapter内で日常の言葉から意味を説明してから名前を示します。

## 全体構成

表紙と読み方を含め、完成時は全38ページ前後を想定します。ページ数は内容を削るための上限ではなく、一つのメッセージを一枚で理解できる密度を保つための目安です。

| 区分 | 章 | 中心となる問い | 目安 | 状態 |
|---|---|---|---:|---|
| Front | 表紙・Blueprintの読み方 | この資料は何で、どう読めばよいか | 2ページ | 構成確定 |
| Chapter 1 | Company OSはなぜ必要か | 日本を支える中小企業が、自ら進化し続けるために何が必要か | 5ページ | レビュー完了 |
| Chapter 2 | 会社の進化は、止まらない。 | 「改善に終わりはない」とは何を意味するか | 5ページ | レビュー反映済み |
| Chapter 3 | 会社の進化を構成する言葉 | 観察、意味付け、改善、判断、結果、学びに、どのような名前と役割を与えるか | 7ページ | レビュー反映済み |
| Chapter 4 | 会社の出来事は、つながった瞬間に意味を持つ。 | 概念同士のつながり、問い、根拠、説明責任がなぜ必要か | 5ページ | レビュー完了 |
| Chapter 5 | 会社の土台・思考・実行を、一つにつなぐ | Company OSを支える3層と、その役割の違い | 6ページ | 初版作成済み |
| Chapter 6 | 会社・人・パートナー | 誰が、どの会社・拠点・役割でCompany OSを使うか | 4ページ | 未作成・一部要定義 |
| Chapter 7 | Company OSを会社へ導入する | 会社の観察設計と対話をどう始めるか | 3ページ | 未作成 |
| Chapter 8 | 現在地とこれから | 現在何が動き、どのようにCompany OS全体へ育てるか | 2ページ | 未作成・一部要定義 |

## 各章の参照ドキュメント

### Chapter 1: Company OSはなぜ必要か

主要参照:

- [`../product/company-os-value.md`](../product/company-os-value.md)
- [`../product/company-os-core-model.md`](../product/company-os-core-model.md)
- [`../README.md`](../README.md)
- [`../philosophy.md`](../philosophy.md)

扱う内容:

- 会社の方針、仕事、判断、結果、知識が分断される問題
- 日本を支える中小企業と、変化する経営環境
- 中小企業の進化が日本の未来につながるという希望
- AIとの仕事が個人の会話で終わる問題
- Company OSの短い定義
- Company OSがProject管理やAIツールだけではない理由

### Chapter 2: 会社の進化は、止まらない。

内部整理名: Company OSの憲法

主要参照:

- [`../product/company-os-core-model.md`](../product/company-os-core-model.md)
- [`../product/company-os-phase3-mvp.md`](../product/company-os-phase3-mvp.md)
- [`../philosophy.md`](../philosophy.md)

扱う内容:

- 会社の進化は終わらないという思想
- Company OSには完了がない
- 結果は次の観察の始まり
- 観察から学び、そして次の観察へ戻る円環
- 会社が目指す方向が循環全体を照らすこと
- 循環するたびに会社の経験と知識が育つこと

### Chapter 3: 会社の進化を構成する言葉

内部整理名: Company OS Dictionary

主要参照:

- [`../product/company-os-core-model.md`](../product/company-os-core-model.md)
- [`../product/company-os-domain-model.md`](../product/company-os-domain-model.md)
- [`../architecture.md`](../architecture.md)
- [`../philosophy.md`](../philosophy.md)

扱う内容:

- Direction、Observation、Sense、Improvement、Decision、Result、Knowledge
- その名前を選んだ理由
- 似ている一般用語との違い
- 事実、解釈、判断、結果、学びの分離

編集上の注意:

Task、Project、AI ProposalはCore Model上の重要な言葉ですが、実行手段とAI支援の位置付けを理解してから説明する方が自然です。Chapter 3へ詰め込まず、Product構造を扱うChapter 5で説明します。

### Chapter 4: 会社の出来事は、つながった瞬間に意味を持つ。

内部整理名: Relationship Model

主要参照:

- [`../product/company-os-relationship-model.md`](../product/company-os-relationship-model.md)
- [`../product/company-os-query-model.md`](../product/company-os-query-model.md)
- [`../product/company-os-domain-model.md`](../product/company-os-domain-model.md)

扱う内容:

- Company OSは意味のネットワークであること
- 一つの出来事だけでは会社を理解できないこと
- 星、星座、星雲によるCompany OSの世界観
- つながりが保持する理由、根拠、確かさ、時間、責任
- 複数の意味や反証を消さずに残す理由
- Company OSが答えるべき問い
- Evidence、反証、不確実性、説明責任
- 「現時点では判断できない」と言えるCompany Intelligence

### Chapter 5: 会社の土台・思考・実行を、一つにつなぐ

内部整理名: Company OS Product Architecture

主要参照:

- [`../README.md`](../README.md)
- [`../architecture.md`](../architecture.md)
- [`../product/company-os-core-model.md`](../product/company-os-core-model.md)
- [`../product/company-os-value.md`](../product/company-os-value.md)
- [`../common-staff-platform-foundation.md`](../common-staff-platform-foundation.md)
- [`../roadmap.md`](../roadmap.md)

扱う内容:

- Company OSは一つのシステムではなく、会社が進化し続けるための基盤であること
- Foundation、Company Core、Business Modulesの3層
- 経営理念、経営指針、経営数値、借入、組織・メンバーを支えるFoundation
- Direction、Observation、Sense、Improvement、Decision、Knowledge、Company Dialogue、Company AIを担うCompany Core
- Project Management、CRM、Meetingなど、会社ごとに選択できるBusiness Modules
- 現在のRISE GATE OSと、将来のCompany Execution Engineという役割名の検討
- HIT-HUBは将来統合を検討するBusiness Moduleであり、正式機能ではないこと
- Company OSの思想をそのまま形にした全体鳥瞰図

編集上の注意:

FoundationとCompany CoreはCompany OSを成立させる共通基盤、Business Modulesは会社ごとに選択・追加できる実務領域として区別します。RISE GATE OSの正式な将来名称、個々のBusiness Moduleの提供範囲、HIT-HUB統合は未確定事項として明示し、完成済みの機能として表現しません。

### Chapter 6: 会社・人・パートナー

主要参照:

- [`../architecture.md`](../architecture.md)
- [`../common-staff-platform-foundation.md`](../common-staff-platform-foundation.md)
- [`../database.md`](../database.md)
- [`../product/company-os-domain-model.md`](../product/company-os-domain-model.md)

扱う内容:

- Companyと内部実装上のOrganization
- UserとCompany Membershipの違い
- Company Owner、Company Admin、Member
- RISE GATEのSystem Admin
- 導入パートナーと利用企業
- Company Branchとしての本社、支店、店舗、拠点

編集上の注意:

PartnerとCompany Branchの責務、会社を新規登録できる権限、Workspaceの利用者向け表現は未確定です。既存実装をそのまま完成形として説明しません。

### Chapter 7: Company OSを会社へ導入する

主要参照:

- [`../product/company-os-phase3-mvp.md`](../product/company-os-phase3-mvp.md)
- [`../product/company-os-query-model.md`](../product/company-os-query-model.md)
- [`../operation.md`](../operation.md)

扱う内容:

- Awareness Culture
- Observation Design
- 人によるObservationから始められること
- Company Dialogue
- CSV、AI、APIへ段階的に育てる導入
- 導入パートナーが提供する価値

### Chapter 8: 現在地とこれから

主要参照:

- [`../README.md`](../README.md)
- [`../roadmap.md`](../roadmap.md)
- [`../changelog.md`](../changelog.md)
- [`../product/company-os-phase3-mvp.md`](../product/company-os-phase3-mvp.md)
- [`../product/company-os-value.md`](../product/company-os-value.md)

扱う内容:

- 現在の実装と将来構想の区別
- Observation First MVP
- RISE GATE自身による運用と学習
- HIT-HUB機能をCompany OSへ統合する将来構想
- Blueprint自身も継続的に更新されること

編集上の注意:

HIT-HUB統合、Branch機能、代理店向け機能は構想段階です。正式な判断記録が作られるまでは、確定機能として表現しません。

## 作成済みChapter

- [`chapter-01-why-company-os.md`](chapter-01-why-company-os.md): Chapter 1「Company OSはなぜ必要か」
- [`chapter-02-continuous-evolution.md`](chapter-02-continuous-evolution.md): Chapter 2「会社の進化は、止まらない。」
- [`chapter-03-company-os-dictionary.md`](chapter-03-company-os-dictionary.md): Chapter 3「会社の進化を構成する言葉」
- [`chapter-04-meaningful-relationships.md`](chapter-04-meaningful-relationships.md): Chapter 4「会社の出来事は、つながった瞬間に意味を持つ。」
- [`chapter-05-company-os-product-architecture.md`](chapter-05-company-os-product-architecture.md): Chapter 5「会社の土台・思考・実行を、一つにつなぐ」

## 次に行うこと

Chapter 5の3層構造、全体鳥瞰図、実行領域の名称候補を確認してからChapter 6へ進みます。現時点ではChapter 6以降の本文を作成しません。
