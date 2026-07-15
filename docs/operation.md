# Operation

## Purpose

このドキュメントは、Rise Gate OS を実際に運用するためのルールを定義します。

Rise Gate OS は、作って終わるシステムではありません。

実際に使いながら改善点を見つけ、その改善を Rise Gate OS 自身に記録し、会社の知識として蓄積していきます。

Phase 1-6 以降、Rise Gate OS 自身の開発は Rise Gate OS 上で管理します。

## Basic Flow

```txt
Projectを作る
  ↓
Improvementを登録する
  ↓
レビューする
  ↓
実装する
  ↓
結果を記録する
  ↓
次の改善へつなげる
```

## Projectの作り方

Project は改善を実行する単位です。

新しい開発、Web制作、社内改善、顧客案件は、まず Project として作成します。

Project作成時に決めること:

- Project名
- Client / Company
- 概要
- ステータス
- 優先度
- 開始日
- 期限

Client が存在しない社内改善や自社プロダクト開発は、Clientなしの Project として作成できます。

例:

- Rise Gate OS
- Rise Gate ホームページ
- プロ厨房ヒット沖縄 ホームページ
- Company OS 構築
- 社内改善

## Project運用ルール

Rise Gate OS の開発は、必ず Project を作成して進めます。

Project には、関係者を Project Members として追加します。

Project Members では、業務上の役割とシステム権限を分けます。

業務上の役割:

- owner
- project_manager
- designer
- coder
- reviewer
- mentor
- client
- viewer

システム権限:

- admin
- edit
- comment
- view

お客様も Project に参加する場合は、client role の Project Member として扱います。

## Improvementの登録方法

Improvement は、単なる作業メモではありません。

現状、理想、問題、仮説、行動、結果、影響、次の行動を記録し、会社の知識に変えるための単位です。

登録時に書くこと:

- title
- current_state
- desired_state
- problem
- hypothesis
- action
- result
- impact
- next_action
- status
- visibility
- assigned_to

最初からすべて埋める必要はありません。

ただし、最低限 `title`、`current_state`、`problem`、`hypothesis`、`next_action` のどれかは書き、後から結果と影響を追記できる状態にします。

## Improvement運用ルール

Improvement は次の流れで運用します。

```txt
改善提案
  ↓
レビュー
  ↓
実装
  ↓
結果記録
  ↓
次の改善
```

### 1. 改善提案

気づいた改善点を Improvement として登録します。

この時点では、解決策が完全である必要はありません。

重要なのは、問題と仮説を残すことです。

### 2. レビュー

登録された Improvement を確認し、実装するか、保留するか、分割するかを判断します。

判断の観点:

- 今すぐ必要か
- Projectの目的に合っているか
- 他の改善と重複していないか
- 実装すると何が良くなるか
- お客様に見せるべき内容か、社内限定か

### 3. 実装

実装する Improvement は `assigned_to` を設定し、status を進めます。

Codex に実装依頼する場合も、Improvement の内容を元に依頼します。

Codex への依頼に含めること:

- 対象Project
- 対象Improvement
- 現状
- 問題
- 期待する状態
- 実装範囲
- 今回やらないこと

### 4. 結果記録

実装後は、`result` と `impact` を記録します。

ここを残すことで、単なる作業履歴ではなく、会社の学習になります。

### 5. 次の改善

実装して終わりではありません。

気づいた課題や次の打ち手を `next_action` に残し、必要なら新しい Improvement を作成します。

## Visibility運用

Improvement には公開範囲を設定します。

```txt
internal  社内限定
project   Project参加メンバー向け
client    Client roleにも公開
```

原価、利益、社内メモ、担当者評価、内部タスク、内部コメントに関する改善は `internal` にします。

お客様と共有したい進捗改善、納品改善、運用改善は `client` にできます。

迷った場合は `internal` で登録し、レビュー時に公開範囲を見直します。

## Evolution Dashboardの運用

Evolution Dashboard は、毎日最初に開くホーム画面として扱います。

目的は、未完了や遅れを責めることではありません。

昨日より会社が進化したことを確認し、今日育てる改善を見つけ、次の一歩へ進むことです。

