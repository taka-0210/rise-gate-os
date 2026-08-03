# Chapter 5 最終推奨構造案

## この資料の位置付け

- 対象: Company OSの長期的なプロダクト全体構造
- 状態: 最終推奨案・承認前
- 目的: 比較案を一つに絞り、Chapter 5本文と基準鳥瞰図の前提を確定する
- 対象外: 正式名称、販売プラン、画面、データベース、実装方式

この資料では、`Foundation`、`Company Core`、`Operational Capabilities`、`Execution Boundary`を仮の呼び名として使います。

名前を確定するためではなく、それぞれの責任を区別して最終構造を確認するためです。

---

## 結論

Company OSの最終推奨構造は、次の形です。

> 会社の正式な土台の上に、進化を担うCoreを置く。Coreの周囲に実行境界を持ち、その外側へ会社ごとの業務能力を接続する。

これは、4つの上下階層ではありません。

```text
Foundation
  Company OS全体を支える正式な会社情報

Company Core
  会社が観察し、意味を考え、改善し、判断し、学ぶ中心

Execution Boundary
  CoreのDecisionを行動へ渡し、ResultをCoreへ戻す境界

Operational Capabilities
  顧客対応、会議、環境整備など、会社ごとの実務を担う接続領域
```

構造としては、土台・中心・境界・外部へ広がる実務です。

```text
┌─────────────────────────────────────────────────┐
│                    COMPANY OS                   │
│                                                 │
│       OPERATIONAL CAPABILITIES                  │
│   会社ごとの実務領域・必要に応じて接続             │
│                                                 │
│      ┌───────────────────────────────────┐      │
│      │        EXECUTION BOUNDARY         │      │
│      │                                   │      │
│      │  Decision → Action → Result       │      │
│      │  Task / Project / Experiment      │      │
│      │  Routine / Workflow               │      │
│      │                                   │      │
│      │   ┌───────────────────────────┐   │      │
│      │   │       COMPANY CORE        │   │      │
│      │   │                           │   │      │
│      │   │ Observe → Sense           │   │      │
│      │   │ → Improve → Decide        │   │      │
│      │   │ → Result → Learn          │   │      │
│      │   └───────────────────────────┘   │      │
│      └───────────────────────────────────┘      │
│                                                 │
│  ───────────────── FOUNDATION ────────────────  │
│  理念・指針・数値・借入・組織・メンバー            │
└─────────────────────────────────────────────────┘
```

この図でExecutionは第4層ではありません。

Company Coreが現実世界と接する境界です。外へ向かうときはDecisionを実行へ変え、内へ戻るときは実際に起きたResultを受け取ります。

---

## 1. Foundationの責任

### 責任

> Company OS全体が参照する、会社の正式な文脈と現在地を支える。

Foundationは、会社の存在と判断の前提になる情報へ責任を持ちます。

- 会社は何のために存在するか。
- どのような会社を目指しているか。
- 現在の経営状態はどうか。
- どのような財務上の条件があるか。
- 誰が会社に属し、どの責任を持つか。

Foundationは単にCompany Coreの下にあるデータ置き場ではありません。

Company Core、Execution Boundary、Operational Capabilitiesのすべてから参照される、Company OS共通の正式情報です。

### 境界

Foundationは、変化の意味を決めません。

経営数値が変化した事実を保持しても、その変化をどう捉え、何を改善するかはCompany Coreの責任です。

---

## 2. Company Coreの責任

### 責任

> 会社の進化に意味と連続性を与える。

Company Coreは、Company OSでなければ成立しない独自の中心です。

```text
Direction
Observation
Sense
Improvement
Decision
Result
Knowledge
Relationship
Company Dialogue
Company AI
```

ここでは、会社で起きたことを単に保存するのではなく、何から生まれ、何を根拠に判断し、何を学んだかをつなぎます。

### 境界

Company Coreは、顧客管理や会議運営などの業務固有機能を抱え込みません。

また、Projectの工程を管理すること自体を目的にしません。

Coreが責任を持つのは、実行する理由、正式なDecision、実行から得たResult、そのResultが次のObservationへ戻ることです。

---

## 3. Execution Boundaryの責任

### 責任

> Company Coreの判断を現実の行動へ変換し、現実で起きたことをCompany Coreへ返す。

