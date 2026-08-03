# Company OS Phase3 MVP

## 文書の位置付け

- Phase: Product Phase3
- 状態: MVP Direction v1.0
- 上位規範: Core Model、Domain Model、Relationship Model、Query Model
- テーマ: Observation First / Company Dialogue
- 対象: 最初に検証する利用体験、Observation Source、導入方法、実装順序
- 対象外: 全自動API連携、完全なSemantic Navigator、Graph可視化、全社機能の実装

思想設計を一度区切り、Company OSが実際に使われる最小の循環を定義します。

## MVP Statement

> Company OSは、毎日開かせるシステムではありません。会社に変化があったとき、自然に開きたくなるOperating Systemです。

会社によって変化の頻度と情報の形は異なります。

- 基幹システムで毎日大量のデータが更新される会社。
- 月次のExcelを中心に判断する会社。
- 紙の記録が中心の会社。
- 会議や現場の気付きから改善が始まる会社。
- Projectや顧客との対話から変化が生まれる会社。

Company OSは、毎日のデータ更新、API、自動分析を導入条件にしません。

変化がない日に、利用を促すためだけの通知や問いを生成しません。

## Observation First

Company OSの入口はObservationです。

```text
会社に変化が起きる
    ↓
Observationが生まれる
    ↓
Company Dialogueが始まる
    ↓
人が意味を与える
    ↓
Sense
    ↓
Improvement
    ↓
Decision
    ↓
Execution
    ↓
Result
    ↓
Observation
```

Company Dialogueは毎朝決まった時刻に始まるものではありません。

次のいずれかが起きたときに始まります。

- 人がObservationを記録した。
- AIがObservation候補を提示し、人が確認した。
- CSVや文書から変化候補が抽出された。
- 外部システムから変化が検知された。
- Resultが評価され、次のObservationが生成された。
- 経過観察中のObservationが再確認時期を迎えた。

## Engagement Principle

MVPの成功を日次ログイン率だけで判断しません。

重要なのは次です。

- 変化が起きたとき、会社の人がCompany OSを思い出せる。
- Observationを負担なく記録できる。
- 記録したObservationが放置されず、意味付けや改善へつながる。
- 実行後のResultが次のObservationへ戻る。
- 必要のない日には静かでいられる。

```text
Bad
  毎日ログインさせる
  毎日AIメッセージを生成する
  更新がなくても通知する

Good
  変化が生まれたときに入口をつくる
  判断が必要なときに理由付きで問いを返す
  経過観察の時期にだけ再確認する
```

## Observation Source

Observation Sourceは、会社の変化がどこから認識されるかを表します。

### Human Sources

- 経営者
- 社員
- 顧客
- 外部パートナー
- 会議
- 面談
- 電話
- 現場での気付き

### System Sources

- 基幹システム
- 会計システム
- 厨房君などの業務システム
- Google Analytics
- Google Search Console
- CRM
- POS
- 勤怠システム
- CSVインポート
- その他の外部システム

### Intelligence and Event Sources

- AIによる検知
- 文書や議事録からの抽出
- 定期レビュー
- 法改正
- 市場変化
- 契約更新
- 決算・月次締め
- Project終了
- Result評価

Observation SourceはObservationそのものではありません。

```text
Observation Source
  どこを観察するか

Observation
  実際に何が観察されたか
```

## Source Maturity

Company OSは次の3段階でObservation Sourceを育てます。

### Stage 1: Human Observation

人がObservationを登録します。

```text
気付いた
    ↓
Company OSへ記録
    ↓
Company Dialogue
```

MVPはこの段階だけで成立させます。

### Stage 2: AI-assisted Observation

人が投入したCSV、文書、議事録、帳票などをAIが読み、Observation候補を生成します。

```text
CSV / Document
    ↓
AIが変化候補を抽出
    ↓
Observation Candidate
    ↓
人が確認
    ↓
Observation
```

AIが抽出した時点では正式なObservationにしません。

### Stage 3: Connected Observation

外部システムとAPI連携し、設定された条件に応じてObservation候補を自動生成します。

```text
External System
    ↓ API / Webhook / Scheduled Import
Observation Candidate
    ↓ Confirmation Policy
Observation
```

API連携は便利機能です。Company OSの必須条件ではありません。

## MVP Source Scope

MVPで正式対応するObservation Sourceは`human`です。

