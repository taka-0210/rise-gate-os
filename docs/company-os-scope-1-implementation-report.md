# Company OS Ver.1 Scope 1 実装・検証報告

- 実施日: 2026-09-19（JST）
- 対象Repository: `C:\xampp\htdocs\rise-gate-os`
- Branch: `master`
- 実装開始Commit: `3e5b6b3c613f5bc41892da6613fcc323306f95fc`
- Scope 1実装Commit: `15751f290f366aace117cfd976190d581c1b2cae`
- Scope 1実装CommitのPush先: `origin/master`（`15751f2`を含む）
- Final Close判定: **Closed / 次Scopeの実装へは未着手**
- 対象: 既存Project計画のAI Proposalを、人が確認して安全に承認・適用できる状態
- 非対象: 本番Deploy、Project新設、Delete、親移動、実績・担当・権限・Financial等の変更

## 結論

Scope 1のP0〜P5を実装し、受入条件をコードとTestで確認した。既存Proposal、既存Entity ID、Relation、履歴を保持したまま、Contract、楽観Lock、Approval、Apply Attempt、Item Result、限定Undo、AI Context境界を追加している。

既存Dataを削除する処理、既存ColumnのDrop/Rename、不可逆Migrationはない。旧Contract Proposalは閲覧できるが、そのままApplyはできず、再提案が必要である。

Scope 1完了はCompany OS Ver.1全体の公開準備完了を意味しない。後続ScopeのDomain整理、権限定義、運用決定は別途必要である。

## Final Close Summary

### Scope 1でできるようになったこと

- `project-plan.v1`の明示Contractで、Project説明Update、Roadmap／旧Improvement／TaskのCreate・Updateだけを提案できる。
- AI Proposalを、提案確認、Approval、Applyに分け、承認時と適用時の内容・版・権限を記録できる。
- Apply全体をTransaction化し、親子Temporary Referenceを実Entityへ解決しながら、全件成功または全件Rollbackにできる。
- Attempt／Item Resultから、誰が、何回目に、どのItemを、どの実Entityへ適用し、なぜ失敗したか追跡できる。
- 連打、同時実行、ネットワーク再送、Commit後の応答消失で二重適用しない。
- 適用後に別変更がないUpdate Proposalだけ、人の明示操作で元の説明Fieldへ戻せる。
- AI ContextをProject、Tenant、Membership、AI設定、Category、件数・文字数上限でProvider送信前に制限できる。

### Testで保証したこと

- 許可された4 EntityのCreate／Updateと、未知Field、Delete、Timeline、Financial、Role変更の拒否。
- same-second更新、親集合更新、Soft Delete／Restoreを含むstale検知。
- Tenant改ざん、Confidential client、権限・所属失効、AI OFF、禁止Categoryの拒否。
- Approval内容固定、途中失敗Rollback、結果履歴、Retry分類、idempotency、再送収束。
- Update-only Undo、後続変更時のUndo拒否、Create Undo拒否。
- Proposal UIの差分、成功、競合、処理中、再提案表示。
- 既存機能を含む全Test 333件／2363 assertions。

### 保証していないこと

- CreateされたEntityの自動削除Undo、Delete／Status／担当／期限／実績／権限のApply・Undo。
- Project新設、親移動、Timeline全置換、Financial／File／Knowledge等の変更。
- 全社4-eyes承認制度、新しいRole体系、料金・契約、顧客公開範囲。
- 複数Proposalを跨ぐ業務Rollbackや外部Service副作用のTransaction保証。
- Ver.1全体のRelease Ready、本番Migration、本番Deploy。

### KEEP / REFACTOR / ADD

| 分類 | 最終整理 |
|---|---|
| KEEP | 既存`AiProposal`／`AiProposalItem`、Project階層、public ID、Organization／Workspace／Project Membership、既存Review／Revision UI、AI Access Key、既存履歴を正本として継続利用 |
| REFACTOR | 旧`AiProposalApplier`をScope 1安全ApplierへのAdapterへ変更、ValidatorをContract whitelist中心へ整理、API／MCP／AI ChatのContext判定を共通Guardへ集約、Proposal画面をApprovalとApplyへ分離 |
| ADD | Contract metadata、before／after／expected version、`plan_version`、Approval記録、Apply Attempt、Item Result、Update-only Undo、Context上限、Version Observer、受入Test、運用Feature Flag |

既存Entityや旧Proposalは削除・Renameしていない。旧形式を自動変換するのではなく、閲覧保持＋再提案としている。