Execution Boundaryは、保存領域や独立製品ではありません。

Company Coreと現実の仕事が接する、双方向の共通契約です。

```text
外へ向かう責任
  Decisionを受け取る
  適切な実行方式を選ぶ
  担当、期限、順序、完了条件を明確にする
  必要な業務領域へ行動を渡す

内へ戻す責任
  完了・終了を受け取る
  実際に起きたResultを記録する
  想定外、失敗、変化なしも失わない
  ResultをKnowledgeと次のObservationへ接続する
```

### 実行方式

```text
Task
  小さく明確な行動

Project
  複数の人、期間、工程、成果物を伴う実行

Experiment
  仮説を確かめる試行

Routine / Workflow
  繰り返し行う定常的な実行
```

これらは機能カテゴリではなく、Decisionを実行へ移す方式です。

### 最小能力と拡張能力

Company OSがContinuous Evolutionを成立させるため、次の最小能力は標準で必要です。

```text
Decisionと実行をつなぐ
実行の責任者を明らかにする
実行の状態を把握する
ResultをCompany Coreへ戻す
```

一方、次のような高度なProject Management機能は、必要な会社が利用する拡張能力にできます。

```text
Roadmap
複雑なTask階層
ガント
工数
見積
実施計画書
複数Projectの横断管理
```

つまり、Company OSは実行との接続を標準で持ちますが、すべての会社へ高度なProject Managementを要求しません。

---

## 4. Operational Capabilitiesの責任

### 責任

> 特定の業務対象とルールを扱い、その領域で起きた変化・行動・結果をCompany OSへ接続する。

ここでは、初版で使った`Business Modules`という名称を確定しません。

理由は、`Module`が製品の販売単位、画面メニュー、技術部品のどれを意味するか曖昧だからです。

責任設計上は、まず`Operational Capabilities`と仮に呼びます。

```text
顧客に関する業務能力
会議と合意に関する業務能力
職場環境に関する業務能力
業種固有の業務能力
外部システムから情報を受け取る能力
```

各能力は、次の3方向でCompany OSへ接続します。

```text
Signal / Event
  業務で起きた変化をObservation候補として渡す

Action
  Decisionに基づく業務上の行動を実施する

Result
  行動によって実際に起きたことを返す
```

### 境界

Operational Capabilityは、Company CoreのConceptを独自に複製しません。

例えばCRMの中だけに「改善」「判断」「学び」を閉じ込めず、Company CoreのImprovement、Decision、Knowledgeへ接続します。

### 製品パッケージとの違い

責任上の業務能力と、提供上のModuleは別です。

一つの製品Moduleが複数の業務能力をまとめることも、一つの業務能力を複数のModuleで提供することもあります。

したがって、Chapter 5の基準鳥瞰図では責任領域を示し、価格表や機能一覧で初めて提供Moduleを示します。

---

## 5. Projectの最終推奨位置

Projectは、Operational Capabilityではありません。

Projectは、Execution Boundaryが持つ実行方式の一つです。

```text
Improvement
    ↓
Decision
    ↓
実行方式を選ぶ
    ├ Task
    ├ Project
    ├ Experiment
    └ Routine / Workflow
```

Projectを選ぶ条件は、Core Modelの定義を維持します。

> 複数の人、期間、工程、成果物を必要とするImprovementを実現するときにProjectを使う。

Projectは複数のOperational Capabilitiesをまたぐことができます。

```text
Project: 問い合わせ体験を改善する
  顧客対応の業務能力
  Webサイト運用の業務能力
  会議と合意の業務能力
```

逆に、Operational Capabilityのすべての行動がProjectを必要とするわけではありません。

```text
顧客へ一本電話する
  Task

新しい案内文の反応を確かめる
  Experiment

問い合わせ対応を毎朝確認する
  Routine
```

> ProjectはCompany OSの中心でも、業務領域でもなく、複雑な改善を前進させるための実行方式です。

---

## 6. 六つの実例による構造テスト

### 例1: 人の気付きだけから始める会社

```text
社員の気付き
  → Observation
  → Improvement
  → Decision
  → Task
  → Result
  → Knowledge
```

Operational Capabilityを追加していなくても循環できます。

判定: 成立する。

### 例2: 顧客対応で同じ要望が増えた