Dashboardで見る観点:

- 今日、何が進んだか。
- 今週、どんな改善が生まれたか。
- 結果や影響を追記すると知識になる改善はどれか。
- 確認すると進められる改善はどれか。
- 次に育てるべき改善はどれか。

停滞しているImprovementは、失敗や遅延として扱いません。

まだ育てられる改善として扱い、必要なら担当者、結果、影響、次の行動を更新します。

## Codexとの連携運用

Phase 2 以降、Codex への実装依頼は Project 内の Improvement を元に行います。

基本形:

```txt
Project: Rise Gate OS
Improvement: Dashboard改善
目的: 今日やることと未完了Improvementを見えるようにする
今回やること: DashboardにImprovement一覧を追加する
今回やらないこと: Task、Documents、AI連携
```

Codex の実装結果は、Improvement の `result` と `impact` に反映します。

## Rise Gate OS 自身の運用開始

最初の運用対象は Rise Gate OS 自身です。

推奨初期データ:

Workspace:

- Rise Gate

Client:

- Rise Gate（社内）
- 株式会社プロ厨房ヒット沖縄

Project:

- Rise Gate OS
- Rise Gate ホームページ
- プロ厨房ヒット沖縄 ホームページ

Improvement:

- Project Members 実装
- Improvement UI改善
- Dashboard改善

この初期データは `RiseGateOsOperationSeeder` で作成できます。

## Rule

新機能を追加する前に、まず Improvement として記録します。

設計変更も Improvement として扱います。

Rise Gate OS は、Rise Gate OS 自身を使いながら改善します。

## Improvementから生まれるもの

運用を通じて、Improvement は Task だけでなく New Project の起点にもなることが分かりました。

例:

```txt
Project: ホームページ制作
  ↓
Improvement: SNS投稿をもっと増やしたい
  ↓
検討
  ↓
New Project: SNS投稿管理システム
```

そのため、Improvement のレビュー時には、次のどれに進むのかを考えます。

```txt
Improvement
  ├── Taskとして実行する
  ├── New Projectとして切り出す
  ├── Documentとして知識化する
  └── Decisionとして判断を残す
```

Phase 2-2 では、Task と New Project を Improvement から生まれる Output として登録できるようにします。Document、Knowledge、Event などは引き続き将来拡張として扱います。



## Roadmap運用ルール

Roadmapは、Projectが目指す未来へ向かうテーマです。

Roadmapは改善そのものではありません。複数のImprovementを生み出し、束ねる一段上の視点です。

最初から必ず作る必要はありません。

- ProjectはRoadmapなしで始められる。
- Improvementが増えてきたら、テーマとしてRoadmapを作る。
- 既存Improvementを後からRoadmapテーマへ追加できる。
- Roadmapは完成した計画表ではなく、改善とともに育つテーマである。
- ロードマップテーマは、作成時に先頭または既存テーマの後ろへ配置できる。
- 小さなProjectではRoadmapを作らなくてもよい。

Roadmapを作る目安:

- Improvementが増えて、改善を束ねるテーマが見えてきた。
- Projectの目指す未来をチームで共有したい。
- 未分類のImprovementが増えてきた。
- 改善の流れをお客様にも分かりやすく見せたい。
## Output生成の運用ルール

Improvement をレビューした後は、次のOutputを必要に応じて作成します。

- 小さな実作業は Task として作成する。
- 複数人・別納期・独立成果物が必要な場合は New Project として作成する。
- 1つの Improvement から複数 Task と New Project が同時に生まれてもよい。
- 作成した Output は、元 Improvement との関係を残す。

Output は「改善から会社の未来が生まれた記録」です。
## New Project化の判断基準

Improvement を New Project として切り出す目安:

- 作業量が大きい
- 複数人で進める必要がある
- 納期や工程を分けたい
- お客様と別Projectとして共有したい
- 成果物が独立している
- 継続運用が発生する

小さな作業で完了する場合は Task として扱います。

判断に迷う場合は、まず Improvement に `next_action` として残し、レビュー時に Task 化するか Project 化するかを決めます。