## P0｜現状固定・対応表

### 実施内容

- Repository、Branch、Commit、未コミット状態、適用される`AGENTS.md`を確認した。
- PHP、Laravel、Composer、DB、Timezone、既存Testを確認した。
- ProposalからProject計画を書き換える既存Writerと、対象Entityの物理Fieldを調査した。
- Scope 1の許可Operation／Fieldと非対象を固定した。

### 変更内容

P0では実装変更なし。実装開始時のWorktreeはcleanで、既存User変更はなかった。

### Entity対応表・Whitelist

| Company OS概念 | 既存Entity | 許可Operation | 許可Field |
|---|---|---|---|
| Project | `Project` | Update | `summary`, `current_state`, `desired_future_state` |
| Roadmap | `Roadmap` | Create / Update | `title`, `purpose` |
| Action Theme | `Improvement` | Create / Update | `title`, `current_state`, `desired_state`, `problem`, `hypothesis`, `action`, `next_action` |
| Action | `Task` | Create / Update | `title`, `description` |

Create時の既存必須値は、既存Model／DBの安全な既定値で充足する。AIは担当、期限、Status、Role、Visibility、実績を補完しない。

明示的な非対象は、Delete、Timeline全置換、Project新設、親移動、Status／期限／担当／Review／実績／Member／Role／Visibility、Organization／Workspace移動、Company Improvement昇格、Document／Knowledge、File、Financial／Loan、User／Permissionである。

### Writer一覧

| Writer | 競合検知への対応 |
|---|---|
| 通常のEloquent Create / Update / Delete / Restore | `PlanVersionObserver`でEntityとProject集合Versionを更新 |
| Timelineの一括更新 | Controllerで更新対象とProject集合Versionを明示更新 |
| ProjectのWorkspace移動 | Projectと子EntityのVersionを明示更新 |
| Plan Restore | Query直書きを避け、Model Eventが発生する削除へ変更 |
| Scope 1 Apply / Undo | 同一Transaction内でRow Lock、Version確認、更新、履歴保存 |

### Test / 検証結果

- 既存関連Baseline: 35 tests / 257 assertions 成功
- `config('app.timezone')`: `Asia/Tokyo`
- Local／Testing DB: SQLite。P0確認で既存DBの変更なし

### 残課題

なし。

### Pending Decision

なし。

## P1｜Contractと競合検知

### 実施内容

- `project-plan.v1` Contract、Capability、Approval Policy、Item public ID、依存関係、Before／After、期待Version、親Project Version、Contract Hashを追加した。
- Project／Roadmap／Improvement／Taskに単調増加する`plan_version`を追加した。
- 同一秒内の変更、親集合の変更、Soft Delete／Restoreを検出対象にした。
- Idempotency Key再利用時は、同じ内容だけを同一Proposalとして返し、異なる内容を拒否するようにした。
- 旧Proposalは読み取り専用とし、再提案経路を用意した。

### 変更内容

- `AiProposalContract`: Scope、Field、Operation、Hashの正規化
- `AiProposalFactory`: stable item ID、snapshot、version、idempotency整合
- `AiProposalValidator`: whitelist、依存順、参照、snapshot、versionの検証
- `PlanVersionObserver`: 全通常WriterのVersion更新
- 追加Migration: 既存ID／Relation／Dataを保持したColumn・Table追加のみ

### Test / 検証結果

- 同一秒内のHuman editをVersion差分で検出
- 子Entity変更によるProject集合Version差分を検出
- Project Soft Delete／Restore後の旧Proposalをstale判定
- ID／親Project／Tenant改ざんを拒否
- 同じIdempotency Keyと異なるPayloadの組合せを拒否

### 残課題

なし。

### Pending Decision

- Project全体の`plan_version`は保守的な集合Versionとして運用する。将来、競合範囲が広すぎることが実測された場合のみ、Roadmap等のCollection Version分割を検討する。

## P2｜認可・AI Context

### 実施内容

- Request時だけでなく、Approval／Apply／Undo時にもUser、Organization、Workspace、Project Membershipを再確認するようにした。
- Project閲覧と変更を分離し、Project metadataはOwner、Action等は既存edit権限を要求した。
- legacy client roleは、edit相当値を持つ場合でもConfidential ProjectのProposal／Context／Applyを拒否した。
- Workspace AI OFF、許可Category、Project境界、Entity件数、文字数をProvider送信前に検証した。
- AIへ送信するProject planから、担当、Status、Priority等のScope外実行情報を除外した。
- Pending Handoff本文をContextへ出さず、承認済み最新HandoffだけをProject metadata contextへ含めた。
- Auditにはresource ID、category、結果を記録し、raw contextやSecretは記録しない。

