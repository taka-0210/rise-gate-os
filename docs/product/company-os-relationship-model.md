# Company OS Relationship Model

## 文書の位置付け

- Phase: Phase2-3
- 状態: Relationship Model v1.0
- 上位規範: `company-os-core-model.md`
- 入力: `company-os-domain-model.md`
- 対象: 概念間の意味、由来、根拠、確信度、時間、責任
- 対象外: 画面、グラフ可視化、Laravel Migration、Graph Databaseの採用

この文書は、Company OSに存在する概念を「点」から「意味のネットワーク」へ変えるための設計です。

## Core Statement

> Company OSの本体は、保存されたデータの量ではなく、会社の中で意味を持って育つRelationshipです。

Observation、Sense、Improvement、Direction、Decision、Task、Project、Result、Knowledgeは、それぞれ単独では会社の文脈を十分に表せません。

```text
Observation
  問い合わせが減った
    ↓ supports
Sense
  検索順位低下の影響かもしれない
    ↑ supports
Knowledge
  前年も同じ時期に同様の現象があった
    ↓ suggests
Improvement
  検索流入と問い合わせ導線を改善する
    ↓ aligns_with
Direction
  新規相談を安定して増やす
```

Company OSは、この意味の経路と、その経路がなぜ作られ、誰が確認し、現在どの程度信頼できるかを保持します。

## Relationshipの定義

Relationshipは、二つのConcept Nodeの間にある意味を表す第一級のドメイン概念です。

```text
from Node
  ── relationship_type ──>
to Node
```

Relationshipは単なる中間テーブルではありません。

> 「AとBは関係がある」という、根拠、責任、確信度、時間を持った会社の意味主張です。

そのため、作成、確認、異議、置換、撤回というライフサイクルを持ちます。

## Node

Relationshipで接続できるCore Nodeは次のとおりです。

```text
Direction
Observation
Sense
Improvement
Decision
Task
Project
Result
Knowledge
AI Proposal
```

将来はExperiment、Document、Metric、Customer、Person、Team、External Eventなどを追加できます。ただし、Node Typeを無制限な文字列として増やしません。意味、所有境界、権限、許可されるRelationshipを定義してからRegistryへ追加します。

## Relationship as Semantic Assertion

Relationshipは事実そのものではなく、意味についての主張である場合があります。

例:

```text
Observation supports Sense
```

この関係は、次のように変化する可能性があります。

```text
AIが候補を提案した
    ↓
担当者が確認した
    ↓
別のObservationによって異議が生まれた
    ↓
新しいSenseに置き換えられた
```

したがって、Relationshipには現在の状態だけでなく、提案者、確認者、根拠、反証、置換元を保持します。

構造上明らかな関係と意味上の関係は区別します。

```text
structural
  Project contains Task

semantic
  Observation supports Sense
  Improvement aligns_with Direction
```

構造関係はシステムが確定できます。意味関係は、原則として人による確認前は`proposed`です。

## Direction Convention

すべてのRelationship Typeは、`from → relation → to`で自然な一文として読めるようにします。

```text
Observation supports Sense
Sense suggests Improvement
Improvement aligns_with Direction
Decision authorizes Project
Project executes Improvement
Result evaluates Improvement
Result generates Observation
Knowledge informs Decision
```

逆向きの名称を同時に保存しません。

例えば`Observation supports Sense`を保存した場合、`Sense supported_by Observation`を別Relationshipとして重複保存しません。逆方向表示はRelationship Type Registryの`inverse_label`から生成します。

## Relationship Categories

### 1. Evidence and Meaning

観察、解釈、知識の根拠関係です。

| Relationship Type | From | To | 意味 |
|---|---|---|---|
| `interprets` | Sense | Observation | Observationをこのように意味付けする |
| `supports` | Observation / Knowledge / Result | Sense | Senseを支持する根拠になる |
| `contradicts` | Observation / Knowledge / Result | Sense | Senseに反する根拠になる |
| `similar_to` | 同種または比較可能なNode | Node | 過去事例や傾向が類似する |
| `references` | Node | Node | 判断材料として参照する |