```text
Human Observation
  経営者が登録する
  社員が登録する
  会議で得た気付きを人が登録する
  顧客の声を担当者が登録する
```

入力時には、将来のSource拡張を妨げない最低限の出所情報を持ちます。

```text
source_category
  human

source_type
  executive
  employee
  customer
  partner
  meeting
  other

source_name
observed_by
occurred_at
observed_at
source_note
```

人が顧客の声を代理登録した場合、観察者と情報源を分けます。

```text
observer
  担当社員

source
  顧客
```

## Observation Design

Observation Designは、Company OS導入前に「その会社では、どこから、どのように変化を捉えるか」を設計する活動です。

> Company OSは、システムを接続する前に、会社の観察方法を設計します。

### Observation Designで決めること

```text
1. Direction
   会社は何を目指しているか

2. Observation Area
   どの領域の変化を見るか

3. Source
   誰、どのシステム、どのイベントから生まれるか

4. Signal
   何が起きたらObservationと考えるか

5. Evidence
   何を根拠として添えるか

6. Observer
   誰が観察・確認するか

7. Timing
   イベント時、随時、定期のどれか

8. Dialogue Policy
   どのObservationにCompany OSが問いを返すか

9. Visibility
   誰が見られるか

10. Follow-up
    判断できない場合、何を追加観察するか
```

### Observation Design Sheet

会社ごとに次の論理情報を整理します。

```text
ObservationSourceDefinition
  name
  purpose
  related_directions
  source_category
  source_type
  source_owner
  observation_area
  expected_signal
  evidence_requirement
  timing_type
  timing_rule
  capture_method
  confirmation_policy
  dialogue_policy
  visibility
  sensitivity
  active_from
  active_until
  review_cycle
```

MVPでは、この定義をすべてシステム設定として実装する必要はありません。導入支援時の設計資料として開始し、運用で繰り返し使われる項目から段階的にシステムへ取り込みます。

## Partner Value

代理店・導入支援会社の最初の仕事はAPI開発ではありません。

```text
会社を理解する
    ↓
Directionを確認する
    ↓
変化が生まれる場所を見つける
    ↓
Observation Sourceを設計する
    ↓
人による観察運用を始める
    ↓
必要な場所だけCSV・AI・APIへ育てる
```

対外的には、次のように説明できます。

> Company OSは、システムを導入する前に「会社の観察設計」から始めます。まず会社の変化をどう観察するかを一緒に設計し、必要に応じてAIや既存システムとの連携へ育てます。

これにより、APIがない会社、紙中心の会社、Excel中心の会社でも導入できます。

## Company Dialogue

Company Dialogueは、Observationを意味と行動へつなぐ対話です。

AIチャットを開くこと自体ではありません。

### Dialogue Trigger

MVPでは、人がObservationを登録した直後にDialogueを開始します。

```text
Observation recorded
    ↓
Company OS
  「この変化は、会社にとってどのような意味がありますか？」
    ↓
Human
  Senseを記録 / まだ分からない
```

### Why This Question

すべての問いは、なぜ今聞くのかを説明します。

```text
Question
  このObservationを改善として育てますか？

Why now?
  あなたが重要な変化として確認しました。
  関連するDirectionがあります。
  まだ既存Improvementへ接続されていません。
```

### Human Responses

MVPでは、次の回答を必ず許可します。

```text
意味を記録する
既存Senseへつなぐ
Improvementとして育てる
既存Improvementへつなぐ
経過観察する
今は扱わない
まだ判断できない
```

「まだ判断できない」は失敗ではありません。次に必要なObservationを残す入口です。

### Silence Is Valid

Observationがない、判断期限が来ていない、再観察の必要がない場合、Company OSは問いを生成しません。

静かな状態は利用不足ではなく、現在対応すべき変化がない状態として扱います。

## MVP Vertical Slice

最初のMVPでは、Company OS全体を実装しません。次の一本の循環を成立させます。

```text
1. 人がObservationを記録する
        ↓
2. Company OSが理由付きの問いを返す
        ↓
3. 人がSenseを記録する、または判断不能を選ぶ
        ↓
4. Improvementを作成または既存Improvementへ接続する
        ↓
5. 人がDecisionを記録する
        ↓
6. 既存Task / Projectで実行する
        ↓
7. Resultを記録・評価する
        ↓
8. ResultからObservationを生成する
```

## MVP Capabilities

### 必須