### 変更内容

- `AiProposalAuthorization`: Proposal操作の共通認可
- `AiProjectContextGuard`: AI Key、Tenant、Membership、AI設定、Category制御
- API、MCP、Project AI Chatを同じ境界へ統合
- Context上限: 500 Entity／100,000文字（環境設定可能）

### Test / 検証結果

- 別Project／別Tenant、失効Membership、AI OFF、禁止CategoryをProvider呼出し前に拒否
- Confidential Clientの閲覧／MCP／Applyを拒否
- 上限超過を拒否してAudit
- Scope外Fieldと禁止RelationをContextへ含めない
- Pending Handoff本文が送られず、承認済みHandoffだけが送られることを確認

### 残課題

なし。

### Pending Decision

- 将来のOwner／Member／Reviewer／Viewer体系と既存role／permission値の物理対応は後続Scopeで決定する。
- `comment`権限をScope 1 Apply権限へ自動変換しない。権限制度の決定後に対応する。
- Context上限値は技術的な暫定値。運用計測後に調整可能。

## P3｜Approval・Apply・Attempt・Item Result

### 実施内容

- ApprovalとApplyを別操作・別状態に分離した。
- Approval後も、Contract Hash、Version、Permission、TenantをApply Transaction内で再確認するようにした。
- Applyを同一Proposal単位で直列化し、業務Data、結果、履歴を単一Transactionで確定するようにした。
- Apply AttemptとItem Resultを永続化し、失敗・未実行・Rollbackを区別した。
- 同時実行、二重送信、Commit応答消失後の再送を同じ確定結果へ収束させた。
- 一時DB障害等だけをRetry可能とし、最大3回、processing timeout 5分とした。

### 責務分離

| 概念 | 責務 |
|---|---|
| Approval | 人が特定Contract Hashの内容を確認した事実 |
| Apply | 承認済み内容を現在の権限・Versionで適用する操作 |
| Attempt | Applyの1回の実行単位、開始・終了・失敗分類 |
| Item Result | Itemごとの`applied`／`failed`／`not_run`／`rolled_back`結果 |

### Test / 検証結果

- 途中Item失敗時、業務Dataを全Rollbackし、Attempt／Item Resultは失敗証跡として保持
- 異なる2プロセスの同時Apply: 変更1回、成功Attempt 1件、成功Item Result 1件
- Commit後の再送: 再適用せず既存成功結果を返却
- Retry不可のvalidation／permission／conflictは再試行しない
- Approval後の内容変更、権限失効、Membership失効、Version差分を拒否

### 残課題

なし。

### Pending Decision

- Retry上限3回、processing timeout 5分は技術的暫定値。運用実績により調整可能。

## P4｜画面と限定Undo

### 実施内容

- User操作を「AIからの提案」「内容確認・修正依頼」「変更を適用」の3段階に整理した。
- Before／After、旧Contract警告、processing、成功件数、対象Link、失敗種別、Retry／再提案導線を表示した。
- Update-onlyのUndoを追加した。
- Undo時にも権限、Tenant、対象Version、依存関係、現在値を再確認するようにした。

### Undo保証範囲

| 対象 | 保証 |
|---|---|
| Scope 1で適用したUpdate | 適用直後の対象が期待snapshotのままなら、変更したFieldだけをBeforeへ戻す |
| Scope 1で作成したEntity | Undo対象外。自動削除しない |
| Apply後にHuman変更されたEntity | Undoを拒否し、上書きしない |
| Permission／Membership失効後 | Undoを拒否 |
| Scope外副作用 | Scope 1では発生させない |

### Test / 検証結果

- Update Undo成功
- Create混在ProposalではUpdateだけUndo可能とせず、Createを含むProposal全体のUndoを拒否
- Apply後の対象Field変更と、Scope内の未変更Field変更の双方を競合検出
- 権限失効後のUndo拒否
- UIに3段階、差分、成功、競合、処理中、再提案を表示

### 残課題

Createの安全な逆操作は未実装。Scope 1では意図的に保証外。

### Pending Decision

- Create Undo、複数Proposalを跨ぐUndo、業務承認取消は後続Scopeで別Contractとして設計する。