`supports`は因果関係を証明しません。ある解釈を支持する根拠であることだけを表します。

### 2. Discovery and Intent

意味付けから改善が生まれ、会社の方向へ接続する関係です。

| Relationship Type | From | To | 意味 |
|---|---|---|---|
| `suggests` | Sense / Knowledge / AI Proposal | Improvement | 改善候補を示唆する |
| `discovered_from` | Improvement | Observation / Sense | 改善がどこから発見されたか |
| `aligns_with` | Improvement / Project | Direction | Directionの実現に沿う |
| `conflicts_with` | Improvement / Project / Decision | Direction | Directionと競合または矛盾する |
| `advances` | Improvement / Result | Direction | Directionを実際に前進させる |
| `derived_from` | Improvement | Improvement | 元の改善から派生した |

`aligns_with`は実行前の意図、`advances`はResultを踏まえた実際の前進を表します。

### 3. Decision and Authority

会社としての判断と実行権限の関係です。

| Relationship Type | From | To | 意味 |
|---|---|---|---|
| `decides_on` | Decision | Improvement / Project / Direction / Knowledge | 何について判断したか |
| `justified_by` | Decision | Direction / Observation / Sense / Knowledge / Result | 判断根拠 |
| `authorizes` | Decision | Task / Project / Experiment | 実行を正式に認める |
| `requires_observation_of` | Decision | Observation対象 | 追加観察を求める |
| `supersedes` | Decision / Direction / Knowledge / Sense | 同種Node | 過去の判断や版を置き換える |

却下、保留、追加観察などの判断結果はDecisionの`outcome`です。`rejects`や`defers`を別Relationship Typeとして重複表現しません。

### 4. Execution

改善をTask、Project、Experimentで実現する関係です。

| Relationship Type | From | To | 意味 |
|---|---|---|---|
| `executes` | Task / Project / Experiment | Improvement | 改善を実行する |
| `contains` | Project | Task / Experiment | Project内の実行要素 |
| `depends_on` | Task / Project / Improvement | 同種または先行Node | 前提となる実行や改善 |
| `contributes_to` | Task / Project / Improvement | Improvement / Project | 一部として貢献する |
| `produces` | Task / Project / Experiment | Result | 実行によってResultを生む |

`executes`は改善との意味関係、`contains`はProject内部の構造関係です。

### 5. Outcome and Learning

実行を評価し、知識と次の観察へ戻す関係です。

| Relationship Type | From | To | 意味 |
|---|---|---|---|
| `produced_by` | Result | Task / Project / Experiment | Resultの発生元を示す逆向き用途 |
| `evaluates` | Result | Improvement / Project / Task | 何の効果を評価するResultか |
| `validates` | Result | Sense / Knowledge | 仮説や知識の妥当性を支持する |
| `invalidates` | Result | Sense / Knowledge | 仮説や知識の妥当性を否定する |
| `learned_from` | Knowledge | Result / Observation / Decision / Project | Knowledgeの由来 |
| `generates` | Result | Observation | Resultから次のObservationを生む |
| `informs` | Knowledge | Observation / Sense / Improvement / Decision | 次の理解や判断に利用する |

`produces`と`produced_by`は意味が重複するため、物理保存では`produces`を正規方向とします。`produced_by`は表示用の逆ラベルです。

`Result generates Observation`はContinuous Evolutionを成立させる必須関係です。

### 6. Graph Utility

| Relationship Type | From | To | 意味 |
|---|---|---|---|
| `related_to` | Node | Node | 関係は認識しているが意味が未整理 |

`related_to`は仮置きです。AIの重要判断や正式な自動処理の根拠には使用しません。Senseの整理時に、より具体的なRelationship Typeへ置き換えることを目指します。

