# Company OS Query Model

## 文書の位置付け

- Phase: Phase2-4
- 状態: Query Model v1.0
- 上位規範: `company-os-core-model.md`
- 入力: `company-os-domain-model.md`、`company-os-relationship-model.md`
- 対象: Company OSが答えるべき問い、探索契約、Evidence、説明責任、権限
- 対象外: 画面、チャットUI、Graph可視化、SQL、検索エンジン、AIモデル選定

この文書は、Company OSが何を保存するかではなく、会社の進化について何を問い、どの根拠で答えられるべきかを定義します。

## Core Statement

> Company OSは、会社の進化について問い、その答えに至った意味の経路と根拠を説明できるOperating Systemです。

Conceptは問いに答える対象です。

Relationshipは問いに答えるための意味の経路です。

Evidenceは回答を会社の判断に使えるものにします。

AIは、許可されたConcept、Relationship、Evidenceから回答を組み立て、結論だけでなく根拠と不確実性を説明します。

```text
Question
    ↓
Authorized Query Plan
    ↓
Concept + Relationship Traversal
    ↓
Evidence Set
    ↓
Answer + Reasoning Path + Uncertainty
```

Semantic Navigatorは、このQuery Modelへ答えるために後から設計します。Company Evolution GraphはSemantic Navigatorが利用する内部表現候補であり、Graphを先に作って利用目的を後付けしません。

## Company Intelligence and Accountability

Company OSが目指す知能を`Company Intelligence`と呼びます。

Company Intelligenceは、データ量やAIの流暢さでは測りません。

> 根拠をたどり、反証を示し、なぜその答えになったかを説明し、必要なら「現時点では判断できない」と言える能力です。

Company OSは回答を返すだけでなく、説明責任を持つOperating Systemです。

```text
Question
    ↓
Semantic Navigator
    ↓
Relationship Traversal
    ↓
Evidence Organization
    ↓
Answer
    ↓
Accountability
```

`Accountability`は、回答とともに次を示せることを意味します。

- なぜその答えになったか。
- 直接根拠はいくつあるか。
- 反証はいくつあるか。
- 未確認のEvidenceはいくつあるか。
- どのRelationship Pathを使用したか。
- AIが推論した部分はどこか。
- 人が最終判断すべき部分はどこか。
- 判断に足りない場合、次に何を観察すべきか。

## Query Model Principles

### 1. 問いを起点にする

画面、テーブル、グラフから表示項目を考えるのではなく、利用者が何を知り、何を判断したいかから設計します。

### 2. 結論だけを返さない

すべての重要な回答は、次を説明できる必要があります。

- どのConceptを見たか。
- どのRelationshipをたどったか。
- どのEvidenceを根拠にしたか。
- どの期間を対象にしたか。
- 反証や別のSenseがあるか。
- 情報がいつ更新されたか。
- 何が不足しているか。

### 3. 事実・解釈・判断を分ける

```text
Fact
  Observation / Result

Interpretation
  Sense

Formal judgment
  Decision

AI suggestion
  AI Proposal
```

AIはこれらを一つの断定文へ混ぜません。

### 4. 答えられないことを答えられる

十分なEvidenceがない場合、Company OSは推測で埋めません。

```text
現時点では判断できません。
確認できたObservationは2件です。
前年との比較に必要なResultが不足しています。
このSenseには支持根拠と反証が同数あります。
```

根拠不足を示し、次に必要なObservationを提案することも正式な回答です。

### 5. 権限を回答生成の前に適用する

権限のない情報を取得してから文章だけ伏せる設計にはしません。

Query Planを作る段階で、Company、Workspace、Project、顧客、個人、機密区分による探索範囲を制限します。

### 6. AIは利用者の権限を継承する

AIに独立した閲覧権限を与えません。同じ質問でも、質問者によって使用できるConcept、Relationship、Evidenceと回答が変わります。

### 7. 「進化」を単一スコアにしない

「会社は進化したか」という問いを、根拠のない一つの点数へ縮約しません。

Direction、Observation、Improvement、Result、Knowledgeを複数の観点から比較し、前進、停滞、後退、未評価を分けて説明します。

### 8. 説明責任を回答の一部にする

Evidence数やConfidenceを回答後の補足表示として扱いません。回答が成立するための必須要素として扱います。

同じ結論でも、直接測定されたResultに基づく場合と、未確認のAI Proposalだけに基づく場合では意味が異なります。Company OSは、この違いを隠しません。

