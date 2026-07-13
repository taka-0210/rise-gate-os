# Architecture

## Version

Architecture v1.0.

この設計は Phase 1 実装開始前の基準です。以降の大きな変更は、直接書き換えるのではなく Improvement として記録します。

## System Layers

```txt
Organization
  契約・全体管理・将来のSaaS課金単位

Workspace
  独立した事業・顧客・案件・請求管理の単位

Project
  実際の案件管理単位であり、お客様と改善を共有する場所
```

## Organization

Organization は、将来のSaaSにおける契約、課金、全体管理の単位です。

Phase 1では最小限の管理に留めますが、主要データは Organization に紐付け、テナント間の分離を前提にします。

## Workspace

Workspace は、独立した事業・顧客・案件管理の単位です。

ライズゲート本体、弟子、独立パートナーは、それぞれ独立した Workspace を持てる設計にします。

Workspaceごとに顧客、案件、設定を分離します。

## Project

Project は実際の案件管理単位です。

また、Project は社内管理画面ではなく、お客様も参加者として招待できる改善プロジェクトの共有空間です。

Project は `owning_workspace_id` を持ちます。

将来、案件所有者と請求主体が異なる可能性があるため、`billing_workspace_id` を追加できる構造を想定します。初期段階では `owning_workspace_id` と `billing_workspace_id` は同じで構いません。

## Collaboration

共同案件用 Workspace は初期段階では作りません。

共同作業およびお客様のProject参加は `project_members` で対応します。

`project_members` では、業務上の役割 `project_role` とシステム権限 `permission_level` を分離します。

```txt
project_role:
  owner
  project_manager
  designer
  coder
  reviewer
  mentor
  client
  viewer

permission_level:
  admin
  edit
  comment
  view
```

招待状態を管理するため、`invited_by`、`invited_at`、`accepted_at`、`status` を持たせます。

## Client Participation

Project Members には社内メンバーだけでなく、お客様も含めます。

お客様は顧客ポータルの利用者ではなく、Projectへ招待されたメンバーとして扱います。

Client role のユーザーが見られる情報は権限と visibility によって制御します。

Client が見られる想定:

- Project概要
- 現在の進捗
- 工程
- 公開予定日
- Project Events
- Documents（公開設定されたもの）
- Comments（公開設定されたもの）
- Improvements（公開設定されたもの）
- 納品物
- 請求書（将来）

Client に見せない情報:

- 原価
- 利益
- 社内メモ
- 担当者評価
- 社内改善
- 内部タスク
- 内部コメント

## Visibility

Comments、Documents、Improvements は公開範囲を持ちます。

```txt
visibility:
  internal  社内限定
  project   Project参加メンバー向け
  client    Client roleにも公開
```

Phase 1ではまず `internal` と `client` を中心に扱い、必要に応じて `project` を使います。

## Project Scope in Phase 1

```txt
Project
  ├── Members
  ├── Tasks
  ├── Workflow Steps
  ├── Improvements
  ├── Project Events
  ├── Documents
  ├── Comments
  └── Activity Logs
```

## Phase 2 Additions

```txt
Project
  ├── Estimates
  ├── Contracts
  ├── Deliveries
  ├── Invoices
  ├── Payments
  └── Maintenance
```

## Business Flow Policy

見積、契約、納品、請求、入金は Project に紐付けますが、一本道で進む強制設計にはしません。

着手金、中間請求、分割納品、契約書なしの案件、月額保守に対応できる柔軟な構造にします。

未完了の前工程がある場合は処理を禁止するのではなく、警告を表示する方針にします。

## Project Events and Activity Logs

Project Events は業務上意味のあるタイムラインです。

例:

- 打ち合わせ
- 中間確認
- 方針決定
- 顧客からの承認
- 案件作成
- ステータス変更
- 工程完了
- 見積承認
- 納品
- 請求
- 入金

Activity Logs はシステム操作履歴・監査ログです。

例:

- ユーザーが案件名を変更した
- ユーザーがタスクを作成した
- ユーザーが改善ステータスを更新した
- ユーザーが文書を削除した

Project Events は Activity Logs の全件表示ではありません。人が読んで案件の流れを理解できる業務タイムラインとして扱います。

## Technical Direction

Laravel + MySQL を第一候補とします。

初期フロントエンドは以下を基本候補とします。

- Blade
- Alpine.js
- Tailwind CSS

必要に応じて、将来 Livewire を追加できる構成にします。

## Improvement as Origin

運用を通じて、Improvement は Task だけでなく New Project の起点にもなることが分かりました。

従来の単純な流れは以下でした。

```txt
Project
  ↓
Improvement
  ↓
Task
```

しかし実際の業務では、改善の検討結果として新しいProjectが生まれることがあります。

例:

```txt
Project: ホームページ制作
  ↓
Improvement: お問い合わせが増え、メール管理では限界
  ↓
検討
  ↓
New Project: CRMシステム開発
```

```txt
Project: ホームページ制作
  ↓
Improvement: 採用情報をもっと充実させたい
  ↓
New Project: 採用サイト制作
```

```txt
Project: 勤怠管理システム
  ↓
Improvement: 給与計算まで管理したい
  ↓
New Project: 給与計算システム
```

そのため、Rise Gate OS では Improvement を「作業の起点」だけでなく、「次のProject、Document、Decision、Taskを生む起点」として扱います。

Company OS が管理する中心は、Projectそのものではなく、Projectで生まれたImprovementが次の行動やProjectへつながっていく改善の連鎖です。

```txt
Project
  ↓
Improvement
  ├── Task
  ├── New Project
  ├── Document
  └── Decision
```

この考え方により、Project は単発で終わるものではなく、改善を通じて次のProjectや知識へ連鎖していく構造になります。

## Project Relationships

Project同士には、将来的に親子関係や派生関係が発生します。

代表例:

- 元Projectから改善が生まれる
- 改善の結果、新しいProjectが生まれる
- 新Projectは元Projectと独立して進行する
- ただし、なぜ生まれたのかという由来は残す

将来検討する関係:

```txt
projects.parent_project_id
projects.source_improvement_id
```

意味:

```txt
parent_project_id      どのProjectから派生したか
source_improvement_id  どのImprovementから生まれたか
```

ただし、現時点ではまだ実装しません。

運用を続けながら、単純なカラムで足りるのか、あるいは `improvement_outputs` のような中間概念が必要かを検証します。

## Improvement Outputs

将来的には、Improvement の成果物を統一的に扱うために、以下のような概念を検討します。

```txt
improvement_outputs
  improvement_id
  output_type  task / project / document / decision
  output_id
```

この設計にすると、Improvement から何が生まれたのかをAIや検索が追いやすくなります。

ただしPhase 1では過剰に抽象化せず、運用で実例を集めてから判断します。

当面は実装を保留し、Improvement がどのような Task や New Project を生むのかを実運用で観察します。
