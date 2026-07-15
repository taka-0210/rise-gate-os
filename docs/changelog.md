# Changelog

このファイルは、Rise Gate OS の設計変更履歴と意思決定を記録します。

コードだけでなく、設計、思想、改善履歴もプロジェクトの資産として管理します。

## Policy

- v1.0確定後の大きな設計変更は、直接上書きせず Improvement として記録する。
- 設計も改善の対象として扱う。
- 変更理由と判断の背景を残す。
- ドキュメントはコードと同じく育てる資産として扱う。

## v1.0 - Design Fixed

Status: Fixed before Laravel implementation.

Summary:

- Rise Gate OS の基本思想を固定。
- Organization / Workspace / Project 構造を採用。
- Organization は契約・全体管理・将来のSaaS課金単位とした。
- Workspace は独立した事業・顧客・案件管理の単位とした。
- Project は実際の案件管理単位とした。
- 共同案件用 Workspace は初期段階では作らず、project_members で共同作業に対応する方針とした。
- Project は owning_workspace_id を持つ方針とした。
- billing_workspace_id は将来の請求主体分離に備えた設計として保持する。
- project_members では project_role と permission_level を分離する。
- Improvements は Phase 1 では Project 紐付けとする。
- Documents は Phase 1 では Project 紐付けとする。
- Project Events と Activity Logs を分離する。
- Documents を単なる Files ではなく、業務文書・証跡・成果物として扱う。
- Phase 1 の範囲を確定。
- Laravel実装開始前の初期ディレクトリとdocsを作成。

## v1.0 Addendum - Client Participation

Status: Added during documentation review before Laravel implementation.

Summary:

- Project は社内だけの管理画面ではなく、お客様も参加できる改善プロジェクトの共有空間と定義した。
- `project_members.project_role` に `client` を追加する方針とした。
- Client は顧客ポータルではなく、Projectへ招待されたメンバーとして扱う。
- Comments、Documents、Improvements に `visibility` を持たせる方針とした。
- Client roleには、公開された進捗、工程、Project Events、Documents、Comments、Improvements、納品物を表示できる設計とした。
- 原価、利益、社内メモ、担当者評価、社内改善、内部タスク、内部コメントは社内限定とした。

## Phase 0 Notes

現在は Laravel 実装前のドキュメント整理段階です。

レビュー完了後、Laravelプロジェクトの初期構築へ進みます。

## Design Addendum - Improvement Outputs and Project Lineage

Status: Added after Phase 1 operation review. Task / Project outputs implemented in Phase 2-2.

Summary:

- 運用により、Improvement は Task だけでなく New Project の起点にもなることが分かった。
- Project は改善を生むだけでなく、改善を通じて次のProjectへ連鎖する構造として考える。
- Taskには通常タスクとImprovement由来タスクがある。
- 将来候補として `projects.parent_project_id`、`projects.source_improvement_id` を検討する。
- より汎用的な将来候補として `improvement_outputs` を検討する。
- 現時点では実装せず、運用実績を集めてから最適なデータ構造を判断する。
- AI活用のため、成果物だけでなく「なぜそれが生まれたか」を追える構造を重視する。

## Design Addendum - Evolution Dashboard Philosophy

Status: Added before Phase 2-1 implementation. Not implemented yet.

Summary:

- Improvement は管理するものではなく、育てるものと定義した。
- Project は主役ではなく、改善を育てる器と定義した。
- Company OS は思想・概念、Rise Gate OS は実装・プロダクトと整理した。
- Rise Gate OS 自身も改善を続けながら育っていく Operating System と定義した。
- Evolution Dashboard は会社の未来へ進むためのホーム画面と定義した。
- Dashboardの主役は不足や遅延ではなく、会社が少しずつ良くなっている実感とした。
- 停滞している改善は「確認すると進められる改善」として扱う方針とした。
- Phase 2-1ではルールベースで「次に育てる改善」を表示し、将来AI提案へつなげる方針とした。

## Phase 2-2 - Output Generation

Status: Implemented.

Summary:

- Improvement を Task / Project を生み出す起点として実装した。
- `tasks` を追加し、Project から通常Taskを登録できるようにした。
- `improvement_outputs` を追加し、Improvementから生まれた Task / Project を追跡できるようにした。
- 1つの Improvement から複数 Task と New Project が生まれる構造に対応した。
- Document / Knowledge / Event のOutput化は将来拡張として残した。

## Phase 2-2.1 - Project Origin Visibility

Status: Implemented.

Summary:

- Improvementから生まれたProjectを、Project一覧で一目で判別できるようにした。
- Project詳細に「このProjectの起点」を追加し、元Project / 元Improvementへ戻れる動線を追加した。
- 元Improvementの閲覧権限がないユーザーには、内部改善名を表示しないようにした。

## Phase 2-3 - Roadmap MVP

Status: Implemented.

Summary:

- RoadmapをProjectが目指す未来へ向かうテーマとして追加した。
- Roadmapは必須ではなく、改善が増えてきたタイミングで作成する任意テーマとした。
- MVPでは `roadmap_items` を作らず、`improvements.roadmap_id` でシンプルに開始した。
- 既存ImprovementをRoadmapテーマへ追加 / 未分類へ戻せるようにした。
- 将来、RoadmapにImprovement以外も並べる必要が出た場合に `roadmap_items` へ育てる方針とした。

## Phase 2-3.1 - Roadmap Theme Granularity

Status: Implemented as documentation and UI refinement.

Summary:

- 運用レビューにより、RoadmapとImprovementの粒度が近すぎると二重表現になることが分かった。
- Roadmapは改善そのものではなく、改善を生み出し、束ねるテーマとして再定義した。
- ImprovementはRoadmapテーマを前へ進める具体的な改善として扱う。
- DB構造は変更せず、文言とUIで粒度を明確にした。

## Phase 2-3.2 - Roadmap Theme Position

Status: Implemented.

Summary:

- UI文言を「改善テーマ」ではなく「ロードマップテーマ」へ統一した。
- ロードマップテーマ作成時に、先頭または既存テーマの後ろへ配置できるようにした。
- `roadmaps.sort_order` を使い、テーマの並び順をProject内で管理する方針を明確にした。