## Query Actors

### 経営者

会社全体の方向、変化、判断、結果、資源配分、学習を問い、次に何へ向き合うかを決めます。

### 管理者

改善循環が正しくつながっているか、判断待ち、根拠不足、評価漏れ、権限、情報品質を確認します。

### Project担当者

担当ProjectがどのImprovementから生まれ、何を実現し、どのResultを返すべきかを確認します。

### 一般社員

日々の気付きが会社のDirectionやImprovementへどうつながったか、自分が次に何を観察・実行できるかを確認します。

### 顧客

許可されたProjectについて、目的、現在地、判断、実施内容、Resultを確認します。社内限定のSense、原価、Knowledge、AI Proposalは参照しません。

### AI

AIは独立した意思決定者ではなく、利用者または許可されたPolicyのためにRelationshipを探索するQuery Actorです。回答とともに使用したPathを提示します。

## Answer Contract

重要なQueryの回答は、共通して次の構造を持ちます。

```text
Answer
  conclusion
  explanation
  answer_type
  scope
  time_range
  evidence_summary
  relationship_paths
  supporting_evidence
  contradicting_evidence
  unconfirmed_evidence
  evidence_counts
  confidence_level
  confidence_basis
  freshness
  unknowns
  next_observation
  ai_inference
  human_judgment_required
  generated_for
  generated_at
```

### `answer_type`

```text
fact
comparison
interpretation
evaluation
recommendation
insufficient_evidence
```

### `confidence_level`

```text
high
medium
low
undetermined
```

初期段階では、説明できない精密な数値をAIに生成させません。ConfidenceはEvidenceの量だけでなく、直接性、確認状態、鮮度、反証の有無から判断します。

星や段階表示をUIで使う場合も、内部では次の内訳を説明できる必要があります。

```text
confidence_basis
  direct_evidence_count
  supporting_evidence_count
  contradicting_evidence_count
  unconfirmed_evidence_count
  stale_evidence_count
  confirmed_path_count
  unresolved_sense_count
```

Evidence数だけからConfidenceを機械的に決めません。同じ情報の複製、同一出所の反復、質の異なるEvidenceを単純加算しないためです。

### `freshness`

回答に使った最新・最古のEvidence日時と、有効期限切れKnowledgeの有無を示します。

### `unknowns`

不足しているObservation、評価されていないResult、未確認Relationship、競合するSenseを明示します。

### `next_observation`

答えを確かめるために次に何を観察すべきかを示します。回答を次のObservationへ接続し、Continuous Evolutionを維持します。

## Query Definition

再利用する重要Queryは、自由文だけでなくQuery Definitionとして契約を持ちます。

```text
QueryDefinition
  key
  name
  intent
  allowed_actors
  required_scope
  input_parameters
  required_concept_types
  allowed_relationship_types
  traversal_direction
  max_depth
  required_evidence
  time_policy
  freshness_policy
  visibility_policy
  deterministic_metrics
  explanation_policy
  ai_inference_policy
  human_judgment_point
  insufficient_evidence_behavior
  version
  status
```

自由質問は、AIが既存Query Definitionへ分類するか、安全な一時Query Planを作成します。自由質問を理由にRelationship Type、探索深度、権限境界を無制限にしません。

すべてのQuery Definitionは、次の9項目へ答えられなければ公開しません。

1. 誰が質問するか。
2. 何を知りたいか。
3. どのConceptを探索するか。
4. どのRelationshipをたどるか。
5. どのEvidenceを根拠とするか。
6. どの反証を探索するか。
7. 根拠不足なら何を観察すべきか。
8. AIはどこまで推論できるか。
9. 人が最終判断すべき点は何か。

## Evidence Model

### Evidenceの種類

```text
direct_observation
measured_result
formal_decision
published_knowledge
confirmed_relationship
supported_sense
document_source
external_source
ai_proposal
```

### Evidenceの優先原則

一般的な優先順は次のとおりです。

```text
直接測定されたResult / 出所が明確なObservation
    ↓
正式なDecision
    ↓
確認済みRelationship / 公開Knowledge
    ↓
人が支持したSense
    ↓
AI Proposal / 未確認Relationship
```

ただし、上位だから常に正しいとは限りません。期限切れKnowledge、古いDecision、測定条件が異なるResultを自動的に優先しません。

### Evidence Path

