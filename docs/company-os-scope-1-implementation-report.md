# Company OS Ver.1 Scope 1 実装・検証報告

- 実施日: 2026-09-19（JST）
- 対象Repository: `C:\xampp\htdocs\rise-gate-os`
- Branch: `master`
- 実装開始Commit: `3e5b6b3c613f5bc41892da6613fcc323306f95fc`
- 対象: 既存Project計画のAI Proposalを、人が確認して安全に承認・適用できる状態
- 非対象: 本番Deploy、Project新設、Delete、親移動、実績・担当・権限・Financial等の変更

## 結論

Scope 1のP0〜P5を実装し、受入条件をコードとTestで確認した。既存Proposal、既存Entity ID、Relation、履歴を保持したまま、Contract、楽観Lock、Approval、Apply Attempt、Item Result、限定Undo、AI Context境界を追加している。

既存Dataを削除する処理、既存ColumnのDrop/Rename、不可逆Migrationはない。旧Contract Proposalは閲覧できるが、そのままApplyはできず、再提案が必要である。

Scope 1完了はCompany OS Ver.1全体の公開準備完了を意味しない。後続ScopeのDomain整理、権限定義、運用決定は別途必要である。

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
| Action Theme | `Improvement` | Create / Update | `title`, `description` |
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

- `php artisan test`: **333 passed / 2363 assertions**
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

## DC-01〜23 受入証跡

| DC | 確認内容 | 主な証跡 |
|---|---|---|
| DC-01 | P0現状固定 | 本報告P0、Baseline test |
| DC-02 | 許可Scopeのみ | Contract whitelist、全許可C/U Test |
| DC-03 | 旧／未知Contract | 閲覧可能、Apply拒否、再提案UI Test |
| DC-04 | stale／親集合 | same-second、parent collection、soft-delete Test |
| DC-05 | Transaction／同時実行 | rollback Test、独立2プロセス同時Apply実験 |
| DC-06 | Tenant／temp ref | cross-project parent・tenant tamper Test |
| DC-07 | Confidential | client read／MCP／apply deny Test |
| DC-08 | Apply時再認可 | user・organization・workspace・project membership失効Test |
| DC-09 | AI OFF／Category | Provider前拒否、別resource検証Test |
| DC-10 | Context最小化 | entity／文字上限、ID、Scope外Field・Secret非送信Test |
| DC-11 | Approval invalidation | content hash変更、revision clear Test |
| DC-12 | Mixed result | update/create混在成功とItem Result Test |
| DC-13 | 中間失敗 | 業務Data rollback、Attempt履歴保持Test |
| DC-14 | Replay | 連打、同時実行、commit-response replay Test |
| DC-15 | Retry | retryable分類、上限、processing recovery Test |
| DC-16 | Update Undo | snapshot復元Test |
| DC-17 | Undo限界 | create、Human change、permission失効拒否Test |
| DC-18 | UX三段階 | Proposal画面Feature Test |
| DC-19 | Failure UX | conflict／processing／retry／reproposal Test |
| DC-20 | 回帰 | 全333 tests成功 |
| DC-21 | Bypass禁止 | legacy applier／feature flag Test |
| DC-22 | Migration | clone適用・再実行・rollback・再適用 |
| DC-23 | 最終証跡 | 本報告、Test結果、Git履歴 |

## Scope超過・Data保全レビュー

- 旧`Improvement`／`Task`の物理Rename、Table移行は実施していない。
- Project Management、Financial、Member／Role等の業務仕様は拡張していない。
- 既存ID、Relation、履歴は保持する。
- 追加Migrationはadditiveであり、`down()`は追加物だけを戻す。
- Proposal Apply以外の既存WriterもVersion更新対象に含め、迂回更新によるstale見逃しを防いだ。
- AuthorizationをProject全体へ拡大せず、Scope 1操作に必要な既存権限だけを共通化した。
- Approval、Apply、Attempt、Item Resultを分離し、人の確認事実と実行結果を混同しない。

## Pending Decision一覧

| ID | 論点 | 暫定判断 | 理由／影響 | Blocker |
|---|---|---|---|---|
| PD-01 | 将来Role体系と既存権限の対応 | 既存owner／admin／editを利用。commentはApply不可 | 全社権限制度をScope 1で新設しない | No |
| PD-02 | Version粒度 | Project集合Version＋Entity Version | 安全側で親集合の変更を検出 | No |
| PD-03 | Retry／Timeout | 最大3回／5分 | 技術的な安全初期値 | No |
| PD-04 | AI Context上限 | 500 Entity／100,000文字 | 漏えい・過大Payload防止。設定変更可 | No |
| PD-05 | 旧Proposal | 閲覧専用＋再提案 | 期待Versionのない変更を安全にApplyできない | No |
| PD-06 | Entity名称移行 | 旧Improvement／Taskを継続利用 | Action Theme／Actionへの物理移行は後続Migration Scope | No |
| PD-07 | Create Undo | 保証外 | 自動削除は既存Data／Relationへ不可逆影響を生み得る | No |

## 主な変更ファイル

### Contract・認可・適用

- `app/Services/AiProposalContract.php`
- `app/Services/AiProposalFactory.php`
- `app/Services/AiProposalValidator.php`
- `app/Services/AiProposalAuthorization.php`
- `app/Services/AiProposalApprover.php`
- `app/Services/AiProposalScopeOneApplier.php`
- `app/Services/AiProposalUndoService.php`
- `app/Services/AiProjectContextGuard.php`

### 永続化・Version

- `database/migrations/2026_09_19_000001_add_scope_one_contract_to_ai_proposals.php`
- `app/Models/AiProposalApplyAttempt.php`
- `app/Models/AiProposalItemResult.php`
- `app/Models/AiProposalUndo.php`
- `app/Observers/PlanVersionObserver.php`

### UI・Route

- `resources/views/ai-proposals/show.blade.php`
- `resources/views/ai-proposals/_scope-one-actions.blade.php`
- `routes/web.php`

### 受入Test

- `tests/Feature/AiProposalScopeOneTest.php`
- `tests/Feature/AiProposalScopeOneAcceptanceTest.php`
- `tests/Feature/AiProposalApiTest.php`
- `tests/Feature/AiChatTest.php`
- `tests/Feature/ProjectHandoffTest.php`

## Migration・切戻し手順

適用前にDB backupを取得し、通常のRelease手順で次を実行する。

```powershell
C:\xampp\php\php.exe artisan migrate --force
```

切戻しが必要な場合は、Scope 1の利用を停止し、Scope 1で作成されたProposal／Attemptが運用上不要であることを確認した上で、Release全体の通常Rollback手順を使う。Migrationの`down()`は追加Table／Columnのみを削除し、既存Project計画Dataは削除しない。ただし追加されたScope 1監査履歴はRollbackで失われるため、本番でのMigration rollbackは運用判断とbackupを必須とする。

## 次工程

Scope 1のDone条件は満たした。次工程では、Scope 1を拡張せず、正本に従ってM2のScope整理とPending Decisionの確定を行う。本報告時点では本番Deployを行わない。