- 人によるObservation登録。
- 事実と解釈を分けた入力。
- Observation Sourceの記録。
- Observationを起点にしたCompany Dialogue。
- 問いの`Why now?`表示。
- Senseの作成・接続。
- Improvementの作成・接続。
- 人によるDecisionの記録。
- 既存Task／Projectへの接続。
- Resultの記録と評価。
- Resultから次のObservationを生成する循環。
- 各段階のRelationshipと由来の記録。
- 「まだ判断できない」と次のObservation。

### MVP後

- CSVアップロードからのObservation候補。
- 文書・議事録からのObservation候補。
- 外部APIとWebhook。
- 自動的な異常検知。
- 高度なSemantic Navigator。
- Company Evolution Graphの可視化。
- 複数AIによる仮説比較。
- 代理店向けObservation Design設定画面。

## Minimal Relationship Set

MVPではRelationship Type v1のすべてを実装せず、循環に必要な最小セットから始めます。

```text
Sense interprets Observation
Improvement discovered_from Observation / Sense
Improvement aligns_with Direction
Decision decides_on Improvement
Decision authorizes Task / Project
Task / Project executes Improvement
Task / Project produces Result
Result evaluates Improvement
Knowledge learned_from Result
Result generates Observation
```

KnowledgeをMVPの必須入力画面にする必要はありませんが、Resultから学びを残せる余地とRelationshipは保持します。

## Notification Policy

MVPで日次通知を前提にしません。

通知候補は次に限定します。

- 新しいObservationが自分の確認を待っている。
- 自分のDecisionが必要である。
- 経過観察の再確認日になった。
- Project終了後のResultが未評価である。
- Resultから生成されたObservation候補が確認を待っている。

すべての通知に次を必要とします。

```text
why_now
required_action
source
importance
silence_or_snooze_option
```

## MVP Success Criteria

### Core Success

- 人だけをObservation Sourceとして循環を開始できる。
- APIがなくてもCompany OSが成立する。
- ObservationとSenseが分離されている。
- 人が意味とDecisionを与える。
- Existing Project Managementを実行手段として利用できる。
- Project終了を改善完了と扱わない。
- Resultが次のObservationへ戻る。
- Relationshipによって由来を追跡できる。

### Experience Success

- 変化が起きたとき、どこへ記録すればよいか分かる。
- 問いがなぜ表示されたか分かる。
- 判断不能を安心して選べる。
- 次に何を観察すべきか分かる。
- 更新がない日に不要な操作を要求されない。
- Observationが改善と実行へつながった実感がある。

### Learning Success

MVP運用を通じて次を学べることを成功とします。

- 人はどのような変化をObservationとして登録するか。
- 事実と解釈を自然に分けられるか。
- どの問いが意味付けを助けるか。
- どこで判断が止まるか。
- どのObservation Sourceを次にCSV・AI・APIへ育てるべきか。
- Resultから次のObservationが本当に生まれるか。

## Non-goals

MVPの目的ではないもの:

- 毎日ログインさせる。
- 日次利用率を最大化する。
- AIに毎日話しかけさせる。
- すべての会社データを自動収集する。
- APIがなければ利用できない設計にする。
- あらゆる原因をAIが自動判断する。
- Company OS全体を一度に完成させる。
- Graphを見せること自体を価値にする。

## Implementation Order

実装は次の順序で一つの縦の循環を育てます。

```text
Slice 1
  Manual Observation

Slice 2
  Observation-triggered Company Dialogue

Slice 3
  Sense / Improvement / Decision connection

Slice 4
  Existing Task / Project execution connection

Slice 5
  Result evaluation → Observation

Slice 6
  Minimal Semantic Navigator and accountability
```

各Sliceで実際にRISE GATEを最初の利用会社として運用し、次のSliceへ進む前にObservationを集めます。

## Product Test

Phase3の設計・実装判断では、次を確認します。

- この機能は、どのObservation Sourceを支えるか。
- APIがなくても人の観察で成立するか。
- Observationがない日に不要な対話を作っていないか。
- 問いには`Why now?`があるか。
- 人が意味と責任を与える場所を守っているか。
- 判断不能を許しているか。
- Resultを終点にしていないか。
- 次のObservationへ戻れるか。
- 代理店がObservation Designとして説明・支援できるか。

> Company OSのMVPは、毎日使わせることではなく、会社の変化を見失わず、意味と改善へつなぐ最小の循環を成立させることです。

