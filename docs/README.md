# Rise Gate OS

改善を、文化に。

Rise Gate OS は、単なる案件管理システムではありません。

会社の改善を育て、知識へ変え、AIが活用できる Company Operating System を目指すプロジェクトです。

## What We Are Building

Rise Gate OS は、ホームページ制作、システム開発、保守、顧客管理、タスク、工程、文書、改善を Project を中心に整理する業務プラットフォームです。

Project は社内だけの管理画面ではありません。お客様も参加者として招待できる、改善プロジェクトを共有する場所です。

Project は改善を実行する単位であり、お客様と一緒に改善を進める共有空間です。

ただし、Company OS が本当に管理する中心は Project 単体ではありません。

Project で生まれた Improvement が、Task になり、新しい Project になり、Document や知識になり、さらに次の Improvement を生みます。

Rise Gate OS は、この改善の連鎖を会社の成長として記録します。

Company OS は思想・概念であり、Rise Gate OS はそれを実際の業務で動かす実装・プロダクトです。

Improvement は会社の資産です。

Documents は知識の器です。

AI は蓄積された知識を活用するパートナーです。

この思想を中心に、すべての設計を行います。

## Values

- 改善を、文化に。
- まず使う。
- 現場で磨く。
- 知識を資産にする。
- 改善の連鎖を追跡する。
- 改善は管理するのではなく、育てる。
- 設計も改善対象として扱う。
- AIは人を置き換えるものではなく、改善を支援するパートナーである。
- お客様の「今どうなっていますか？」という不安をなくす。
- メール、電話、チャット、ファイル共有、進捗確認をProjectに集約する。

## Project Status

- Design version: v1.0 fixed
- Current phase: Phase 1-6 completed / Operation preparation
- Laravel implementation: Phase 1 foundation implemented
- Next step: Operate Rise Gate OS development inside Rise Gate OS itself

## Implemented Foundation

Phase 1-6 までで、以下の Company OS の骨格が動作しています。

```txt
Organization
  ↓
Workspace
  ↓
Company / Client
  ↓
Project
  ↓
Project Members
  ↓
Improvement
```

実装済みの主な範囲:

- ログイン
- Workspace切替
- Organization / Workspace / User 基盤
- Client / Company 管理
- Project 管理
- Project Members 管理
- Improvement 管理
- Project参加者とClient roleを考慮した権限チェック
- Improvement visibility による公開範囲制御

思想として追加した重要な方向性:

```txt
Project
  ↓
Improvement
  ├── Task
  └── New Project
```

Phase 2-2 では、この構造の第一歩として Task / New Project の Output 生成を実装しました。

## Operation Policy

Phase 1-6 以降、新機能を追加する前に Rise Gate OS 自身を最初の利用者として運用します。

Rise Gate OS の開発は、必ず Project を作成して進めます。

Codex への実装依頼も、Project 内の Improvement を元に行います。

改善の提案、実装、結果、次の改善を Rise Gate OS に残し、システム自身を会社の学習装置として育てます。

運用では、Improvement から Task が生まれるだけでなく、新しい Project が生まれる流れも記録していきます。

詳しくは `operation.md` を参照してください。

Evolution Dashboard は、会社の未来へ進むためのホーム画面です。昨日より会社が少し進化したことを感じ、今日育てる改善を見つけ、次の一歩へ進みたくなる体験を目指します。

## Documentation Policy

このプロジェクトでは、動くコードと同じくらい育つドキュメントを重視します。

設計、思想、改善履歴、顧客との共有プロセスもコードと同じ資産として管理します。

完璧な設計書を一度で作るのではなく、現場で使いながら改善していきます。ただし、v1.0確定後の大きな設計変更は直接書き換えるのではなく、Improvement として記録し、必要な判断を残します。

## Documents

- `philosophy.md`: 思想・目的・価値観
- `architecture.md`: システム全体構成と責務分離
- `database.md`: ER図・テーブル設計・データ方針
- `roadmap.md`: Phase管理と実装順序
- `operation.md`: Project / Improvement の運用ルール
- `changelog.md`: 設計変更履歴と意思決定