```text
顧客領域のSignal
  → Observation
  → Improvement
  → Decision
  → TaskまたはExperiment
  → 顧客領域でAction
  → Result
```

業務領域と実行方式を分離できます。

判定: 成立する。

### 例3: Webサイトを全面的に作り直す

```text
複数のImprovement
  → Decision
  → Project
  → 制作、顧客対応、会議を横断
  → 複数のResult
```

Projectが複数領域を横断できます。

判定: 成立する。

### 例4: 毎日の環境点検

```text
Routine
  → 環境領域で点検
  → 変化があればObservation
  → 必要ならImprovement
```

定常業務を無理にProjectへ変えません。

判定: 成立する。

### 例5: 会議で判断だけが行われた

```text
会議領域でDecisionが記録される
  → 実行しないと決めた
  → 後日のObservation対象になる
```

実行しないDecisionもCoreに残せます。

判定: 成立する。

### 例6: 外部基幹システムが数値変化を検知した

```text
外部システムのSignal
  → Observation候補
  → 人が確認
  → Sense
  → 必要な実行方式を選ぶ
```

API連携をCompany OSの前提にせず、接続後も同じ構造を使えます。

判定: 成立する。

---

## 7. この構造を守る不変条件

1. Foundationは正式な会社情報を支えるが、変化の意味を決めない。
2. Company Coreは会社の進化へ責任を持つが、業務固有機能を抱え込まない。
3. Execution BoundaryはDecisionを行動へ渡し、Resultを戻すが、Improvementの重要性を決めない。
4. Operational Capabilitiesは固有業務へ責任を持つが、Observation、Decision、Knowledgeを分断しない。
5. Projectをすべての実行の必須条件にしない。
6. Projectの終了をImprovementの完了と同一視しない。
7. 高度なProject Management機能がなくても、最小のContinuous Evolutionを成立させる。
8. 実行からResultが戻らない機能を、Company OSへ接続済みとみなさない。
9. 責任上の構造と、販売・契約・画面上のModule構成を混在させない。
10. 新しい業務能力が増えても、Company CoreのConceptを複製しない。

---

## 8. 名称についての判断

この段階では、正式名称を決めません。

責任設計から、次のことだけを確定候補とします。

```text
RISE GATE OS
  開発の由来を示す現在の名称
  Company OS内の責任領域名としては再検討が必要

Project Management Engine
  Projectという一つの実行方式には合う
  Execution Boundary全体の名称としては狭い

Evolution Engine
  Company Coreの責任と重なるため採用しない

Company Execution Engine
  高度な共通実行基盤を実装するときの技術名称候補
  現時点で利用者向けブランドにはしない

Business Modules
  提供単位の名称としては将来使える
  責任領域の正式名称としては保留する
```

Chapter 5では、承認後も名称より責任を先に説明します。

---

## 9. Chapter 5へ反映する最終方針

この案が承認された場合、Chapter 5を次の順番へ改訂します。

1. Company OSは機能の集合ではなく、責任がつながる基盤である。
2. FoundationはCompany OS全体の正式な土台である。
3. Company Coreは会社の進化へ責任を持つ。
4. Execution Boundaryは判断と現実を双方向につなぐ。
5. 会社ごとの業務能力は、Signal・Action・Resultで接続する。
6. Projectは複雑な改善のための実行方式である。
7. 最後に、土台・中心・境界・広がる実務を一枚の鳥瞰図で示す。

鳥瞰図では、縦に積み上がる製品階層ではなく、次の世界観を基準にします。

```text
Foundation = 会社の大地
Company Core = 会社が考え、学ぶ中心
Execution Boundary = 判断と現実を行き来する境界
Operational Capabilities = 会社ごとに外へ広がる実務
```

> **Company OSは、会社の土台の上で、思考と現実を往復させ、あらゆる実務から生まれた結果を次の進化へ戻すOperating Systemです。**

---

## 承認後に行うこと

- Chapter 5本文をこの構造へ全面改訂する。
- 現在の縦3層の鳥瞰図を、土台・中心・境界・広がる実務の図へ置き換える。
- RISE GATE OSとCompany Execution Engineの正式名称は、構造承認とは分けて検討する。
- Product構造承認後に、Chapter 6へ進む。

承認前は、Chapter 5本文と現在の鳥瞰図を最終版として扱いません。
