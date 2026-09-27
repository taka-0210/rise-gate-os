# Company OS｜Scope 11「AI共通入口 / AI Common Entry」Implementation Report

## 1. 判定

- 実施日: 2026-09-27 JST
- Development Line: `scope11-ai-common-entry`
- Base: `s10cd-tqc@9ffed507be96fe858751dc981cc0a1332b1ed4e1`
- P0 Final Review: 承認済み
- S11-C01 / C02 / C03: Resolved
- S11-G01: OPEN / 通過済み
- P1〜P5: 実装・隔離検証完了
- Product判断Blocker: 0
- 終了状態: **Code Complete / Formal Close候補**
- Formal Close: 未実施（人＋ChatGPT Final Review待ち）
- S11-G03: 未実施

Scope 1〜10およびCapture UnitのClosed Contractを変更していない。第二Proposal Engine、Unit Writer迂回、権限・privacy緩和、旧Data推測変換、旧Conversation強制移行は行っていない。

## 2. 正本・開始状態

P0で照合したv146 / v042およびv002三成果物を正本とした。正本SHA-256はP0 Reportの値を継承する。開始時点はCapture Unit Formal Close済み、origin一致、working tree cleanだった。IR-1は `master@924af91188cc60d33ff87c91b94ecc1d539566e6` の固定Evidenceとして分離した。

実装はCapture Formal Close commitから独立branchを作成した。通常local DB、Production、Production DB、Deploy、IR-1 RC / Artifact / Manifest / Test / Gate / Track A・Bは使用・変更していない。

## 3. P1〜P5結果

| Phase | 結果 | 主な成果 |
|---|---|---|
| S11-P1 | Done | Common principal、additive schema、Gateway、Usage Ledger、既存Proposal dispatch、新Unit Adapter骨格 |
| S11-P2 | Done | Private Conversation、AI Policy、Context選択、Source/Citation、再認可、Degraded UX |
| S11-P3 | Done | Capture L1、Action L2、Project L2、Business Domain L3を既存Writerへ接続 |
| S11-P4 | Done | 既存AI境界維持、bounded retry、usage観測、Desktop/390px UI |
| S11-P5 | Done | Focused・陰性・full suite・SQLite/MariaDB・並列・Browser・build Evidence |

## 4. Architecture

書込経路は `Existing Proposal orchestration -> AiCommonUnitAdapter -> Existing Unit Writer` の一系統である。

- 既存Factory / Validator / Approver / Applier / Undo / Attempt / Item Result / canonical hash / expected versionを再利用する。
- Scope 11 contractだけを明示dispatchし、旧 `project-plan.v1` / `project-action.v1` の意味と結果を維持する。
- 1 Proposal = 1 Unit = 1 operation。server-side whitelist外fieldは拒否する。
- Conversation→Proposal→Unitはprivate provenance relationで追跡し、Unit閲覧権から元Conversation本文を逆公開しない。
- Apply/Undo時にcurrent permission、membership、tenant、versionを再評価する。

## 5. Schema / Migration

additive Migration `2026_09_27_000001_add_scope_eleven_ai_common_entry.php` を追加した。

- `organization_ai_policies` / `ai_resource_policies`
- `ai_common_conversations` / `ai_common_messages`
- `ai_common_sources` / `ai_common_message_sources`
- `ai_usage_ledgers` / `ai_common_handoff_relations`
- `ai_proposals`へのCommon Entry scope列・明示index/FK

既存rowを変換せず、既存Project Conversationも移行しない。ダミーProject / Workspaceは作らない。SQLiteとMariaDB 10.11のFK/index/unique、全97 Migration、Scope 11 rollback/reapplyを実測した。

## 6. Conversation / Context Authorization / Source

- Common ConversationはOrganization内の本人専用。別member・別tenant・inactive userをfail-closedする。
- Organization AI Policyはdefault OFFでOwnerのみ管理可能。Workspace AI Policy、resource単位Policy、current read permissionをProvider呼出前に確認する。
- Captureはcreator/recipient当事者だけを許可する。Project/Action/Domainは既存Access/Guardを再利用する。
- Source Manifestは選択済みprojectionだけを保持し、20 source、各2,000文字、合計20,000文字等の上限を適用する。
- freshness fingerprintにresource version、membership access epoch、credential generation、Org/Resource Policy versionを含める。
- Citationは選択Source handleとの一致を検証し、架空Citationを拒否する。失権・更新後は回答本文、次turn再投入、Deep Linkを停止する。
- 旧Conversationや出典なしmessageへ推測Citationをbackfillしない。