回答根拠は、Nodeの一覧ではなく意味のPathとして保持します。

```text
EvidencePath
  start_node
  steps[]
    relationship_id
    relationship_type
    from_node
    to_node
    status
    confidence
  end_node
  path_purpose
  path_confidence
```

AIは回答の各主要主張について、少なくとも一つのEvidence Pathを示します。

## Canonical Query Set v1

### A. Observation Queries

#### Q-O1 最近、会社で何が変わったか

```text
Actors
  経営者 / 管理者 / 一般社員 / AI

Concepts
  Observation, Result, Direction, Project

Relationships
  generates, related_to, similar_to, advances, conflicts_with

Evidence
  発生日時、観察元、関連数値、Result、確認状態、比較期間

Answer
  変化を事実として列挙し、重要性の理由と未解釈のObservationを分ける。
```

AIは「重要な変化」を断定する前に、Directionとの関係、影響範囲、変化量、継続性を説明します。

#### Q-O2 同じObservationは過去にもあったか

```text
Concepts
  Observation, Sense, Result, Knowledge

Relationships
  similar_to, interprets, supports, contradicts, learned_from

Evidence
  類似点、相違点、発生時期、当時のSense、Result、Knowledge

Answer
  「同じ」と断定せず、類似点と違い、過去の対応結果を示す。
```

#### Q-O3 AIが見つけたObservationは何か

```text
Concepts
  Observation, AI Proposal

Relationships
  references, supports, related_to

Evidence
  検知元、検知ルールまたはAI根拠、人の確認状態、信頼度

Answer
  人が記録したObservationとAI検知候補を明確に分ける。
```

#### Q-O4 まだ意味付けされていないObservationは何か

```text
Concepts
  Observation, Sense

Relationships
  interprets

Evidence
  Observationの状態、経過時間、出所、重要度候補

Answer
  Senseへ接続されていないObservationと、確認すると進みそうな理由を示す。
```

### B. Sense Queries

#### Q-S1 このObservationは何を意味する可能性があるか

```text
Concepts
  Observation, Sense, Knowledge, Result

Relationships
  interprets, supports, contradicts, similar_to, informs

Evidence
  支持根拠、反証、類似事例、Knowledgeの有効期間

Answer
  複数のSenseを併記し、一つへ早期収束させない。
```

#### Q-S2 このSenseを支持・反証するものは何か

```text
Concepts
  Sense, Observation, Knowledge, Result

Relationships
  supports, contradicts, validates, invalidates

Evidence
  confirmed Relationship、測定条件、期間、Evidenceの出所

Answer
  支持と反証を同じレベルで表示し、採用時点のDecisionがあれば示す。
```

#### Q-S3 他にどのような解釈があるか

```text
Concepts
  Observation, Sense, AI Proposal

Relationships
  interprets, similar_to, contradicts

Evidence
  同じObservationを解釈する別Sense、提案者、確認状態

Answer
  採用済み、未確認、否定済みを分けて示す。
```

### C. Improvement Queries

#### Q-I1 このImprovementは何から生まれたか

```text
Concepts
  Improvement, Observation, Sense, Knowledge, AI Proposal

Relationships
  discovered_from, suggests, supports, references

Evidence
  起点Observation、採用されたSense、参照Knowledge、AI提案の確認履歴

Answer
  発見までのRelationship Pathを時系列と意味の両方で示す。
```

#### Q-I2 どのDirectionにつながっているか

```text
Concepts
  Improvement, Direction, Decision, Result

Relationships
  aligns_with, conflicts_with, advances, justified_by

Evidence
  Directionの有効版、関係理由、確認者、実際のResult

Answer
  意図上の整合と、Resultによる実際の前進を分ける。
```

#### Q-I3 このImprovementは今どこまで育っているか

```text
Concepts
  Improvement, Decision, Task, Project, Result, Knowledge, Observation

Relationships
  decides_on, authorizes, executes, produces, evaluates, learned_from, generates

Evidence
  現在状態、最新Decision、実行状況、Result、未評価事項

Answer
  完了率ではなく、発見・判断・実行・評価・学習のどこまでつながったかを示す。
```

#### Q-I4 今、確認すると前へ進むImprovementは何か

```text
Concepts
  Improvement, Decision, Observation, Sense, Project

Relationships
  discovered_from, decides_on, depends_on, requires_observation_of

Evidence
  判断待ち、依存関係、追加Observation、最終活動日時

Answer
  停滞を責めず、必要な確認、判断者、次の一歩を示す。
```

