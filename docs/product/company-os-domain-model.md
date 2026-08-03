# Company OS Concept / Domain Model

## 文書の位置付け

- Phase: Phase2-2
- 状態: Logical Model v0.1
- 上位規範: `company-os-core-model.md`
- 対象: 概念ごとのデータ、関係、ライフサイクル、不変条件
- 対象外: 画面、Controller、URL、Laravel Migration、物理テーブルの確定

この文書では、Company OSのCore Modelを実装可能な論理モデルへ近づけます。既存データベースへ合わせて概念を変形せず、先にドメイン上必要な情報を定義します。

## Modeling Policy

### 会社スコープ

すべてのCore Conceptは、原則として一つのCompany Accountに所属します。現在の実装ではCompany Accountに相当する境界として`Organization`がありますが、この文書ではドメイン用語として`Company`を使用します。

### 識別子

各概念は、内部IDとは別に外部参照可能な変更されない識別子を持ちます。

```text
id
public_id
company_id
```

### 共通履歴

現在状態だけでなく、少なくとも次を追跡します。

```text
created_by
created_at
updated_by
updated_at
status
```

重要な判断や公開済み知識は上書きせず、版または後続レコードとして履歴を残します。

### 出所と作成主体

人、AI、自動検知、外部システムを区別します。

```text
actor_type: human / ai / system / external
actor_id
source_type
source_reference
```

AIが生成した情報には、使用したAI、生成時刻、根拠、確認状態を保持します。秘密情報やプロンプト全文を無条件に保存することは前提にしません。

### 時刻

保存時刻と表示時刻を区別します。アプリケーションの表示、履歴、通知、生成文書は`Asia/Tokyo`を基準にします。

## 1. Observation

### 責務

評価前の事実、変化、声、違和感、兆候を保持します。

### 論理属性

```text
Observation
  id
  public_id
  company_id
  title
  body
  observation_type
  occurred_at
  observed_at
  observer_type
  observer_id
  source_type
  source_reference
  factual_basis
  confidence
  significance
  status
  created_by
  created_at
  updated_at
```

`occurred_at`は事象が起きた時刻、`observed_at`は認識した時刻です。不明な場合を考慮し、期間や精度を将来表現できる余地を残します。

`confidence`は事実性の確度、`significance`は会社にとっての重要度候補です。確度と重要度を混同しません。

### 種別候補

```text
metric_change
customer_voice
employee_voice
project_event
operational_friction
market_change
legal_change
ai_detection
result_observation
other
```

### 状態

```text
recorded
under_review
interpreted
watching
closed
```

Observationは削除して意味を消さず、誤記訂正、重複、無効化を履歴として残します。

### 関係

- Observation `*..*` Sense
- Observation `*..*` Improvement
- Observation `*..*` Knowledge
- Observation `*..*` Observation
- Result `1..*` Observation

### 不変条件

- 事実と解釈を同じ属性へ混在させない。
- AI検知はAI検知であることを明示する。
- 出所不明の場合も、不明であることを記録する。
- Resultから生成されたObservationは元Resultを追跡できる。

## 2. Sense

### 責務

Observationが会社にとって何を意味するかという解釈、仮説、因果候補を保持します。

コード上の名称は、一般語との衝突を避けるため`Sensemaking`または`Interpretation`を候補とします。正式名称は物理設計時に決定します。

### 論理属性

```text
Sense
  id
  public_id
  company_id
  title
  interpretation
  hypothesis
  supporting_basis
  contradicting_basis
  confidence
  status
  proposed_by_type
  proposed_by_id
  reviewed_by
  reviewed_at
  created_at
  updated_at
```

### 状態

```text
proposed
discussing
supported
uncertain
rejected
superseded
```

`supported`は真実の確定ではなく、現時点で判断材料として採用できることを表します。

### 関係

- Sense `*..*` Observation
- Sense `*..*` Knowledge
- Sense `*..*` Improvement
- Sense `0..*` AI Proposal

Observationとの関係には、支持根拠、反証根拠、参考情報などの役割を持たせます。

### 不変条件

- 少なくとも一つのObservationまたはKnowledgeを根拠に持つ。
- AI生成のSenseは、人が確認するまで正式採用しない。
- 否定されたSenseも削除せず、判断履歴として残す。
- 後続のResultによって支持・否定・置換できる。

## 3. Improvement

### 責務

会社が実現したい変化と、その変化を育てる文脈を保持します。

### 論理属性

```text
Improvement
  id
  public_id
  company_id
  title
  background
  current_state
  desired_state
  reason
  hypothesis
  expected_effect
  evaluation_method
  success_indicators
  scope_type
  scope_id
  priority
  urgency
  owner_user_id
  decision_due_at
  observation_window
  status
  created_by
  created_at
  updated_at
```