## P5｜Migration・回帰・受入

### 実施内容

- 専用SQLite cloneでMigrationを適用し、旧Data固定fixtureの保持を確認した。
- 同じMigrationの再実行、Rollback、再適用を確認した。
- Scope 1 focused tests、既存全回帰Test、PHP syntax、format、route、diffを検証した。

### Migration検証結果

1. 全既存Migrationを適用した専用SQLite DBを作成。
2. 固定IDのProject、Roadmap、Improvement、Task、旧Proposal、旧Itemを登録。
3. Scope 1 Migration適用後もID、Relation、値が一致することを確認。
4. 旧Proposalの`contract_version`と旧Itemの`public_id`がnullのまま保持され、閲覧専用になることを確認。
5. 再実行が`Nothing to migrate`であることを確認。
6. Rollback後も既存ID、Relation、値が一致し、追加Column／Tableだけが除去されることを確認。
7. 再適用後も既存Dataが保持されることを確認。
8. 検証用DBと出力を削除。

本番Dataや通常のlocal DBはこの検証で変更していない。

### 最終Test / 検証結果

- `php artisan test`: **333 passed / 2363 assertions**（Final Close再実行: 2026-09-19、116.15秒）
- `vendor/bin/pint --dirty`: passed
- 変更・追加PHP 43 files: syntax OK
- `git diff --check`: passed
- Proposal Route 11件: 登録確認
- Node.js実行環境がPATHおよび既知のlocal pathに存在しないため、`npm run build`は未実行。PHP／Bladeを含む全Feature Testは成功している。

### 残課題

- Node.jsが利用可能なCIまたは開発環境で、既存Frontend buildを実行する。
- 本番Deployは実施しない。GitHub Actionsの手動`workflow_dispatch`対象である。

### Pending Decision

下記「Pending Decision一覧」を参照。

## DC-01〜23 最終判定

判定定義は、`Done`＝正本のDone条件を実装と証跡で確認、`Conditional`＝中核条件は満たすが証跡範囲に明記すべき制約がある、`Not Done`＝必要機能または確認が欠ける、とする。

**集計: Done 17 / Conditional 6 / Not Done 0**