## Canonical Relationship Types v1

物理保存する正規Relationship Typeの初期セットです。

```text
interprets
supports
contradicts
similar_to
references
suggests
discovered_from
aligns_with
conflicts_with
advances
derived_from
decides_on
justified_by
authorizes
requires_observation_of
supersedes
executes
contains
depends_on
contributes_to
produces
evaluates
validates
invalidates
learned_from
generates
informs
related_to
```

自由入力の`relation_type`は認めません。新しいTypeは、意味、方向、許可Node、逆表示、推移性、対称性、AI利用可否を定義してRegistryへ追加します。

## Relationship Type Registry

各Relationship Typeには次の定義が必要です。

```text
key
label
inverse_label
category
description
allowed_from_types
allowed_to_types
is_symmetric
is_transitive
requires_human_confirmation
can_be_ai_proposed
can_drive_automation
is_active
version
```

初期方針では、次のTypeだけを対称関係とします。

```text
similar_to
related_to
```

`supports`、`aligns_with`、`depends_on`などを推移的と自動判断しません。推移可能に見えても、会社の意味は文脈に依存するためです。

## Logical Data Structure

### Relationship

```text
Relationship
  id
  public_id
  company_id

  relation_type
  from_type
  from_id
  to_type
  to_id

  reason
  context
  confidence
  strength
  relevance

  valid_from
  valid_until

  origin_type
  origin_id
  creation_method

  status
  reviewed_by
  reviewed_at
  review_note

  visibility
  supersedes_relationship_id

  created_by
  created_at
  updated_at
```

### 属性の意味

#### `reason`

なぜこの関係があると考えたかを、人が読める言葉で記録します。

#### `context`

どの状況、期間、会社、顧客、Projectで成立する関係かを記録します。同じ二つのNodeでも、状況によって関係が異なる可能性があります。

#### `confidence`

関係が妥当である確信度です。事実性や根拠の十分さを表します。

#### `strength`

関係の影響の強さです。確信度とは別です。

```text
確信度は高いが影響は弱い
確信度は低いが、成立すれば影響は大きい
```

#### `relevance`

現在の目的や探索に対する関連度です。原則として検索時に計算しますが、人が明示した重要度を保持できる余地を残します。

#### `origin_type` / `origin_id`

このRelationshipがどこから生まれたかを示します。

```text
human_statement
ai_proposal
system_rule
import
domain_event
```

#### `valid_from` / `valid_until`

関係が意味を持つ期間です。作成日時とは異なります。

「昨年度は正しかったが、現在は成立しない」という関係を削除せず表現します。

### Relationship Evidence

Relationshipの理由を本文だけに閉じず、根拠となるNodeを関連付けます。

```text
RelationshipEvidence
  relationship_id
  evidence_type
  evidence_id
  evidence_role
  note
  added_by
  added_at
```

```text
evidence_role:
  support
  contradiction
  context
  source
```

一つのRelationshipに複数の根拠と反証を持てます。

## Relationship Lifecycle

```text
proposed
    ↓
under_review
    ├ confirmed
    ├ disputed
    ├ rejected
    └ withdrawn

confirmed
    ├ disputed
    ├ superseded
    └ retracted
```

### `proposed`

人またはAIが関係候補を提示した状態です。

### `confirmed`

権限を持つ人、または明示的に許可された構造ルールによって確認された状態です。

### `disputed`

反証や異なる解釈があり、現在の判断にそのまま使えない状態です。

### `rejected`

提案された関係を正式に採用しなかった状態です。

### `superseded`

新しいRelationshipによって意味が置き換えられた状態です。

### `retracted`

確認後に、誤りや不適切な根拠が判明して撤回した状態です。

確認済みRelationshipを直接書き換えて意味を変えません。変更時は新しいRelationshipを作成し、`supersedes_relationship_id`でつなぎます。

## Human and AI Authority

### AIができること