## 7. Gateway / Provider / Usage Ledger

- purposeは `business_common` 固定。現行adapterはOpenAI 1系統のみで、Cross-provider fallbackは行わない。
- Provider DTOへ渡すのは認可済みmessageと選択済みprojectionだけ。Development file/build/preview/deploy toolはない。
- retryは最大2 attempt。各attemptを別Ledger rowとして記録し、invalid responseはretryしない。
- LedgerはOrg/User/Conversation/request/purpose/provider/model/attempt/token/cost version/currency/result/safe error/latencyを保持する。
- unknown usageは `null` とし0へ偽装しない。raw Context・会話本文をLedger/Auditへ複製しない。
- 実Provider、実Secret、実料金、実送信は未使用で、Release Verificationへ残す。

## 8. Proposal Engine / Unit Adapter

| Unit | Level | Field / operation境界 | Writer |
|---|---:|---|---|
| Capture | L1 | self/request/tell_later、本文、1 recipient、JST timing。曖昧recipient/時刻は拒否 | `CaptureWriter` |
| Action | L2 | createと限定update。Assignee/Reviewer資格、due date理由、Review差戻し副作用 | `ProjectExecutionWriter` |
| Project | L2 | existing Projectの `purpose` / `expected_outcome` updateだけ | `ProjectExecutionWriter` |
| Business Domain | L3 | active Domainの確定7本文field、reason、Context影響確認 | `BusinessDomainWriter` |

Account/Role/Permission/Owner/Membership/AI Policy/Execution/state/通知直接送信、Capture ack/close/cancel/promote、新Project作成等はserver-sideで拒否する。

## 9. Atomicity / Idempotency / Undo

- Proposal/Attempt/Item Result/Unit Writer side effectは同一outer transactionで処理する。
- operation IDはdeterministic UUID。`(proposal_id, idempotency_key)` uniqueとrow lockで同時Apply/response-lossをexactly-once化する。
- 2 workerが同一Capture proposalを同時ApplyしてもCapture/Event/Notification/Attempt/Item Resultは各1件だった。
- Writer後の人工例外でUnit、History、Notification、Proposal状態がすべてrollbackされることを確認した。
- stale expected version、同key異内容、field smuggling、current permission lossは部分反映なしで拒否する。
- Undoはupdate-only。current permissionと適用後versionを再確認し、既存Writer経由で復元する。create取消は追加していない。

## 10. Existing AI compatibility / Failure mode

- 既存Project AI Chat、`project-plan.v1`、`project-action.v1`、S8実行計画、S9 Action Draft 4-field payload、Development AI、Image AI、MCP/AI Access Keyを維持した。
- 旧Conversationを移行せず、既存transportのTool/本文Contractを変更していない。
- Policy OFF、permission loss、provider failure/timeout/invalid response、Context上限、stale、Apply失敗はfail-closed。入力・未適用Proposalは保存事実と区別する。
- 手動Project/Action/Today/Capture/Notificationの正常経路はfocused regressionで維持した。

## 11. Verification Evidence

### SQLite / regression / build

| Verification | 結果 |
|---|---|
| Scope 11 focused（最終） | **18 PASS / 0 FAIL / 82 assertions** |
| Proposal/Project AI/S8/S9/S10/Capture focused regression | **149 PASS / 0 FAIL / 949 assertions** |
| shared transport後 Project Chat/Image + S9 | **41 PASS / 0 FAIL / 316 assertions** |
| Full SQLite suite | **556 PASS / 2 FAIL / 16 SKIP / 4,348 assertions** |
| Production frontend build | PASS（58 modules、manifest/assets生成） |
| SQLite Migration | 全97適用、Scope 11 rollback/reapply PASS |

Full suiteの2 FAILは新規回帰ではない。

1. `CompanyNavigationTest` 1件: Capture Formal Close時点から存在する既知baseline（company期待に対しsystem-admin URL）。
2. `ReleaseHardeningTest` 1件: IR-1固定RCのMigration 95件に対し、Formal Close済みCapture追加後とScope 11 additive MigrationによりRepository現在値が97となった差。