| DC | 判定 | 正本の確認内容 | Test・実装・Evidence | 判定上の注記 |
|---|---|---|---|---|
| DC-01 | Done | 対象Commit、DB、Writer、Role mapping | 本報告P0、開始Commit `3e5b6b3`、実装Commit `15751f2`、Writer一覧、`AiProposalAuthorization` | 既存Roleを利用し、新Role制度は追加していない |
| DC-02 | Done | Project説明U＋既存3階層C/Uだけを許可 | `AiProposalScopeOneAcceptanceTest::test_all_whitelisted_entities_support_the_allowed_create_and_update_contract`、`AiProposalApiTest::test_api_rejects_unknown_contract_timeline_financial_and_role_changes`、`AiProposalContract`／`AiProposalValidator` | Delete等は明示拒否 |
| DC-03 | Done | 旧／未知Contractで業務変更せず再提案 | `test_feature_flag_and_legacy_adapter_never_fall_back_to_unsafe_apply`、`test_scope_one_ui_reports_diff_success_conflict_and_processing_from_persisted_state`、旧形式警告UI | 旧Dataは削除せず閲覧保持 |
| DC-04 | Done | 全Writerの版更新と古い提案の上書き防止 | `test_same_second_human_edit_and_parent_collection_change_are_both_conflicts`、`test_soft_delete_and_restore_of_project_invalidates_an_existing_proposal`、`PlanVersionObserver`、Timeline／Restore／Project移動Writer対応 | Scope 1対象計画にImport writerは存在しない |
| DC-05 | Done | 版照合と更新を同一Transactionで実行し二重Apply防止 | `test_repeated_apply_converges_to_one_attempt_and_one_change`、独立2 PHP process同時開始リハーサル（変更1回、成功Attempt 1件、Result 1件）、`AiProposalScopeOneApplier` | Project集合Versionのため競合範囲は安全側に広い |
| DC-06 | Conditional | 他Tenant／Project ID・Temporary Reference拒否 | `test_cross_project_parent_and_tenant_tampering_are_rejected`、`test_key_cannot_create_proposal_in_another_workspace`、`AiProposalAuthorization`／`AiProjectContextGuard` | 共通Guardにより全経路を保護。Read／Approve／Undo等をA社/B社ごとに列挙した単一Matrix Testは未作成 |
| DC-07 | Conditional | 未許可Confidentialの名称・差分・履歴を非公開 | `test_client_role_cannot_read_confidential_proposal_or_ai_context`、`test_client_role_cannot_apply_even_if_legacy_permission_level_is_edit`、`test_project_metadata_requires_owner_while_an_edit_member_can_apply_an_action` | Proposal表示／AI Context／Applyは確認済み。Scope 1固有の検索・通知経路はなく、全Applicationの検索・通知を横断する専用Testは未実施 |
| DC-08 | Done | 提案・承認後の権限／所属失効を再評価 | `test_permission_is_rechecked_after_approval`、`test_user_and_tenant_memberships_are_rechecked_after_approval`、Undo失効Test | Apply Attempt作成前にも拒否することを確認 |
| DC-09 | Done | AI OFF／禁止Category／他ResourceをProvider前に拒否 | `test_ai_off_and_forbidden_category_block_context_before_proposal_creation`、`AiChatTest::test_forbidden_project_categories_are_not_sent_to_the_provider`、client／oversize provider前拒否Test | UI制御だけに依存しない |
| DC-10 | Conditional | 必要最小Context、上限、参照ID、Secret非残存 | `test_project_plan_context_enforces_entity_limit_and_omits_execution_fields`、`test_oversized_context_is_rejected_and_audited_before_provider_send`、`AiMcpToolService` | Auditはresource ID／category中心でraw contextを保存しない。全Log sinkを対象にした自動Secret scanは未実施 |
| DC-11 | Done | Approval内容・版固定、Applyとの分離 | `test_content_change_invalidates_approval_and_revision_clears_it`、`AiProposalApprover`、approval hash／actor／time columns | revisionで旧Approvalをclear |
| DC-12 | Done | 親子複数Itemの順序適用と実Entity追跡 | `AiProposalScopeOneTest::test_mixed_contract_is_approved_then_applied_atomically_with_results`、Item Resultの`applied_entity_public_id` | Project U＋Roadmap／Theme／Action Cの混合を確認 |
| DC-13 | Conditional | 中間失敗で全業務Data Rollback、失敗履歴保持 | `test_failed_middle_item_rolls_back_business_data_but_keeps_attempt_history`、invalid proposal拒否Test、故障注入用`beforeItemApply` seam | 故障注入とValidationは確認済み。実DB制約違反を意図的に起こす専用Testは未作成 |
| DC-14 | Done | 連打・再送・応答断で二重適用しない | `test_repeated_apply_converges_to_one_attempt_and_one_change`、`test_processing_attempt_recovery_and_commit_response_replay_do_not_double_apply`、2 processリハーサル | Idempotency Key＋Proposal row lockで収束 |
| DC-15 | Done | 一時障害だけRetry、履歴と最終状態一致 | `test_only_retryable_failure_creates_a_second_attempt`、processing recovery Test、Attempt error code／retryable／attempt number | conflict／permission／schemaは自動Retryしない |
| DC-16 | Done | 後続変更のないScope内Updateを復元 | `test_update_only_undo_succeeds_but_rejects_later_human_change`、`AiProposalUndoService` | 変更したFieldだけをBeforeへ復元し履歴を保存 |
| DC-17 | Done | Human変更・Create・権限変化時にUndoしない | `test_mixed_update_undo_restores_values_but_create_and_revoked_access_are_not_undoable`、`test_undo_detects_a_later_change_to_an_untouched_scope_field`、Undo UI文言 | 完全Undoとは表示しない |
| DC-18 | Conditional | 提案→確認／修正→適用、差分・件数・Link | `test_scope_one_ui_reports_diff_success_conflict_and_processing_from_persisted_state`、Proposal／Item Review Feature Tests、Blade UI | HTTP Feature Testで画面内容と遷移を確認。実ブラウザによる人手E2E証跡は未取得 |
| DC-19 | Conditional | 競合・失敗・応答断・処理中の再入場表示 | 同UI Test、processing recovery／commit response replay Test、永続Attemptからの表示 | 競合・処理中・Retry・再提案は確認。実ブラウザで通信を切断するE2Eは未実施 |
| DC-20 | Done | 既存Project関連Data・表示の回帰防止 | `php artisan test`: 333 tests / 2363 assertions、Project／Roadmap／Task／Schedule／Plan Version既存Test | asset source変更はなく、npm未実行は下記評価のとおり非Blocker |
| DC-21 | Done | 旧UI／API／MCP等から安全判定を迂回不可 | `test_feature_flag_and_legacy_adapter_never_fall_back_to_unsafe_apply`、API／MCP拒否Test、legacy `AiProposalApplier`の安全Applier委譲 | Scope外Contractは拒否 |
| DC-22 | Done | 追加Migration、再実行、切戻し、Data無損失 | 専用SQLite cloneで固定ID／Relation／主要値を適用前後比較、再実行、Rollback、再適用 | 通常local／本番DBは変更していない |
| DC-23 | Done | DC証跡、変更、Pending、保証範囲、次工程を引渡し | 本Final Close Report、Commit `15751f2`、全Test結果、Pending分類 | Conditionalを成功扱いせず明記。Ver.1公開Readyとは分離 |