- 類似Nodeを発見する。
- Relationship候補を`proposed`で作る。
- Relationshipの理由、根拠、反証候補を示す。
- 古くなった可能性があるRelationshipを提示する。
- 複数のNodeをつなぐ経路を説明する。
- 既存Relationship Typeへの分類候補を提示する。

### AIができないこと

- 意味関係を無断で`confirmed`にする。
- `confidence`を会社の正式評価として確定する。
- 反証Relationshipを削除する。
- 人のDecisionをRelationshipから自動生成して確定する。
- 権限のないNodeを経由して文脈を取得する。

構造関係は例外です。例えばProject内でTaskが作成された場合、システムは`Project contains Task`をルールに基づいて確認済みで生成できます。

## Contradiction and Multiple Meanings

Company OSは、矛盾するRelationshipが同時に存在することを許容します。

```text
Observation A supports Sense X
Observation B contradicts Sense X
Knowledge C supports Sense X
Result D invalidates Sense X
```

どれか一つを消して単純化しません。

会社の判断では、何が支持し、何が反証し、どの時点でどのSenseを採用したかが重要です。

また、一つのObservationから複数のSenseが存在できます。

```text
Observation
  問い合わせが減った
    ├→ Sense A: 検索順位低下の影響
    ├→ Sense B: 季節変動
    └→ Sense C: 広告停止の影響
```

複数の意味を無理に一つへ統合せず、Decision時点で採用した解釈を記録します。

## Structural Source of Truth and Semantic Graph

Company OSでは、既存の業務上の外部キーや専用Relationを廃止しません。

```text
Operational Model
  Project owns Task
  Project has Member
  Result belongs to source execution

Semantic Relationship Model
  Project executes Improvement
  Result evaluates Improvement
  Knowledge learned_from Result
```

方針は次のとおりです。

1. 所有、権限、整合性に関わる関係は専用FKまたは専用Relationを正本にする。
2. 意味探索に使う関係はRelationship Modelへ表現する。
3. 同じ意味を二つの正本で個別更新しない。
4. 構造関係からSemantic Relationshipが必要な場合はDomain Eventで同期する。
5. Graphを理由に既存のテナント境界や認可を迂回しない。

## Company Boundary and Visibility

Relationshipは両端のNodeより広い権限を持てません。

```text
relationship_visibility
  ≤ from_node_visibility
  ≤ to_node_visibility
```

実際には、両端とRelationshipのうち最も厳しい公開範囲を適用します。

- CompanyをまたぐRelationshipは初期段階では作成しない。
- Project限定情報をCompany全員へ自動公開しない。
- 顧客公開Nodeと社内限定NodeのRelationshipから、社内情報を推測できないようにする。
- AIは現在の利用者が参照できるNodeとRelationshipだけを探索する。
- 削除・無効化されたNodeへつながるRelationshipは探索対象から除外し、監査履歴には残す。

## Graph Traversal for AI

AIは全文検索だけでなく、意味の経路をたどって文脈を集めます。

```text
1. 起点Nodeを決める
2. 許可されたRelationship Typeだけをたどる
3. confirmedを優先し、proposedは候補として区別する
4. visibilityとCompany境界を確認する
5. valid期間とconfidenceを確認する
6. 探索深度と件数を制限する
7. 使用したRelationship Pathを回答根拠として示す
```

例:

```text
現在のObservation
  ↓ similar_to
前年のObservation
  ↓ interpreted_by（inverse表示）
過去のSense
  ↑ supports
Knowledge
  ↓ informs
過去のDecision
  ↓ authorizes
Project
  ↓ produces
Result
```

AIは、この経路を根拠として次のように支援できます。

- 去年のObservationと似ています。
- 当時はこのKnowledgeを判断材料にしました。
- このImprovementにつながる可能性があります。
- 過去のResultでは効果が限定的でした。

AIが新しく推定した経路はAI Proposalであり、既存Relationshipと区別します。