#### Q-I5 最も大きな影響を生んだImprovementは何か

```text
Concepts
  Improvement, Result, Direction, Project

Relationships
  evaluates, advances, executes, produces

Evidence
  複数Result、測定方法、対象期間、Directionへの影響、反作用

Answer
  単一指標で順位付けせず、財務、顧客、社員、業務、将来性など評価軸を明示する。
```

### D. Decision Queries

#### Q-D1 このDecisionは何を根拠にしたか

```text
Concepts
  Decision, Direction, Observation, Sense, Knowledge, Result, AI Proposal

Relationships
  decides_on, justified_by, supports, contradicts, references

Evidence
  決定時点で参照可能だった情報、選択肢、判断者、反証

Answer
  現在の情報ではなく、決定時点のEvidenceを優先して再現する。
```

#### Q-D2 AIはなぜこの提案をしたか

```text
Concepts
  AI Proposal, Observation, Sense, Improvement, Knowledge, Direction

Relationships
  references, supports, suggests, aligns_with

Evidence
  AIが使用したNode、Relationship Path、生成時刻、未確認情報

Answer
  AIの内部思考を推測せず、Company OS上で実際に使用した根拠だけを説明する。
```

#### Q-D3 過去のDecisionは今も有効か

```text
Concepts
  Decision, Direction, Observation, Result, Knowledge

Relationships
  supersedes, justified_by, conflicts_with, invalidates, generates

Evidence
  有効期間、置換Decision、前提変化、新しいObservation、Result

Answer
  有効、見直し候補、失効、判断不能を分ける。
```

### E. Project and Execution Queries

#### Q-P1 このProjectは何のために存在するか

```text
Concepts
  Project, Improvement, Direction, Decision

Relationships
  executes, aligns_with, authorizes, justified_by

Evidence
  元Improvement、開始Decision、成功条件、Direction

Answer
  納品物ではなく、実現する変化を中心に説明する。
```

#### Q-P2 Project終了後、会社に何が残ったか

```text
Concepts
  Project, Result, Observation, Knowledge, Improvement

Relationships
  produces, evaluates, generates, learned_from, advances

Evidence
  Result、次のObservation、公開Knowledge、Directionへの影響

Answer
  終了を終点にせず、Resultから次の循環までを示す。
```

#### Q-P3 このTaskはどのImprovementを前へ進めるか

```text
Concepts
  Task, Project, Improvement, Direction

Relationships
  executes, contains, contributes_to, aligns_with

Evidence
  Taskの目的、所属Project、元Improvement

Answer
  関係がない場合は、目的不明Taskとして確認を促す。
```

#### Q-P4 Projectが終了できても、まだ評価できないものは何か

```text
Concepts
  Project, Improvement, Result, Observation

Relationships
  executes, produces, evaluates, generates

Evidence
  評価期間、未測定指標、未記録Result、必要Observation

Answer
  Project終了とImprovement評価を明確に分ける。
```

### F. Result Queries

#### Q-R1 実行によって実際に何が変わったか

```text
Concepts
  Result, Improvement, Project, Task, Observation

Relationships
  produced_by（inverse表示）, evaluates, generates

Evidence
  期待結果、実際結果、測定条件、期間、想定外の影響

Answer
  effective、no_change、negative_effect、inconclusiveを区別する。
```

#### Q-R2 期待と結果はどこが違ったか

```text
Concepts
  Improvement, Result, Sense, Decision

Relationships
  evaluates, validates, invalidates, justified_by

Evidence
  事前仮説、成功指標、実測値、評価者

Answer
  成否だけでなく、どの仮説が支持・否定されたかを示す。
```

#### Q-R3 このResultから何を次に観察すべきか

```text
Concepts
  Result, Observation, Knowledge, Improvement

Relationships
  generates, learned_from, suggests

Evidence
  既に生成されたObservation、評価不足、想定外の影響

Answer
  Continuous Evolutionへ戻る具体的なObservation候補を示す。
```

### G. Knowledge Queries

#### Q-K1 今回のImprovementに使えるKnowledgeは何か

```text
Concepts
  Improvement, Knowledge, Observation, Sense, Result

Relationships
  informs, similar_to, learned_from, supports, contradicts

Evidence
  適用条件、制約、有効期間、元Result、類似点と相違点

Answer
  類似しているだけで適用可能と断定せず、適用条件を示す。
```

