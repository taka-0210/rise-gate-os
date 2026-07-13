# Roadmap

## Current Status

現在は Phase 0 です。

Laravel実装はまだ開始していません。

## Phase 0: Documentation and Project Foundation

目的: Rise Gate OS の思想、設計、データ構造、Phase計画をレビュー可能な状態にする。

Scope:

- 初期ディレクトリ作成
- docs 作成
- 設計 v1.0 固定
- README / philosophy / architecture / database / roadmap / changelog 整理
- レビュー完了後、Laravel初期構築へ進む

## Phase 1: Minimum OS

目的: Projectを中心に、実際に使える最小のCompany OSを作る。

Scope:

- ログイン
- Workspace切替
- 顧客管理
- 案件管理
- 案件メンバー
- タスク管理
- 工程管理
- 改善管理
- Project Events
- Documents基本機能
- Activity Logs

DocumentsのPhase 1範囲:

- Projectへのファイルアップロード
- 文書タイトル
- 文書種別
- 説明
- アップロード者
- ファイル一覧
- ダウンロード
- 論理削除
- 権限チェック

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

## Phase 1 Implementation Order

1. Laravel初期構築
2. 認証導入
3. Organization / Workspace / User 基盤
4. Workspace切替
5. 権限チェック基盤
6. 顧客管理
7. 案件管理
8. 案件メンバー管理
9. 工程テンプレート
10. Project Steps
11. タスク管理
12. 改善管理
13. Project Events
14. Documents基本機能
15. Activity Logs
16. ダッシュボード
17. UI調整・権限テスト

## Phase 2: Business Operations

目的: 案件に紐付く業務処理を広げる。

Scope:

- 見積
- 契約
- 納品
- 請求
- 入金
- 保守
- 詳細な議事録管理

## Phase 3: Improvement OS

目的: 改善を蓄積・分析・活用するOSへ育てる。

Scope:

- 改善提案
- 改善ステータス強化
- 活動ログ分析
- ダッシュボード分析
- Client / Workspace単位のImprovement展開

## Phase 4: AI Integration

目的: 蓄積した知識をAIが活用できる状態にする。

Scope:

- 議事録AI
- 改善提案AI
- Documents検索
- ナレッジ検索
- Codex連携
- GitHub連携

## Phase 5: SaaS

目的: 他社にも展開できるCompany OSにする。

Scope:

- Organization管理
- 権限管理
- プラン管理
- 課金管理
- 外部公開API
