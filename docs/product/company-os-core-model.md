# Company OS Core Model

## 文書の位置付け

- Phase: Phase2-1
- 状態: Core Model v1.0
- 対象: Company OSの思想、概念、判断原則
- 対象外: 画面、Controller、URL、物理テーブル、実装方式

この文書はCompany OSの憲法です。機能、画面、データ、AI、運用は、このCore Modelから導きます。

既存資料と矛盾する場合は、後から確定したこの文書をCompany OSの上位思想として扱います。変更するときは直接意味を書き換えるのではなく、変更理由と判断履歴を残します。

## Core Statement

> Company OSは、会社に起きる変化を観察し、意味を見いだし、改善へ育て、実行と学習を次の観察へつなぐOperating Systemです。

Company OSは、業務、問題、Project、Taskの完了を集計するためのシステムではありません。

会社が目指す方向と現在地をつなぎ、人とAIによる判断、実行、結果、知識が途切れず循環する状態を支えます。

## Continuous Evolution

### 改善に終わりはない

Taskは完了します。

Projectは終了します。

しかし、そのResultは終点ではありません。Resultは必ず新しいObservationとして会社へ戻り、次のSense、Improvement、Decisionへつながります。

> Company OSには完了がありません。

> Company OSは、完了を管理するOSではなく、会社の進化が止まらない循環を支えるOperating Systemです。

### 始点も終点もない

Company OSのCore Modelは直線的なワークフローではなく円環です。

```text
Direction
    ↓
Observation
    ↓
Sense
    ↓
Improvement
    ↓
Decision
    ↓
Execute
    ↓
Result
    ↓
Knowledge
    ↓
Observation
```

便宜上Directionから説明しますが、実際の運用に唯一のスタート地点はありません。

- ResultからObservationが生まれる。
- Knowledgeによって過去のObservationの意味が変わる。
- ObservationによってDirectionの見直しが必要になる。
- Projectの途中から新しいImprovementが生まれる。
- 実行しないDecisionも、後の観察対象になる。

この循環全体を`Continuous Evolution`と呼びます。

## 中心と入口

- Company OSの入口は`Observation`です。
- Company OSの中心概念は`Improvement`です。
- `Project`はImprovementを実現するための実行モジュールです。
- `Result`は実行の終点ではなく、次のObservationの起点です。
- `Knowledge`は次の観察、意味付け、判断に再利用する会社の記憶です。

## Core Concepts

### Direction

会社がどこへ向かい、何を大切にするかを示す判断基準です。

Directionは一度通過する工程ではなく、Observationの重要性、Improvementの目的、Decisionの妥当性、Resultの評価を継続的に照らします。

### Observation

会社の内外で起きた事実、変化、声、違和感、兆候の記録です。

Observationの時点では、それを問題、原因、改善と決めません。AIによる検知もObservation候補になりますが、人の観察と出所を区別します。

### Sense

一つまたは複数のObservationが会社にとって何を意味するかという意味付け、解釈、仮説です。

Observationは事実、Senseは解釈です。一つの事実に複数のSenseが存在して構いません。Senseは真実として確定するのではなく、根拠と反証可能性を持つ現時点の理解として扱います。

### Improvement

現在地から目指す未来へ近づくために、会社が育てる変化の単位です。

Improvementは解決方法ではありません。「CRMを導入する」ではなく、「問い合わせ対応の抜け漏れをなくす」のように、実現したい変化を表します。

### Decision

誰が、何を根拠に、会社として何をするか、または何をしないかを決めた記録です。

承認、却下、保留、追加観察、再検討を含みます。AIは選択肢を提示できますが、会社の正式な責任は人が担います。

### Task

Improvementを前進させる、担当者と完了条件を持つ具体的行動です。

Taskには完了があります。ただし、完了したTaskから何が起きたかはResultとして観察します。

### Project

複数の人、期間、工程、成果物を必要とするImprovementを実現するための実行モジュールです。

Projectには終了があります。ただし、Projectの終了はImprovementの完了を意味しません。終了後にResultを評価し、新しいObservationへ戻します。

### Result

Task、Project、Experiment、Decisionなどの実行によって、実際に何が起きたかを表す事実です。

Resultは完了報告や成果物だけではありません。数値変化、反応、影響、失敗、変化なし、想定外の出来事を含みます。

### Knowledge