`scope_type`は将来、Company、部門、Workspace、顧客、Projectなどへ拡張できる概念です。初期実装の既存`Improvement`と即座に統合することは、この文書では決定しません。

### 状態

```text
discovered
exploring
ready_for_decision
decided
planned
executing
watching
on_hold
rejected
evaluating
learned
```

Company Improvementには、循環を断ち切る意味での最終`completed`を置きません。

`learned`は一つの改善サイクルで学びが得られた状態です。その後、継続、改訂、派生Improvement、追加観察へ進めます。

### 関係

- Improvement `*..*` Direction
- Improvement `*..*` Observation
- Improvement `*..*` Sense
- Improvement `1..*` Decision
- Improvement `*..*` Task
- Improvement `*..*` Project
- Improvement `*..*` Result
- Improvement `*..*` Knowledge
- Improvement `0..*` child Improvement

### 不変条件

- 解決手段ではなく、実現したい変化を記述する。
- 少なくとも一つのObservation、Sense、Direction、または明示的な作成理由を持つ。
- TaskやProjectの完了によって自動的に完了扱いにしない。
- Resultの評価と次のObservationへの接続を保持する。

## 4. Decision

### 責務

会社としての選択、責任、根拠を不変の判断記録として保持します。

### 論理属性

```text
Decision
  id
  public_id
  company_id
  subject_type
  subject_id
  question
  options
  outcome
  rationale
  conditions
  effective_from
  effective_until
  reconsider_on
  decided_by
  decided_at
  authority_context
  status
  supersedes_decision_id
  created_at
```

### 判断結果

```text
approved
rejected
deferred
observe_more
needs_revision
stopped
```

### 状態

```text
requested
under_consideration
decided
superseded
withdrawn
```

Decisionは決定後に内容を上書きしません。変更する場合は新しいDecisionを作り、`supersedes_decision_id`で置き換え関係を残します。

### 関係

- Decision `*..1` 判断対象
- Decision `*..*` Direction
- Decision `*..*` Observation
- Decision `*..*` Sense
- Decision `*..*` Knowledge
- Decision `0..*` AI Proposal
- Decision `0..*` Task / Project / Experiment

### 不変条件

- 決定者、決定時刻、結果、理由を持つ。
- AIを最終決定者にしない。
- 実行しない、保留する、追加観察する判断も保存する。
- 過去のDecisionを上書きせず、再判断を新しい記録として残す。

## 5. Project

### 責務

複数の人、期間、工程、成果物を必要とするImprovementの実行を管理します。

現行Projectモデルを活用できる可能性がありますが、既存Projectへの追加項目や移行方法は実装計画時に決定します。

### Core Modelから追加で必要になる論理属性

```text
Project
  existing_project_fields
  purpose
  success_conditions
  evaluation_method
  evaluation_window
  execution_status
  ended_at
  end_reason
```

元となるImprovementは一列の外部キーに限定せず、多対多を前提にします。

### 状態

```text
proposed
approved
planning
active
on_hold
ended
cancelled
```

Projectは`ended`または`cancelled`になります。Project終了後も、関連Improvementは`evaluating`へ進み、ResultとObservationを待ちます。

### 関係

- Project `*..*` Improvement
- Project `1..*` Decision
- Project `1..*` Task
- Project `0..*` Result
- Project `0..*` Observation
- Project `0..*` Knowledge

### 不変条件

- 少なくとも一つの目的またはImprovementとの関係を持つ。
- Project終了と改善評価を分離する。
- 終了時にResult記録またはResult記録予定を要求する。
- Project中に生まれたObservationをCompany OSへ戻せる。

## 6. Result

### 責務

実行によって実際に起きた事実と、期待との差を保持します。

### 論理属性

```text
Result
  id
  public_id
  company_id
  source_type
  source_id
  title
  expected_result
  actual_result
  measurement_method
  measured_from
  measured_to
  quantitative_data
  qualitative_data
  unexpected_effects
  evaluation
  evaluated_by
  evaluated_at
  status
  created_by
  created_at
  updated_at
```

### 評価

```text
effective
partially_effective
no_change
negative_effect
inconclusive
not_yet_measurable
```

### 状態

```text
expected
observed
recorded
under_evaluation
evaluated
```

Result自体には終端状態を設けません。`evaluated`後も、新しい測定、再評価、Observationが追加される可能性があります。

### 関係

- Result `*..1` Task / Project / Experiment / Decision
- Result `*..*` Improvement
- Result `1..*` Observation
- Result `0..*` Knowledge
- Result `0..*` Result（再測定、比較、派生）

### 不変条件

- 期待と実際を分ける。
- 変化がなかったこともResultとして扱う。
- 評価不能や観察期間不足を失敗と同一視しない。
- 評価済みResultは、一つ以上のObservationを生成または既存Observationへ明示的に接続する。
- ResultからObservationへの接続が、Continuous Evolutionを成立させる。