#### Q-K2 このKnowledgeは今も有効か

```text
Concepts
  Knowledge, Result, Observation, Decision, Knowledge

Relationships
  supersedes, validates, invalidates, contradicts, learned_from

Evidence
  有効期間、最終レビュー、新しい反証、置換Knowledge

Answer
  有効、条件付き、見直し候補、失効、判断不能を分ける。
```

#### Q-K3 過去の似た事例では何が起きたか

```text
Concepts
  Observation, Improvement, Project, Result, Knowledge

Relationships
  similar_to, discovered_from, executes, produces, learned_from

Evidence
  類似条件、相違条件、実行内容、Result、学び

Answer
  過去事例を成功事例として単純転用せず、差分を説明する。
```

#### Q-K4 このKnowledgeはどこから生まれたか

```text
Concepts
  Knowledge, Result, Observation, Decision, Project

Relationships
  learned_from, produces, generates, justified_by

Evidence
  元Result、元Project、作成者、確認者、版

Answer
  Knowledgeの由来をRelationship Pathで示す。
```

### H. Company Evolution Queries

#### Q-C1 会社は以前より進化しているか

```text
Actors
  経営者 / 管理者 / AI

Concepts
  Direction, Observation, Improvement, Decision, Result, Knowledge, Project

Relationships
  aligns_with, advances, evaluates, generates, learned_from

Evidence
  比較期間、Direction別Result、改善循環の接続数、未評価領域、負の影響

Answer
  前進、停滞、後退、未評価をDirectionごとに説明する。単一の進化点数は使わない。
```

#### Q-C2 今、会社で最も大きな変化は何か

```text
Concepts
  Observation, Result, Direction, Improvement

Relationships
  generates, advances, conflicts_with, suggests

Evidence
  変化量、影響範囲、継続期間、Directionとの関係、確認状態

Answer
  評価軸を明示し、財務的変化と非財務的変化を混同しない。
```

#### Q-C3 今止まっているのは何か

```text
Concepts
  Improvement, Decision, Project, Task, Observation, Result

Relationships
  depends_on, decides_on, requires_observation_of, executes, evaluates

Evidence
  最終活動、判断待ち、依存先、評価待ち、担当者

Answer
  「止まっている人」を責めず、循環のどこが未接続かを示す。
```

#### Q-C4 会社は何を学んだか

```text
Concepts
  Knowledge, Result, Improvement, Observation, Decision

Relationships
  learned_from, evaluates, generates, informs

Evidence
  新規・改訂Knowledge、元Result、利用された次のDecision

Answer
  作られたKnowledgeだけでなく、実際に次の判断へ使われたかを示す。
```

#### Q-C5 どのDirectionに改善が偏っているか

```text
Concepts
  Direction, Improvement, Project, Result

Relationships
  aligns_with, conflicts_with, advances, executes

Evidence
  Direction別Improvement、投入工数、Project、Result、未接続Improvement

Answer
  件数だけでなく、投入量とResultを分けて示す。
```

#### Q-C6 この変化の原因は何か

例として「粗利率が下がった原因は何か」のような因果を問うQueryです。

```text
Actors
  経営者 / 管理者 / 権限を持つ担当者 / AI

Intent
  観察された変化に影響した可能性のある要因を理解し、次の判断材料を得る。

Concepts
  Observation, Sense, Result, Knowledge, Decision, Improvement, Project

Relationships
  interprets, supports, contradicts, validates, invalidates, similar_to, justified_by

Evidence
  直接測定値、構成要素の変化、期間、比較条件、過去事例、支持根拠、反証

Contradiction
  別のSense、変化しなかった関連指標、測定条件の違い、未確認Relationshipを探索する。

AI inference
  原因候補の整理とEvidence Pathの比較まで行える。相関だけで因果を確定しない。

Human judgment
  どの原因仮説を採用し、追加観察、Experiment、Improvementへ進むかを人が決める。

Insufficient evidence
  原因を断定せず、不足する内訳、比較期間、顧客・現場Observationなどを次の観察として提示する。

Answer
  確認できた事実、原因候補、支持数、反証数、未確認数、Confidence、必要な次のObservationを分ける。
```

## Inference and Human Judgment by Query Category

Canonical Query Setの各Queryは、個別定義に加えて次のカテゴリ契約を継承します。