Observation、判断、実行、Resultから得た、次の観察や判断に再利用できる会社の記憶です。

Documentは情報を保持する器です。Knowledgeは、適用条件や根拠を持ち、次の判断に利用できる意味です。

### AI Proposal

AIが会社の正式情報をもとに作成した、まだ人が承認していない候補です。

AI ProposalはObservation、Sense、Improvement、Decision、実行、Knowledgeの各段階に存在できます。承認と反映を分け、AI Proposal自体を会社の正式判断とは扱いません。

## Concept Network

基本循環は一対一の直線ではありません。

```text
Observation ─┐
Observation ─┼→ Sense ─┬→ Improvement ─┐
Observation ─┘          └→ Improvement ─┼→ Decision
                                         ↓
                              Task / Project / Experiment
                                         ↓
                                      Result
                                      ├→ Knowledge
                                      └→ Observation
```

- 複数のObservationを一つのSenseが解釈できます。
- 一つのObservationから複数のSenseが生まれます。
- 一つのSenseから複数のImprovementが生まれます。
- 一つのImprovementに複数回のDecisionが行われます。
- 複数のImprovementを一つのProjectで実行できます。
- 一つの実行から複数のResultが生まれます。
- Resultは一つ以上のObservationを生成または関連付けます。
- Knowledgeはすべての段階から参照できます。

## Human and AI

### AIが支援すること

- 変化を検知し、Observation候補を提示する。
- 関連するObservationとKnowledgeを探す。
- Sense候補と反証候補を提示する。
- Improvement候補を提示する。
- 選択肢、効果、工数、リスクを比較する。
- Task、Project、Experimentを提案する。
- Resultを整理し、Knowledge候補を作る。

### 人が責任を持つこと

- 会社のDirectionを定める。
- Observationを会社の正式情報として確認する。
- Senseの妥当性を判断する。
- 何をImprovementとして育てるか決める。
- 実行するか、しないかをDecisionとして残す。
- 予算、権限、責任を引き受ける。
- Resultを評価する。
- Knowledgeを会社の正式な知識にする。

> AIは候補を生み、人は会社として意味と責任を与えます。

## Event Storming

Company OSは「状態」だけでなく、「会社で何が起きたか」を過去形のDomain Eventとして捉えます。

主なDomain Eventは次のとおりです。

```text
Directionが定められた
Observationが記録された
Observation同士が関連付けられた
Senseが提案された
Senseが支持された
Senseが否定された
Improvementが発見された
ImprovementがDirectionに関連付けられた
Decisionが要求された
実行が承認された
追加観察が決定された
Taskが完了した
Projectが終了した
Resultが記録された
Resultが評価された
Knowledgeが承認された
ResultからObservationが生成された
```

Event Stormingはドメインを発見する設計手法として採用します。Event Sourcingは前提にしません。監査や復元の必要性が明確な領域だけ、将来個別に検討します。

## Invariants

今後の設計と実装は、次の不変条件を守ります。

1. Company OSには全体としての完了状態を設けない。
2. Taskの完了とProjectの終了を、Improvementの完了と同一視しない。
3. Resultは終点にせず、一つ以上のObservationへ接続する。
4. Observationの事実とSenseの解釈を混在させない。
5. Improvementは実行手段ではなく、実現したい変化を表す。
6. Decisionは判断者、根拠、選択肢、判断理由を失わない。
7. AI Proposalと会社の正式なDecisionを分離する。
8. Knowledgeは根拠、適用条件、由来を持つ。
9. すべての概念について、何から生まれ、何を生んだかを追跡できるようにする。
10. 通常の循環を示しても、緊急対応などの例外的な順序を禁止しない。
11. 例外的な順序でも、後から根拠、結果、学びを補完できるようにする。
12. Directionはすべての段階から参照でき、Resultによって見直すことができる。

## Product Design Test

新しい機能や画面を考える前に、次を確認します。

- 何を観察できるようになるのか。
- 事実と解釈を分けているか。
- どのImprovementを育てるためのものか。
- 人が判断すべき場所を守っているか。
- ProjectやTaskを目的化していないか。
- 実行後のResultを観察できるか。
- ResultがKnowledgeと次のObservationへ戻るか。
- 会社の進化を途中で「完了」にしていないか。

この問いに答えられない機能は、Company OSのCore Modelへ接続できていません。