## 7. Knowledge

### 責務

次の観察、意味付け、判断、実行に再利用できる会社の知識を保持します。

### 論理属性

```text
Knowledge
  id
  public_id
  company_id
  title
  summary
  content
  knowledge_type
  applicability
  limitations
  factual_basis
  confidence
  visibility
  owner_user_id
  reviewed_by
  reviewed_at
  valid_from
  valid_until
  version
  status
  supersedes_knowledge_id
  created_by
  created_at
  updated_at
```

### 状態

```text
captured
under_review
published
superseded
archived
```

### 関係

- Knowledge `*..*` Observation
- Knowledge `*..*` Sense
- Knowledge `*..*` Improvement
- Knowledge `*..*` Decision
- Knowledge `*..*` Result
- Knowledge `0..*` Document
- Knowledge `0..*` Knowledge

### 不変条件

- 単なる添付ファイルと区別する。
- 根拠、適用条件、制約を持つ。
- AI生成内容は、人の確認前に正式なKnowledgeとして公開しない。
- 改訂時に過去版と置換理由を追跡できる。
- 次のObservationやSenseから参照できる。

## Cross-cutting: AI Proposal

AI Proposalは各概念を横断する候補レイヤーです。

```text
AIProposal
  id
  public_id
  company_id
  proposal_type
  target_type
  target_id
  proposed_change
  rationale
  evidence
  confidence
  risks
  impact_scope
  generated_by
  generated_at
  reviewed_by
  reviewed_at
  review_status
  application_status
  applied_at
```

```text
review_status:
  pending
  approved
  rejected
  revision_requested
  expired

application_status:
  not_applied
  applying
  applied
  failed
  reverted
```

AI Proposalは承認されても、自動的に対象概念を書き換えません。承認後、対象AggregateへのCommandを発行し、反映結果をDomain Eventとして記録します。

## Relationship Model

Phase2-3で、Relationshipを第一級のドメイン概念として確定しました。

正本は`company-os-relationship-model.md`です。

主な決定:

- 所有、認可、業務整合性は専用FKまたは専用Relationを正本とする。
- 意味探索に使う関係をRelationship Modelで表現する。
- Relationshipは理由、根拠、確信度、強さ、時間、可視性、確認状態を持つ。
- Relationship TypeはRegistryで管理し、自由入力を認めない。
- AIが生成した意味関係は、人が確認するまで`proposed`とする。
- 矛盾する意味を削除せず、並存と判断履歴を許容する。
- `Result generates Observation`をContinuous Evolutionの必須関係とする。

## Lifecycle Events

主要な状態変更はDomain Eventとして過去形で記録できるようにします。

```text
ObservationRecorded
ObservationLinked
SenseProposed
SenseSupported
SenseRejected
ImprovementDiscovered
ImprovementPreparedForDecision
DecisionRequested
DecisionMade
ProjectStarted
ProjectEnded
TaskCompleted
ResultRecorded
ResultEvaluated
KnowledgeCaptured
KnowledgePublished
ObservationGeneratedFromResult
```

これらは現時点でイベントテーブルの採用を意味しません。監査ログ、通知、AI再評価、履歴表示など、イベントを利用するConsumerをPhase2-3以降で整理します。

## Continuous Evolution Invariant

Company OS全体で最も重要な整合性ルールです。

```text
Task completed
    ↓
Result recorded
    ↓
Observation generated or linked

Project ended
    ↓
Result recorded
    ↓
Observation generated or linked

Result evaluated
    ↓
Knowledge captured when reusable learning exists
    ↓
Observation remains available for the next cycle
```

Knowledgeが作られないResultはあり得ます。しかし、評価されたResultがObservationへ戻らず、循環から消えることは認めません。

## Decisions Handed to Later Phases

Phase2-3でRelationshipの意味モデルを確定しました。次の内容は物理モデル、Semantic Navigator、権限設計で確定します。

1. 各専用RelationとSemantic Relationshipを同期するDomain Eventの範囲。
2. 既存Project ImprovementとCompany Improvementを同一Aggregateへ統合するか。
3. TaskをCompany直下でも作成できるようにするか。
4. ResultからObservationを生成するトランザクションと再実行時の一意性。
5. Senseの正式なコード名称を`Sensemaking`と`Interpretation`のどちらにするか。
6. Directionのバージョンと、判断時点のDirectionをどう固定するか。
7. KnowledgeとDocumentの所有・公開範囲。
8. Relationship Domain Eventを監査ログとして永続化する範囲。
9. AI Proposalの既存Project向け実装とCompany向け提案の境界。
10. Company、Workspace、Projectをまたぐ権限と可視性。

この論理モデルとRelationship Modelを、Query Model、Semantic Navigator、物理設計の入力とします。