| Queryカテゴリ | AIが推論できる範囲 | 必ず探索する反証 | Evidence不足時のObservation | 人が最終判断する点 |
|---|---|---|---|---|
| Observation | 変化の要約、類似候補、重要性候補 | 比較条件、別期間、出所不明、未確認検知 | 追加測定、出所確認、継続観察 | 正式Observationとして扱うか |
| Sense | 複数解釈、支持・反証整理、過去類似 | 競合Sense、反対Result、古いKnowledge | 仮説を区別できる新しい観察 | どのSenseを判断材料に採用するか |
| Improvement | 由来、Direction整合、実行候補 | Direction競合、重複改善、負のResult | 対象範囲、期待効果、成功指標 | 何をImprovementとして育てるか |
| Decision | 決定時点の根拠再構成、選択肢比較 | 当時の反証、置換Decision、前提変化 | 判断に不足する事実と期限 | 会社として何を選び責任を持つか |
| Project / Task | 目的、元Improvement、未接続の検知 | 目的不明、依存未解決、評価設計不足 | 完了条件、評価期間、必要Result | 開始・変更・終了を承認するか |
| Result | 期待との差、影響候補、仮説検証 | 測定誤差、別要因、負の影響、期間不足 | 再測定、別指標、利用者の反応 | 効果をどう評価し次へ進むか |
| Knowledge | 類似事例、適用条件、有効性候補 | 期限切れ、反証Result、適用条件の差 | 現在条件での再検証 | 正式Knowledgeとして採用・改訂するか |
| Company Evolution | Direction別の前進・停滞・後退 | 未評価領域、負のResult、偏り、Evidence欠落 | 比較可能なResultと未観察領域 | 会社として次に何へ資源を向けるか |
| Causal | 原因候補とEvidence Pathの比較 | 相関、代替仮説、期間差、測定条件 | 仮説を分離する測定・Experiment | 原因仮説を採用し行動へ移すか |

AIが行うのは、許可されたEvidenceからの整理、比較、推論候補の提示です。正式な意味関係の確認、原因の確定、Decision、Knowledge公開は人が行います。

## Actor Query Matrix

| Query領域 | 経営者 | 管理者 | Project担当者 | 一般社員 | 顧客 | AI |
|---|---:|---:|---:|---:|---:|---:|
| Company全体の変化 | ○ | ○ | 権限範囲 | 公開範囲 | × | 質問者権限 |
| Directionと進化 | ○ | ○ | 関連範囲 | 公開範囲 | × | 質問者権限 |
| Observation | ○ | ○ | 関連範囲 | 許可範囲 | 公開Project | 質問者権限 |
| Sense・仮説 | ○ | ○ | 関連範囲 | 許可範囲 | 原則× | 質問者権限 |
| Improvement | ○ | ○ | 関連範囲 | 許可範囲 | 公開Project | 質問者権限 |
| Decision根拠 | ○ | ○ | 関連範囲 | 許可範囲 | 公開Decision | 質問者権限 |
| Project | ○ | ○ | 担当Project | 参加Project | 公開Project | 質問者権限 |
| Result | ○ | ○ | 関連範囲 | 許可範囲 | 公開Result | 質問者権限 |
| Knowledge | ○ | ○ | 関連範囲 | 公開範囲 | 原則× | 質問者権限 |
| AI Proposal | ○ | ○ | 関連範囲 | 許可範囲 | 原則× | 質問者権限 |

この表は初期原則です。最終的な許可は役割名ではなく、Company、Workspace、Project、Concept、Relationship、Visibilityの組み合わせで判定します。

## Query Execution Model

### 1. Understand

質問者、意図、対象、期間、比較軸を特定します。曖昧な場合、断定せず前提を明示します。

### 2. Authorize

質問者が参照できるCompany、Project、Concept、Relationship Type、Visibilityを確定します。

### 3. Plan

Query Definitionを選び、起点Node、許可Relationship、方向、最大深度、期間、必要Evidenceを定めます。

### 4. Retrieve

Operational ModelとSemantic Relationship Modelから、権限内のConceptとRelationshipを取得します。

### 5. Build Evidence Set

支持、反証、未確認、別解釈、鮮度、欠落を整理し、重複出所を除外したEvidence数を計算します。

### 6. Evaluate

決定的に計算できる部分と、解釈が必要な部分を分けます。

### 7. Explain