## Scope超過・Data保全レビュー

- 旧`Improvement`／`Task`の物理Rename、Table移行は実施していない。
- Project Management、Financial、Member／Role等の業務仕様は拡張していない。
- 既存ID、Relation、履歴は保持する。
- 追加Migrationはadditiveであり、`down()`は追加物だけを戻す。
- Proposal Apply以外の既存WriterもVersion更新対象に含め、迂回更新によるstale見逃しを防いだ。
- AuthorizationをProject全体へ拡大せず、Scope 1操作に必要な既存権限だけを共通化した。
- Approval、Apply、Attempt、Item Resultを分離し、人の確認事実と実行結果を混同しない。

## Pending Decision一覧

### 次Scopeへ持ち越す非Blocker

| ID | 論点 | Scope 1の暫定判断 | 次Scopeで扱う条件 | Blocker |
|---|---|---|---|---|
| PD-01 | 将来Role体系と既存権限の対応 | 既存owner／admin／editを利用し、commentはApply不可 | Organization Role／Group／Project Roleの正本を確定するときに物理mappingを決める | No |
| PD-02 | Version粒度 | Project集合Version＋Entity Versionで安全側に競合検出 | 実利用で不要な競合が問題になった場合だけCollection Version分割を検討 | No |
| PD-04 | AI Context上限 | 500 Entity／100,000文字 | Usage／Latency／Provider制約の実測後に設定値を調整 | No |
| PD-06 | Entity名称移行 | 旧Improvement／TaskをAction Theme／ActionとしてAdapter利用 | Domain Migration ScopeでTable／Model／表示名の移行方式を決定 | No |
| PD-07 | Create Undo | 保証対象外 | Create取消が商品要件になった場合、DeleteではなくRelation／履歴を含む別Contractとして設計 | No |
| PD-08 | Conditional DCの追加証跡 | 中核安全性は確認済み | 全Tenant endpoint matrix、全Log secret scan、実DB constraint、browser E2Eは次のRelease hardening時に追加可 | No |
| PD-09 | Frontend build | Scope 1では非Blocker | Node.jsが既に用意されたCI／開発環境で次回Release前に実行 | No |

### Scope 1固有として終了する判断

| ID | 終了判断 | 根拠 |
|---|---|---|
| PD-03 | Retry最大3回／processing timeout 5分をScope 1初期値として採用 | 一時障害だけをRetryし、設定変更可能。Scope 1 Doneを妨げない |
| PD-05 | 旧Proposalは閲覧専用＋再提案とする | 期待Versionのない旧変更を安全にApplyできないため、自動変換しない |
| PD-10 | ApprovalとApplyは同一人物でも可能 | 新しい4-eyes制度をScope 1で導入せず、既存Project権限を再評価する仕様で確定 |
| PD-11 | UndoはUpdate-only | Create削除や業務状態の完全復元を保証しないことをUIと本報告に明記済み |
| PD-12 | `project-plan.v1` whitelist | Scope 1 Contractを後から拡張せず、新しいCapabilityは新Contractで扱う |

## 主な変更ファイル

実装Commitでは45 pathを変更・追加した。主要ファイルと責務は次のとおり。