## Relationship Invariants

1. Relationshipは必ず同じCompany境界内の有効な二つのNodeを参照する。
2. `from → relation_type → to`が一文として読める方向で保存する。
3. 逆方向Relationshipを重複保存しない。
4. Relationship Type RegistryにないTypeを作成しない。
5. Typeごとに許可されたNodeの組み合わせを守る。
6. AIが生成した意味関係は、人が確認するまで`proposed`とする。
7. 確認済みRelationshipの意味を直接上書きしない。
8. 矛盾するRelationshipを削除せず、並存と判断履歴を許容する。
9. `confidence`と`strength`を混同しない。
10. 関係が成立する期間と作成日時を分離する。
11. Relationshipは両端のNodeより広い可視性を持たない。
12. `related_to`を正式判断や自動実行の唯一の根拠にしない。
13. `Result generates Observation`をContinuous Evolutionの必須経路とする。
14. 所有、認可、参照整合性はSemantic Graphだけに依存しない。
15. AIが判断に使用したRelationship Pathを追跡できるようにする。

## Event Storming

Relationshipに関する主なCommandとDomain Eventです。

```text
Command
  Relationshipを提案する
  Relationshipを確認する
  Relationshipへ異議を付ける
  Relationshipを却下する
  Relationshipを置き換える
  Relationshipを撤回する
  根拠を追加する
  反証を追加する

Domain Event
  RelationshipProposed
  RelationshipConfirmed
  RelationshipDisputed
  RelationshipRejected
  RelationshipSuperseded
  RelationshipRetracted
  RelationshipEvidenceAdded
  RelationshipContradictionAdded
```

Policy候補:

```text
ResultEvaluated
  → Result generates Observationを要求する

ProjectEnded
  → Result記録を要求する

SimilarObservationDetected
  → similar_to Relationshipをproposedで作成する

KnowledgePublished
  → 関連するSenseとDecisionへのinforms候補を提示する

RelationshipDisputed
  → そのRelationshipを根拠にした未決定AI Proposalを再評価する
```

## Implementation Direction

Phase2-3では思想と論理構造だけを確定します。

現時点では、次を採用しません。

- Graph Databaseありきの設計
- Event Sourcingありきの設計
- すべてを一つの汎用Edgeテーブルだけで管理する設計
- AIによる自動確定
- グラフを直接編集するUI

最初の物理実装候補は、Laravelと既存RDBを維持した次の構成です。

```text
専用FK・専用Relation
  所有、認可、業務整合性の正本

relationships
  意味のEdge

relationship_evidence
  Edgeの根拠と反証

relationship_type_registry
  Typeの契約
```

これはPhase2-4以降の検証対象であり、この文書だけでMigration作成を承認するものではありません。

## Next Phase Input: Company OS Query Model and Semantic Navigator

Relationship Modelの次に、Company OSが答えるべき問いをQuery Modelとして設計します。Semantic Navigatorは、そのQuery Modelに必要な意味探索と説明責任を実現するために設計します。Company Evolution GraphはNavigatorの内部表現候補です。

```text
Concept Node = 星
Relationship = 重力
意味のまとまり = 星座
会社全体の進化 = 星雲
```

Navigator設計では見た目から始めず、Query Modelを入力として次を決めます。

- どのNodeをCompany Evolution Graphへ含めるか。
- どのRelationshipを探索可能にするか。
- 時間による星雲の変化をどう表現するか。
- 人の確定関係とAI推定関係をどう分けるか。
- 一つのImprovementを中心にどの範囲まで展開するか。
- Company、Project、個人、顧客の可視性をどう守るか。
- AIがRelationship Pathをどのように説明するか。

Company Evolution Graphは飾りの可視化ではなく、Semantic Navigatorが利用する探索構造です。

> 会社の中にある意味のつながりを、人とAIが理解し、育て、次の進化へ使うためのCompany OSの意味モデルです。