IR-1のTest / Manifest / RC / Artifactを97へ更新せず、SKIP追加・期待値弱体化・PASS偽装も行っていない。16 SKIPは既存MariaDB RG02専用profileでありPASSへ数えていない。

### MariaDB 10.11 / concurrency

- official MariaDB 10.11.19、専用datadir、`127.0.0.1:13339`、専用DBで実施。
- 全97 Migration、FK/unique/index、utf8mb4、rollback/reapply: PASS。
- Scope 11 focused: **17 PASS / 67 assertions**。
- 2 worker同時Apply: 両worker applied、Capture 1 / Event 1 / Notification 1 / Attempt 1 / applied Attempt 1 / failed 0 / Item Result 1。
- response-loss想定の再実行後も同じ件数。partial applyと重複通知なし。
- clean template由来のMariaDB system-table version warningは出たが、application schema / migration / test / concurrency結果には影響なし。
- 専用server、datadir、DB、barrier/logを停止・削除し、port 13339 listenerなしを確認した。

### Browser / 390px

実Chrome headlessでDesktopと390x844を実行した。

- OwnerだけがPolicyを設定可能。
- Common Entry、Private provenance、Context選択、Proposal境界を表示。
- 別memberによるConversation URLは403。
- 390px horizontal overflowなし、textarea focus成功。
- 実Provider呼出なし。
- screenshots SHA-256: Desktop `C7535C0BDC2DC66FD799C98592FF8CAC05E25F24F33930B768C160067DB8E509`、390px `9399D353F49A7E317A19E627821792286842B04911C4FA8DD21F68F31D41C4F7`。
- 一時Node/SQLite/server/screenshotsを削除し、port 8781 listenerなしを確認した。

## 12. S11-DC01〜20最終判定

P5のDC照合でDC03のArchiveがschemaだけでroute/UI未接続だったことを検出した。確定Contract内の最小Corrective Deltaとして、本人専用Archive、JST `archived_at`、active/archived一覧分離、履歴read、Archive後のmessage/source/proposal/approve/apply/undo拒否を実装した。別member 403、本人Archive、JST、read-only、Business Data非追加をfocused testで再現→修正→PASSした。Product Contract変更はない。

| DC | 判定 | Evidence要約 |
|---|---|---|
| DC01 正本・Decision・独立Line | Done | v002/P0を継承し独立branch、IR-1分離 |
| DC02 移行互換 | Done | additive DDL、既存row/ID/value/relation変換なし |
| DC03 本人用Common Entry | Done | Project不要、本人private、Archive、390px、403/tenant陰性 |
| DC04 送信前Context認可 | Done | default OFF、Owner管理、Org/WS/Resource/current permission/epoch |
| DC05 Relevance/上限/正本 | Done | 明示選択projection、件数/文字上限、現在値のみ |
| DC06 Citation/派生履歴 | Done | handle検証、架空Citation拒否、失権後非表示・非再投入 |
| DC07 既存Engine再利用 | Done | 単一Engine dispatch、旧contract exact regression |
| DC08 Capture L1 | Done | 現資格、JST timing、operation UUID、Writer、通知重複なし |
| DC09 Action L2 | Done | whitelist、資格、due理由、review return、既存Writer |
| DC10 Project L2 | Done | purpose/expected_outcome限定、version/permission |
| DC11 Domain L3 | Done | active 7 field、reason/impact、Revision/Audit/Writer |
| DC12 禁止Apply/未確定入力 | Done | field smuggling、曖昧recipient、禁止operation拒否 |
| DC13 Approval UX | Done | L1/L2/L3 mapping、ApprovalとApply分離、状態表示 |
| DC14 stale/Atomic/Idempotency | Done | rollback、2-worker、response-loss、exactly-once |
| DC15 Update-only Undo | Done | current permission/version、Writer復元、idempotent |
| DC16 Private Handoff | Done | relation追跡、元Conversation非公開 |
| DC17 Gateway/Usage | Done | 1 adapter、bounded retry、attempt ledger、unknown null |
| DC18 Failure/Degraded | Done | fail-closed、入力保持、手動経路regression |
| DC19 既存AI経路 | Done | S9 exact payload、Project/Development/Image/MCP維持 |
| DC20 検証・Close/Release分離 | Done | isolated SQLite/MariaDB/Browser/build、IR-1非変更 |

- Scope Development Conditional: **0**
- Scope Development Not Done: **0**
- Product判断Blocker: **0**
- 新規説明不能FAIL: **0**