| 区分 | 主なファイル | 責務 |
|---|---|---|
| Contract | `app/Services/AiProposalContract.php` | Version、Capability、risk、approval policy、許可Entity／Operation／Field、canonical hash |
| Proposal生成 | `app/Services/AiProposalFactory.php` | stable item ID、before／after、期待Version、依存関係、idempotency payload一致 |
| 検証 | `app/Services/AiProposalValidator.php` | whitelist、親子参照、順序、snapshot、Version、Scope外変更拒否 |
| 認可 | `app/Services/AiProposalAuthorization.php` | User／Org／Workspace／Project membership、view／edit、Confidential client境界 |
| Approval | `app/Services/AiProposalApprover.php` | Proposal row lock、Contract hash／Version固定、承認者・日時記録 |
| Apply | `app/Services/AiProposalScopeOneApplier.php` | 直列化、Transaction、再認可、stale確認、temp ref解決、Attempt／Result、retry／replay |
| Legacy adapter | `app/Services/AiProposalApplier.php` | 旧呼出しをScope 1安全Applierへ委譲し、unsafe fallbackを廃止 |
| Undo | `app/Services/AiProposalUndoService.php` | Update-only snapshot復元、再認可、後続変更検知、Undo履歴 |
| AI Context | `app/Services/AiProjectContextGuard.php`、`app/Services/AiMcpToolService.php` | Key identity、Tenant、Membership、AI ON、Category、Context最小化／上限 |
| Domain version | `app/Observers/PlanVersionObserver.php`、`app/Providers/AppServiceProvider.php` | Eloquent writerでEntity／Project集合Versionを単調増加 |
| Entity Model | `app/Models/Project.php`、`Roadmap.php`、`Improvement.php`、`Task.php` | `plan_version`、Relation／cast、Project soft-delete時Version更新 |
| Proposal Model | `app/Models/AiProposal.php`、`AiProposalItem.php` | Contract／Approval／snapshot／適用先情報と新Relation |
| 実行履歴Model | `app/Models/AiProposalApplyAttempt.php`、`AiProposalItemResult.php`、`AiProposalUndo.php` | Apply試行、Item単位結果、Undo結果の永続化 |
| Schema | `database/migrations/2026_09_19_000001_add_scope_one_contract_to_ai_proposals.php` | additive columns、Attempt／Result／Undo table、可逆`down()` |
| API／MCP | `app/Http/Controllers/Api/AiProposalController.php`、`AiProjectController.php`、`AiMcpController.php` | Guard済みContext参照、Proposal生成、idempotency、監査 |
| Web操作 | `app/Http/Controllers/Project/AiProposalController.php`、`AiProposalItemReviewController.php` | view／review／revision／approve／apply／undoの状態遷移と競合防止 |
| AI Chat | `app/Http/Controllers/Project/AiChatController.php` | Provider送信前Category／Context／client境界、監査、承認済みHandoff連携 |
| 既存Writer補強 | `ProjectController.php`、`TimelineScheduleController.php`、`ProjectPlanRestoreService.php` | Query bulk update／move／restoreでもVersionを確実に更新 |
| UI／Route | `resources/views/ai-proposals/show.blade.php`、`_scope-one-actions.blade.php`、`routes/web.php` | 3段階操作、差分、状態、結果Link、再提案、Update-only Undo endpoint |
| 設定 | `.env.example`、`config/services.php` | Apply feature flag、Context entity／character上限 |
| 中核受入Test | `AiProposalScopeOneTest.php`、`AiProposalScopeOneAcceptanceTest.php` | atomicity、競合、境界、retry、replay、Undo、UI、bypassを検証 |
| 回帰Test | `AiProposalApiTest.php`、`AiProposalFoundationTest.php`、`AiProposalItemReviewTest.php`、`AiChatTest.php`、`ProjectHandoffTest.php` | API／MCP／既存Proposal／Review／AI Context／Handoffの回帰確認 |

## Undo最終保証

- 対象は`project-plan.v1`で正常Applyされた、Createを一件も含まないUpdate-only Proposalである。
- 復元するのは各Itemの`after`に含まれ、Scope 1で実際に変更した説明Fieldだけである。
- Apply時の期待snapshot全体と現在値を比較するため、同じ対象の別Scope 1 Fieldが後から変更されてもUndoを拒否する。
- Undo実行時にUser、Organization、Workspace、Project Membership、操作権限、Tenant、Entityを再確認する。
- Undo全体をTransactionで処理し、Undo Record作成後の成功・失敗を`ai_proposal_undos`へ記録する。未適用Proposal、Createを含むProposal、開始前の権限拒否はRecord作成前に拒否される。
- Create、Delete、Status、期限、担当、実績、Role、Visibility、外部副作用、複数Proposal連鎖は保証しない。
- Undoは「完全な時間巻戻し」ではなく、条件付きField復元である。

## Migration・切戻し手順