結論、なぜその答えになったか、Evidence Path、支持数、反証数、未確認数、Confidenceの根拠、AI推論の範囲、人が判断すべき点、次に必要なObservationを回答します。

### 8. Record

重要なQueryでは、質問者、Query Definition、使用したNodeとRelationship、回答時点を監査可能にします。AIの回答全文を常にKnowledgeへ昇格させることはしません。

## Deterministic and AI Responsibilities

### システムが決定的に計算するもの

- 件数、期間、状態、日付、金額、工数。
- Relationshipの存在と状態。
- NodeとRelationshipの可視性。
- Resultの測定値。
- 有効期限。
- Query Path。
- 未接続、未評価、未確認の判定。

### AIが支援するもの

- 自由質問をQuery Definitionへ対応付ける。
- 類似性候補を説明する。
- 複数Senseを整理する。
- Evidence Setを自然言語で要約する。
- 反証と不確実性を説明する。
- 次に必要なObservationを提案する。

AIに件数や金額を推測させません。計算結果をシステムが渡し、AIは意味を説明します。

## Query Safety Invariants

1. Queryは回答生成前に質問者の権限で探索範囲を制限する。
2. AIは質問者より広い権限を持たない。
3. 重要な主張にはEvidence Pathを付ける。
4. Observation、Sense、Decision、AI Proposalを回答内で区別する。
5. `proposed` Relationshipだけを根拠に正式な事実として断定しない。
6. 支持Evidenceだけでなく反証も探索する。
7. 期限切れKnowledgeと置換済みDecisionを現在の正本として扱わない。
8. 比較期間と評価軸を示さず「進化した」と断定しない。
9. 類似性を因果関係として説明しない。
10. Evidence不足時は`insufficient_evidence`を返せるようにする。
11. 回答の不足を次のObservation候補へ接続する。
12. 顧客向け回答から社内限定NodeやRelationshipを推測できないようにする。
13. Query Pathの最大深度、件数、時間範囲を制限する。
14. AIが新しく推定したRelationshipはAI Proposalとして分離する。
15. 回答をそのままDecisionやKnowledgeとして自動確定しない。
16. ConfidenceをEvidence件数だけで決めず、出所の独立性、直接性、鮮度、確認状態を評価する。
17. AIの推論と、ConceptまたはRelationshipとして確認済みの事実を分けて表示する。

## Query Events

Queryに関する主なDomain Event候補です。

```text
QueryRequested
QueryAuthorized
QueryPlanned
EvidenceCollected
ContradictingEvidenceFound
InsufficientEvidenceDetected
QueryAnswered
NextObservationSuggested
QueryAccessDenied
```

これらはEvent Sourcingの採用を意味しません。監査、改善、AI品質評価に必要な範囲を物理設計時に決定します。

## Success Criteria

Company OSのQuery Modelが成立している状態は、次のとおりです。

- 利用者ごとに、Company OSへ問いかけられる内容が定義されている。
- 主要な問いについて必要ConceptとRelationshipが分かる。
- 回答に必要なEvidenceと不足時の挙動が分かる。
- AIが結論だけでなくRelationship Pathを説明できる。
- すべての回答で「なぜそう答えたか」を説明できる。
- 支持、反証、未確認、古いEvidenceの内訳を示せる。
- AIの推論限界と人の最終判断点を示せる。
- 反証、別解釈、古いKnowledgeを隠さない。
- Project終了後のResultがObservationへ戻る問いを持つ。
- 「会社は進化したか」を単純な完了率や単一スコアで答えない。
- Query Modelから、次に必要なGraph、Read Model、権限、データが逆算できる。

## Next Phase: Semantic Navigator

次は、このQuery Modelに答えるための`Semantic Navigator`を設計します。

Semantic Navigatorは利用者が得る価値の名称です。Company Evolution Graphは、その内部でRelationshipを探索するための実装・表現候補です。

順序は次のとおりです。

```text
Question
    ↓
Query Contract
    ↓
Required Relationship Traversal
    ↓
Semantic Navigator
    ↓
Company Evolution Graph / Search Index
    ↓
Read Model / AI Context
    ↓
UI
```

利用者はGraphを見ること自体を目的にしません。会社を知り、意味をたどり、根拠を理解するためにNavigatorを使います。

> Semantic Navigatorは、Company OSが会社の進化について答え、その理由とEvidence Pathを説明するための意味探索エンジンです。