## 13. S11-TP01〜11現在状態

| TP | 状態 | 結果 / 引継ぎ |
|---|---|---|
| TP01 schema binding | Resolved | additive contract、FK/index/SQLite/MariaDB実測 |
| TP02 lock/idempotency | Resolved | lock順、outer transaction、2-worker/response-loss |
| TP03 Context budget | Resolved for Scope Development | 20 source・各/合計文字・message/output上限を実装。実Model値はTP10 |
| TP04 Source graph | Resolved | 失権時の派生回答非表示・非再投入、旧message自動投入なし |
| TP05 usage normalization | Resolved for Scope Development | unknown null、attempt、price version、safe error。実単価はTP10 |
| TP06 private relation | Resolved | private Conversation→Proposal→target、権限非伝搬 |
| TP07 existing transport | Resolved | purpose固定shared transportへ薄く接続。各serializer/tool/既存usage保存先は不変、二重Ledgerなし |
| TP08 baseline差分 | Resolved | Full suite 2既知FAILを分離、IR-1固定95を変更せず |
| TP09 retention/legal | **Release Verification Open** | 顧客向け保持年限・消去・Legal条件をRelease Ready前に確定 |
| TP10 real provider | **Release Verification Open** | 実Provider/model/price/Secret/data handling/Smokeを承認済みStagingで確認 |
| TP11 Knowledge/RAG | **OUT-LATER** | Official Summary/Knowledge/RAGは後続Gate。Scope 11 blockerではない |

## 14. Gates / Release separation

- S11-C01 / C02 / C03: **Resolved**
- S11-G01｜P1開始Gate: **OPEN / 通過済み**
- S11-G02｜Handoff接続Gate: **OPEN候補**。既存Contract回帰、current permission、Writer、whitelist、stale、Atomic、Idempotency、response-loss、途中失敗、private provenance、通知重複なしのEvidenceが揃った。Codex自身ではRelease Ready/Production公開扱いにしない。
- S11-G03｜Formal Close: **未実施 / 自動OPENしない**
- Scope 12 / Production / Deploy: 未着手

Scope 10 Release Verification Open Evidenceは未取得のまま維持する。

1. 実SMTP ProviderによるEmail実受信
2. Android Chrome実機 PWA Install / Push / Deep Link

Scope 11 Evidenceで代替PASSにしていない。

## 15. Master Update判定

**Master v146 / v042への同期は必要**と判定する。

理由は、MasterがScope 11の設計骨格を持つ一方、正式確定したDE01〜13、Capability Matrix、4 UnitのField境界、L1/L2/L3、Organization AI Policy、Source/Citation、Usage Ledger、Provider routing、Ver.1 IN/OUT、および実装結果の詳細はv002と本Reportにあるためである。

これはProduct Contract変更要求ではなく、承認済みContractと実装EvidenceのMaster同期である。本作業ではMasterを更新していない。人＋ChatGPT Final Review後の明示指示で扱う。

## 16. Git / change boundary

Implementation commits:

- `54527906087c00909e4eff34bb0549c078f65ac6` — AI Common Entry foundation
- `2b0de11` — authorization、handoff、Undo、concurrency/response-loss Evidence
- `04c7211` — purpose-bound shared AI transport、既存payload/usage互換
- `f9f43e4` — DC03 private Conversation Archive Corrective Delta

Evidence Reportは本Codeとは別commitにする。Scope 11関連fileだけをstageし、同branchへPushする。

IR-1のRC、Artifact、Manifest、Test、Gate、Track A/B、Migration 95件Evidenceは非変更。通常local DB、Production、Production DB、Production Credential、Deployも非使用・非変更。

## 17. 最終状態

| 項目 | 判定 |
|---|---|
| S11-P1〜P5 | Done |
| S11-DC01〜20 | Done |
| Conditional / Not Done | 0 / 0 |
| Product Blocker | 0 |
| C01 / C02 / C03 | Resolved / Resolved / Resolved |
| G01 | OPEN / 通過済み |
| G02 | OPEN候補 |
| G03 | 未実施 |
| Master Update | 必要（未実施、別明示指示待ち） |
| Formal Close | **可能候補**。Code Complete。人＋ChatGPT Final Review待ち |

本ReportでFormal Close、G03 OPEN、Scope 12、Release Ready、Production、Deployへは進めない。
