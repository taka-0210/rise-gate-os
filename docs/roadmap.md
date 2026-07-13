# Roadmap

## Current Status

現在は Phase 1-6 completed / Operation preparation です。

Organization、Workspace、Company(Client)、Project、Project Members、Improvement までの Company OS の骨格が実装されました。

ここで新機能開発を一時停止し、Rise Gate OS 自身の開発を Rise Gate OS で管理する運用へ移行します。

運用の中で、Improvement が Task だけでなく New Project を生むことを確認し、Company OS の中心を「Project管理」ではなく「改善の連鎖」として捉える方針を追加しました。

## Phase 0: Documentation and Project Foundation

Status: Completed.

目的: Rise Gate OS の思想、設計、データ構造、Phase計画をレビュー可能な状態にする。

Scope:

- 初期ディレクトリ作成
- docs 作成
- 設計 v1.0 固定
- README / philosophy / architecture / database / roadmap / changelog 整理
- レビュー完了後、Laravel初期構築へ進む

## Phase 1: Minimum OS

Status: Paused after Phase 1-6 for operation preparation.

目的: Projectを中心に、実際に使える最小のCompany OSを作る。

Implemented through Phase 1-6:

- Laravel初期構築
- 認証導入
- Organization / Workspace / User 基盤
- Workspace切替
- 権限チェック基盤
- Client / Company 管理
- Project 管理
- Project Members 管理
- Improvement 管理
- Improvement visibility と Client role の基本制御

Not implemented yet:

- Task管理
- 工程管理
- Project Events
- Documents基本機能
- Activity Logs
- ダッシュボード改善

Phase 1で行わないこと:

- 見積
- 契約
- 納品
- 請求
- 入金
- 保守
- バージョン管理
- 複数ファイル構成
- 全文検索
- AI検索
- 自動分類
- ClientやWorkspaceへのDocuments直接紐付け

## Phase 1 Operation Preparation

目的: Rise Gate OS を実際に運用できる状態にする。

Scope:

- README / roadmap の Current Status 更新
- Rise Gate OS 開発用 Seeder の追加
- `docs/operation.md` の追加
- Project運用ルールの明文化
- Improvement運用ルールの明文化
- 今後の Codex 実装依頼を Project 内 Improvement から開始する運用へ移行

この段階から、Rise Gate OS 自身を最初の利用者として扱います。

## Phase 2: Operated Development

目的: Rise Gate OS を使いながら改善点を見つけ、その改善を Rise Gate OS 自身で管理する。

Start condition:

- Rise Gate OS 開発用の Workspace / Client / Project / Improvement が登録されている。
- 開発作業は Project 内 Improvement を元に開始できる。
- 新しい機能追加は、先に Improvement として目的、問題、仮説、期待する影響を記録する。
- Improvement から Task が生まれるのか、新しい Project が生まれるのかを運用で記録する。

Candidate improvements:

- Dashboard改善
- Improvement編集・ステータス更新
- Documents基本機能
- Project Events
- Activity Logs
- Task管理
- ImprovementからNew Projectが生まれる運用の検証
- Project同士の関係設計の検証
- 工程管理

## Phase 3: Business Operations

目的: 案件に紐付く業務処理を広げる。

Scope:

- 見積
- 契約
- 納品
- 請求
- 入金
- 保守
- 詳細な議事録管理

## Phase 4: Improvement OS

目的: 改善を蓄積・分析・活用するOSへ育てる。

Scope:

- 改善提案
- 改善ステータス強化
- 活動ログ分析
- ダッシュボード分析
- Client / Workspace単位のImprovement展開
- Improvementを起点にしたTask / Project / Document / Decisionの整理
- 改善の連鎖を可視化するCompany OS構造

## Phase 5: AI Integration

目的: 蓄積した知識をAIが活用できる状態にする。

Scope:

- 議事録AI
- 改善提案AI
- Documents検索
- ナレッジ検索
- Codex連携
- GitHub連携

## Phase 6: SaaS

目的: 他社にも展開できるCompany OSにする。

Scope:

- Organization管理
- 権限管理
- プラン管理
- 課金管理
- 外部公開API