Migrationは既存Tableへの追加Columnと新規Tableだけで構成される。既存のProject／Roadmap／Improvement／Task／Proposal／ItemのID、public ID、Relation、値を更新・削除・Renameしない。既存4 Entityの`plan_version`には初期値`1`が設定され、旧Proposalの新Contract項目は`null`のため閲覧専用となる。

適用前にDB backupを取得し、通常のRelease手順で次を実行する。

```powershell
C:\xampp\php\php.exe artisan migrate --force
```

切戻しが必要な場合は、Scope 1 ApplyをFeature Flagで停止し、新規操作を止め、DB backupを確定してからRelease全体の通常Rollback手順を使う。Migrationの`down()`は追加Table／Columnのみを削除し、既存Project計画Dataは削除しない。

ただし、Migration適用後に生成されたContract metadata、Approval、Attempt、Item Result、Undo履歴と`plan_version`は`down()`で失われる。このため、本番利用開始後のSchema rollbackは単なるコード切戻しとして実行せず、監査Dataのexport／archive、対応コードRelease、DB backup、復元確認を含む運用判断を必須とする。既存Data削除を伴う本番RollbackはScope 1では実行していない。

専用SQLite cloneでは、固定IDの既存Dataを用いて、適用、再実行、Rollback、再適用の前後で既存件数、ID、Relation、主要値が一致することを確認した。検証用DBは削除済みで、通常local DBと本番DBは変更していない。

## `npm run build`未実行の影響評価

判定は**Scope 1 Doneへの影響なし／Release前の環境検証へ持越し**とする。

- 実装Commit `15751f2`では`resources/js`、`resources/css`、`vite.config.js`、`package.json`、lock fileを変更していない。
- UI変更はserver-side Bladeと既存CSS class／inline styleだけで、新しいJavaScript bundle、npm package、Vite entry pointを追加していない。
- Proposal画面はHTTP Feature Testでrenderし、差分、Approval／Apply、成功、競合、processing、Undo文言を検証した。
- 全Laravel Test 333件／2363 assertionsは成功している。
- Node.jsがない現在環境へ、Close処理のためだけにRuntimeや依存を導入しない。

したがって未実行はScope 1機能の未完成を示さない。ただし、次回のRelease／CIではNode.jsが既に用意された標準環境で通常の`npm run build`を実行し、Application全体のasset buildを確認する。

## 次Scopeへの技術的注意事項

1. `project-plan.v1`のwhitelistを直接広げない。Scope外のEntity／Field／Operationは、新Capabilityまたは新Contract Versionとして設計する。
2. 新しいProposal writerは`AiProposalFactory`を通し、stable item ID、before／after、expected Entity／Project Version、canonical content hashを欠落させない。
3. Query Builderのbulk `update`／`delete`はEloquent Observerを発火しない。Project計画Writerを追加するときは、対象EntityとProject集合の`plan_version`更新を必ず追加し、Writer inventoryとTestを更新する。
4. Approval済みでもApply／Undo Transaction内でTenant、Membership、Permission、Contract hash、Versionを再確認する。Controllerの事前authorizeだけに依存しない。
5. 同じIdempotency Keyを異なるPayloadへ再利用しない。Factoryのcanonical比較とDB unique制約を維持する。
6. Attempt／Item Resultは実行証跡であり、Proposalの承認記録と統合しない。失敗履歴を業務Data TransactionのRollbackで消さない。
7. Create Undoを既存`AiProposalUndoService`へ安易に追加しない。参照中Entity、履歴、実績を含む取消Contractとして別途判断する。
8. AI ContextはRelationをloadする前にAI ON／Category／Tenantを検証する。raw context、Secret、権限外titleをAudit／Logへ保存しない。
9. 旧Improvement／Taskの物理Rename時も、Scope 1 Contractの外部`entity_type`互換、既存public ID、Proposal snapshot、Item Result linkを保つ。
10. Migration rollbackは新しい監査履歴を失う。利用開始後はFeature Flagによる停止とコード切戻しを優先し、Schema rollbackはData保全計画を伴う場合だけ実施する。
11. Applicationの日時基準は引き続き`Asia/Tokyo`とし、新しいAttempt／Approval／Undo表示でもJSTを維持する。
12. Git PushとDeployは別操作である。Commit `15751f2`はGitHubへPush済みだが、本番Deployはしていない。

## 次工程

Scope 1はFinal Closeとする。次Scopeの実装、Migration、本番Deployには進まない。次の明示的な依頼を受けた時点で、本報告の持越し事項と正本を再確認して開始する。
