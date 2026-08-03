# Chapter 5 責任設計レビュー

## この資料の位置付け

- 対象: Chapter 5のプロダクト全体構造
- 状態: 比較完了・最終推奨案作成済み
- 目的: 名称より先に、各領域が何に責任を持つかを確定する
- 対象外: 画面、データベース、Controller、物理ディレクトリ、正式ブランド名の決定

Chapter 5初版では、Business Modulesの中にProject Management、CRM、Meeting、Environmentを並べました。

しかし、この並びには二種類の異なるものが混在しています。

```text
CRM / Meeting / Environment
  何について仕事をするか
  = 業務領域

Task / Project / Experiment
  どのように改善を実行するか
  = 実行方式
```

Project ManagementをCRMなどと同じ階層へ置くと、「業務領域」と「実行方式」の違いが見えなくなります。

この資料では、名称を決める前に責任と境界を整理します。

比較後に1案へ絞った結論は、[`chapter-05-final-structure-proposal.md`](chapter-05-final-structure-proposal.md)にまとめています。

---

## 1. 先に確定したい責任

### Foundationの責任

> 会社が何者で、何を大切にし、誰が属し、現在どの状態にあるかという、判断の基準となる正式情報を支える。

主な対象:

- 経営理念
- 経営指針
- 経営数値
- 借入
- 組織・メンバー

Foundationが責任を持たないこと:

- 変化の意味を決めること
- Improvementを選ぶこと
- 実行方法を計画すること
- 業務領域固有の処理を行うこと

### Company Coreの責任

> 会社の変化を受け取り、意味を考え、改善へ育て、判断し、実行と学びを次の観察へ戻す。

主な対象:

- Direction
- Observation
- Sense
- Improvement
- Decision
- Result
- Knowledge
- Relationship
- Company Dialogue
- Company AI

Company Coreが責任を持たないこと:

- CRM固有の顧客対応ルール
- 会議室や議事進行などMeeting固有の運用
- Projectの工程そのものを会社の目的にすること
- 各業務の詳細機能をすべて内包すること

### Business Modulesの責任

> 特定の業務領域を運営し、そこで生まれた変化と結果をCompany Coreへ返す。

例:

- CRM: 顧客、接点、商談、対応という業務領域
- Meeting: 会議、議題、発言、合意という業務領域
- Environment: 職場環境、点検、整備という業務領域
- 将来のHIT-HUB統合領域: HIT-HUB由来の業務機能

Business Modulesが責任を持たないこと:

- 会社全体のDirectionを決めること
- Company OS共通のObservationやKnowledgeを独自形式で分断すること
- すべての仕事をProjectとして扱うこと
- Company Coreから切り離された機能の中だけで学びを閉じること

### 実行を支える領域の責任

> Decisionを現実の行動へ移し、誰が・何を・いつまでに・どのまとまりで行うかを支え、実際に起きたResultをCompany Coreへ返す。

実行方式の候補:

```text
Task
  一人または小さな単位で完了条件を持つ行動

Project
  複数の人、期間、工程、成果物を伴うまとまり

Experiment
  仮説を確かめるための期限と評価条件を持つ試行

Routine / Workflow
  繰り返し行われる定常的な実行
```

重要なのは、すべてをProjectにすることではありません。

Core Modelでは、Projectは「複数の人、期間、工程、成果物を必要とするImprovement」の実行方式です。小さな行動はTask、仮説検証はExperimentというように、ImprovementとDecisionに合う方式を選びます。

> Projectは重要な実行方式ですが、実行そのものの総称ではありません。

---

## 2. 構造案の比較

### 案A: Project ManagementをBusiness Moduleとして並べる

```text
Foundation
    ↓
Company Core
    ↓
Business Modules
  ├ Project Management
  ├ CRM
  ├ Meeting
  └ Environment
```

良い点:

- 現在の機能配置として説明しやすい。
- 利用者が「Project機能を選ぶ」と理解しやすい。

問題点:

- 実行方式と業務領域が同じ階層に混在する。
- CRMで生まれた改善をProjectで実行するなど、領域横断の関係を表しにくい。
- ProjectがCompany OSの目的であるように見えやすい。

評価:

> 現在の製品メニューとしては成立しますが、10年以上使う全体構造には採用しません。

### 案B: Executionを独立した第4層にする

```text
Foundation
    ↓
Company Core
    ↓
Execution Layer
  ├ Task
  ├ Project
  ├ Experiment
  └ Routine / Workflow
    ↓
Business Modules
```

良い点:

- 実行の責任が明確になる。
- ProjectをCRMなどから分離できる。
- 共通する計画、担当、期限、進捗、Result回収を整理しやすい。

問題点:

- Foundation / Company Core / Business Modulesという分かりやすい3層が崩れる。
- Business ModulesがExecutionの下位にあるように見える。
- 顧客対応や会議のように、Projectを必要としない日常業務までExecution Layerを必ず通る誤解が生まれる。

評価:

> 内部アーキテクチャとして検討余地はありますが、Blueprintの基準図には階層が強すぎます。

### 案C: 3層を保ち、ExecutionをCompany CoreとBusiness Modulesを結ぶ横断能力にする

```text
┌─────────────────────────────────────────┐
│ BUSINESS MODULES                        │
│ CRM  Meeting  Environment  Other        │
│ 特定の業務を運営し、変化と結果を返す       │
└──────────────────┬──────────────────────┘
                   ↕
        ┌──────────────────────┐
        │ COMPANY EXECUTION    │
        │ Decisionを行動へ移す  │
        │                      │
        │ Task                 │
        │ Project              │
        │ Experiment           │
        │ Routine / Workflow   │
        └──────────┬───────────┘
                   ↕
┌─────────────────────────────────────────┐
│ COMPANY CORE                            │
│ 変化を意味・判断・学びへつなぐ             │
└──────────────────┬──────────────────────┘
                   ↕
┌─────────────────────────────────────────┐
│ FOUNDATION                              │
│ 会社の正式情報と判断基準を支える            │
└─────────────────────────────────────────┘
```

