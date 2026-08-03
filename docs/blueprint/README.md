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

表紙と読み方を含め、完成時は全37ページ前後を想定します。ページ数は内容を削るための上限ではなく、一つのメッセージを一枚で理解できる密度を保つための目安です。

| 区分 | 章 | 中心となる問い | 目安 | 状態 |
|---|---|---|---:|---|
| Front | 表紙・Blueprintの読み方 | この資料は何で、どう読めばよいか | 2ページ | 構成確定 |
| Chapter 1 | Company OSはなぜ必要か | 日本を支える中小企業が、自ら進化し続けるために何が必要か | 5ページ | レビュー完了 |
| Chapter 2 | 会社の進化は、止まらない。 | 「改善に終わりはない」とは何を意味するか | 5ページ | 初版作成済み |
| Chapter 3 | 会社の進化を構成する概念 | 観察、意味付け、改善、判断、結果、学びに、どのような名前と役割を与えるか | 6ページ | 未作成 |
| Chapter 4 | 会社の意味と知能 | 概念同士のつながり、問い、根拠、説明責任がなぜ必要か | 5ページ | 未作成 |
| Chapter 5 | Company OSのプロダクト構造 | 標準機能、実行モジュール、AIはどう位置付くか | 5ページ | 未作成 |
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

### Chapter 3: 会社の進化を構成する概念

主要参照:

- [`../product/company-os-core-model.md`](../product/company-os-core-model.md)
- [`../product/company-os-domain-model.md`](../product/company-os-domain-model.md)
- [`../architecture.md`](../architecture.md)
- [`../philosophy.md`](../philosophy.md)

扱う内容:

- Direction、Observation、Sense、Improvement、Decision
- Task、Project、Result、Knowledge、AI Proposal
- 事実、解釈、判断、実行の分離
- ProjectはImprovementを実現する実行モジュールであること

### Chapter 4: 会社の意味と知能

主要参照:

- [`../product/company-os-relationship-model.md`](../product/company-os-relationship-model.md)
- [`../product/company-os-query-model.md`](../product/company-os-query-model.md)
- [`../product/company-os-domain-model.md`](../product/company-os-domain-model.md)

扱う内容:

- Company OSは意味のネットワークであること
- Relationshipが保持する理由、根拠、確信度、時間、責任
- Company OSが答えるべき問い
- Evidence、反証、不確実性、説明責任
- 「現時点では判断できない」と言えるCompany Intelligence

### Chapter 5: Company OSのプロダクト構造

主要参照:

- [`../README.md`](../README.md)
- [`../architecture.md`](../architecture.md)
- [`../product/company-os-core-model.md`](../product/company-os-core-model.md)
- [`../product/company-os-value.md`](../product/company-os-value.md)
- [`../roadmap.md`](../roadmap.md)

扱う内容:

- Company OSを上位サービスとするブランド構造
- 会社・経営指針・経営数値・改善・Knowledgeなどの標準領域
- RISE GATE OSをProject Management Engineとして位置付ける考え方
- 会社や拠点が選択して利用する追加モジュール
- 全体を横断して支援するAI

編集上の注意:

標準機能と追加モジュールの正式な境界は、既存ドキュメントだけでは未確定の部分があります。Chapter 5を作成する前に、経営指針、経営数値、組織・メンバー基盤、Branch、HIT-HUB機能統合の判断を別途記録します。

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

## 次に行うこと

Chapter 2のメッセージ、用語、順序を確認してからChapter 3へ進みます。現時点ではChapter 3以降の本文を作成しません。
