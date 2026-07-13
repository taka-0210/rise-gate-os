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