ここでCompany Executionは、第4の製品群ではありません。

Company CoreのDecisionを実行へ橋渡しし、Business Modules内外で行われたResultをCompany Coreへ戻す、Company OS共通の横断能力です。

良い点:

- Foundation / Company Core / Business Modulesの3つの責任を維持できる。
- Projectを一つの実行方式として正しく置ける。
- CRMの改善をProjectで実行する、会議のDecisionをTaskへする、といった横断関係を表せる。
- Projectを必要としない小さな行動や日常業務も扱える。
- 将来Business Moduleが増えても構造が崩れにくい。

注意点:

- Company Executionを第4層や独立サービスに見せない図解が必要。
- 何を共通実行能力とし、何を各Business Module固有の実行とするかは今後も境界判断が必要。
- `Company Execution`は現時点の責任領域を示す仮称であり、正式ブランド名ではない。

評価:

> 現時点の基準案とします。

---

## 3. 基準案の責任フロー

案Cでは、会社の進化は次のようにつながります。

```text
Foundation
  会社の理念、指針、数値、組織を支える
        ↓ 判断の基準と現在地

Company Core
  Observationを受け取り、Sense、Improvement、Decisionへ育てる
        ↓ 実行するというDecision

Company Execution
  適切な実行方式を選ぶ
  Task / Project / Experiment / Routine
        ↓ 業務上の行動

Business Modules または領域横断の実行
  顧客対応、会議、環境整備、制作、開発などを行う
        ↓ 実際に起きたこと

Company Execution
  完了や終了だけでなく、Resultを回収する
        ↓

Company Core
  Resultを評価し、Knowledgeと次のObservationへつなぐ
        ↓ 必要なら見直す

Foundation / Direction
```

この構造では、Business Moduleがなくても実行できます。

例えば、人が登録したObservationから小さなTaskを作り、Resultを残すだけでもContinuous Evolutionは成立します。

Business Moduleは、特定領域の仕事をより深く、便利に、安全に行う必要が生まれたときに接続します。

---

## 4. 境界を判断するためのテスト

新しい機能をどこへ置くか迷ったときは、次の問いで判断します。

### Foundationか

- 会社の正式な属性、基準、現在地を支える情報か。
- 特定の改善や業務が終わっても残り続けるか。
- 複数のConceptやBusiness Moduleから共通して参照されるか。

### Company Coreか

- 変化の観察、意味付け、改善、判断、結果、学びに責任を持つか。
- 業種や特定業務に依存せず、会社の進化全体で使うか。
- Relationshipと根拠をたどれる必要があるか。

### Company Executionか

- Decisionを具体的な行動へ移すものか。
- 担当、期限、順序、依存、完了条件、Result回収を扱うか。
- 複数のBusiness Moduleから共通して利用できるか。

### Business Moduleか

- 顧客、会議、環境など、固有の業務対象とルールを持つか。
- その領域ならではのObservationと行動があるか。
- Company CoreとCompany Executionへ接続しても、固有の価値が残るか。

---

## 5. Project Managementの暫定位置付け

Project Managementという言葉には、二つの意味が混ざりやすいため分けます。

```text
Project
  Improvementを実行する一つの方式

Project Management機能
  Projectを計画し、複数の人、期間、工程、成果物を調整する機能群
```

責任上は、どちらもCompany Executionの中に置きます。

ただし、Project Management機能をCompany OSの利用者が選択する追加パッケージとして提供する可能性は残ります。

```text
概念上の位置
  Company Executionの実行方式

提供上の位置
  必要な会社が有効化する機能パッケージになり得る
```

概念上の分類と、販売・契約上のモジュール分類を同じ図で表さないことが重要です。

---

## 6. 命名は責任確定後に行う

現段階では、`Company Execution`を責任領域を指す仮称として使います。

次の名称はまだ確定しません。

- RISE GATE OS
- Company Execution Engine
- Project Management Engine
- Evolution Engine

責任設計から分かる現時点の評価は次のとおりです。

| 名称 | 責任との整合 | 現時点の評価 |
|---|---|---|
| Project Management Engine | Project以外のTaskやExperimentを含めにくい | 狭い |
| Evolution Engine | 意味付けや学習を担うCompany Coreと重なる | 広すぎる |
| Execution Engine | 責任は合うが、利用者向けブランドとしては機械的 | 役割名候補 |
| Company Execution | Company OS内の責任領域として理解しやすい | レビュー用仮称 |
| Company Execution Engine | 共通実行基盤を実装するときの内部・技術名称になり得る | 将来候補 |

> 先に「何に責任を持つか」を確定し、その責任を最も誤解なく伝える名前を後から選びます。

---

## 7. Chapter 5改訂前に確定すること

1. Foundation、Company Core、Business Modulesの責任定義を採用するか。
2. Company Executionを第4層ではなく横断能力として扱うか。
3. Task、Project、Experiment、Routine / Workflowを実行方式として扱うか。
4. Business ModuleがなくてもCompany CoreとCompany Executionだけで循環が成立することを認めるか。
5. Project Managementの概念上の位置と、提供上のパッケージを分けるか。
6. 上記を確定してから、Chapter 5本文と全体鳥瞰図を改訂する。

この判断が終わるまで、Chapter 5の現行鳥瞰図をCompany OSの最終基準図として使用しません。
