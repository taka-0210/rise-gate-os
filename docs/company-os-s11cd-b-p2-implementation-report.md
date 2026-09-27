# Company OS｜S11 Companion Delta B｜B-P2 Implementation Report

- Verification date: 2026-09-28 JST
- Phase: `B-P2｜Shared Context / Proposal / Notification / One Shared CO Basic State`
- Decision input: `S11-CD-B-G01 = OPEN / 通過済み`、`B-P1 = DONE`
- Base commit: `1075cd01d4a7914f86c99836b9c787f7f9ac15ae`
- Development branch: `s11cd-b-shared-ai-conversation-p2`
- Implementation commit: `0ce019fb51666bf948e35e2dc7e33928ae429906`
- Evidence commit: this Report commit; immutable hash is recorded in the completion handoff
- P2 disposition: **DONE candidate / 人＋ChatGPT B-P2 Done Review待ち**
- P3: **未開始**

## 1｜正本・開始状態・保護境界

### 1.1 参照した正本

- `CompanyOS_v153_Shared_AI_Conversation_Product_Design_Fixed.pptx`
  - SHA-256: `D671511C1CF4083011BBBA9898A80B6D54D8492E8BFC3B573DB814EE096E7DF5`
- `CompanyOS_Ver1_要件仕様書_v048_Shared_AI_Conversation_Product_Design_Fixed.xlsx`
  - SHA-256: `AAF5C5DDBF0AA07F8937A447998EEA7170840263433D37ACDE376A9293002E4D`
- `CompanyOS_S11CD_B_Implementation_Preparation_v003.md`
- `CompanyOS_S11CD_B_Decisions_Pending_v003.md`
- `CompanyOS_S11CD_B_Codex_Instructions_v003.md`
- `docs/company-os-s11cd-b-p0-compatibility-readiness-report.md`
- `docs/company-os-s11cd-b-p1-implementation-report.md`
- Delta A Formal Close commit `64d1c3f1d2755ef3c6d44be3fc9e434ceca9be00`
- `docs/company-os-s11cd-a-p5-close-verification-report.md`
- Scope 11 Formal Closed ContractおよびC02 Corrective Evidence

P2では確定済みProduct Contractを変更せず、P1のShared principal / Participant / Purpose Revision / Human Writer基盤へadditiveに接続した。

### 1.2 非変更

- IR-1: 非変更
- Product Master: 非変更
- `master` branch: 非変更
- Production / Deploy: 未接続・未実施
- 通常local DB: 未使用・非変更
- Delta A Formal Closed Contract: 再Openなし・非変更
- Repository内のユーザー所有未追跡Master 2点: 非変更・非stage・非commit
- Release Pending 4区分: Open維持

## 2｜P2 Implementation

### 2.1 Shared Context intersection

- Shared AI処理は、Ownerだけではなく**全active Participant**のcurrent eligibilityを毎回検証する。
- Participantのmembership status / membership epoch / credential generationがstaleまたは失効している場合、対象者だけを黙って除外せず、Shared処理全体をfail-closedする。
- Source選択時に全active Participantへ同じresource identity / version / projection / Organization policy / Resource policyが成立することを確認する。
- Shared Source Revisionごとに、participant version、audience fingerprint、全員分のauthorization snapshotをimmutable sidecarとして保持する。
- Shared Sourceの再選択は新しいSource Revisionを作成し、過去回答のlineageを現在Revisionへ付け替えない。
- legacy / unknown lineage、欠落sidecar、audience変更、revision変更、projection変更は再利用せず、再選択を要求する。
- Context上限は既存Contractの20 Source / 20,000文字を再利用する。

### 2.2 explicit Shared CO Request

- Human Message保存とAI Requestを分離したP1 / Delta A Contractを維持した。
- Provider callは認証本人による明示CO Requestだけを起点とする。
- `operation_id + payload fingerprint`で同一Requestを収束させ、同じoperation IDの異なるactor / payload再利用を拒否する。
- Gatewayの各attempt直前callbackで、Organization AI Policy、全Participant、Purpose Revision、Source Revision、current authorizationを再評価する。
- Provider I/O中にDB transaction / row lockを保持しない。
- Provider response取得後、公開直前に同じrevision ID / opaque handle / audienceを再認可する。
- Provider処理中のParticipant失権、Policy変更、Source reselectでは回答を公開せず、request stateを`unavailable / discarded`へ遷移させる。
- CitationはProviderへ渡したopaque source handleの範囲だけを許可する。
- 過去AI回答を次turnへ入れる場合、その回答が持つimmutable Source Revisionを推移的lineageとして再認可する。

### 2.3 One Shared CO basic state

- Conversation単位のserver-authoritative stateを追加した。
- `request_id / response_id / state / phase / sequence / version`を1つの共有stateとして保持する。
- 状態は`idle`、`processing / provider_processing`、`answer_ready / published`、`unavailable / discarded`を実処理に基づいて表す。
- 同一Conversationでprocessing中の2件目Requestを拒否する。
- 同一operationの同時実行はrequest 1件、Provider attempt 1件、response 1件へ収束する。
- 認可済みShared画面は同じserver stateを表示し、端末別Provider callを生成しない。
- P3のSession / capture stream / ASR / Multi-device cursorは先行実装していない。

### 2.4 Shared Proposal

- 第二Proposal Engineや独自Unit Writerを追加せず、既存`AiCommonProposalFactory`、Approval、`AiCommonUnitAdapter`、Undoを再利用した。
- Proposal作成時に全active ParticipantのTarget read権限を確認する。
- actorと明示approval recipientのTarget write権限を確認する。
- participant version、audience fingerprint、Target snapshot fingerprint、approval recipientをimmutable sidecarへ保持する。
- approve / apply / undo時にもcurrent Participant / Permission / lineage / staleを再評価する。
- canonical hash、Field whitelist、Approval Level、stale、Atomic Apply、Idempotency、Undoの既存Contractを変更していない。
- apply replayは同じUnit resultへ収束し、Unit rowの重複生成を行わない。

### 2.5 S10 Notification接続

Shared Conversation由来の通知は次の3種だけを追加した。

1. Invitation
2. 明示Mention
3. Approval Request

- 全Message、AI response、Session開始等の通知は追加していない。
- 通知作成はConversation / Message / Proposalのtransaction完了後に行う。
- 既存S10 timing / Quiet Hours / delivery / dedupe / retry経路を再利用する。
- Center / delivery時にcurrent membership、credential、Participant、Conversation state、Proposal approval recipientを再認可する。
- Participant remove / leave、Invitation state変更、Proposal state変更後はsource authorizationがfail-closedする。
- notification本文へShared本文、Source内容、Proposal payloadを複製していない。

### 2.6 Private compatibility

- Private Conversationの`organization_id + user_id`本人認可、ID、Source semantics、Proposal hash、Human Writerを変更していない。
- Private ConversationをSharedへ変換していない。
- 既存Private dataからParticipant / author / audience / lineageを推測backfillしていない。
- Human PostだけではProvider call / CO Request / Context maintenance / Proposal生成を行わない。

## 3｜Migration

- Migration: `2026_09_28_000002_add_s11cd_b_p2_shared_ai_context.php`
- Repository migration total: **104**
- 新規table: **4**
  - `ai_common_shared_source_contexts`
  - `ai_common_shared_ai_requests`
  - `ai_common_shared_co_states`
  - `ai_common_shared_proposal_contexts`
- P2 FK: **16**
- unique / index:
  - Source Revision sidecar 1件制約
  - Conversation + operation ID / logical request ID
  - ConversationごとのOne Shared CO state 1件制約
  - Proposal sidecar 1件制約
  - Conversation / state / participant version / approval recipient検索index
- Migrationはadditiveのみ。
- 既存ID / Private意味変更、推測backfill、destructive data conversionはない。
- historyが存在する場合のrollbackはEvidence消失を避けるためfail-closedする。

## 4｜Focused Verification

`tests/Feature/S11CompanionDeltaBP2Test.php`

- Result: **8 PASS / 32 assertions**
- Pint後・最終test整理後に再実行し、同じ結果を確認した。

Focused scenario:

1. 全active Participant intersection / immutable Source Revision / One CO authoritative state / idempotent replay
2. Participant失権時にaudienceを縮小せずRequest全体を拒否
3. retry直前Policy再評価、およびresponse公開直前Membership再評価
4. unknown Shared lineage fail-closed
5. Provider I/O中reselect時に旧resultを破棄し、lineageを新Revisionへ付け替えない
6. Proposal作成時の全Participant Target read negative
7. 既存Proposal Engine / Approval / Unit Writer / apply replay収束
8. Invitation / Mention / Approvalの3種だけを通知し、remove後にcurrent authorizationで非公開

HTTP Evidenceでは、認可済みShared画面がOne Shared COのauthoritative stateを表示し、P3のContinuous ASRを公開していないことも確認した。

## 5｜Regression

隔離SQLiteで次を同一bundleとして実行した。

- B-P2
- B-P1
- Delta A P1〜P4
- Scope 11 / C02 F01〜F05

Result: **74 PASS / 392 assertions**

確認事項:

- C02 F01〜F05: PASS / Resolved維持
- Private Conversation / Private Source / Private Proposal: regression 0
- Delta A Human Writer / Attachment / Source / Citation / Voice / Transcript / Proposal: regression 0
- Scope 11 Proposal Engine / Gateway / Usage / Unit Writer: regression 0
- Human Message保存からのAI Request: 0
- P2由来の新規FAIL: 0

## 6｜SQLite Evidence

- 隔離temporary SQLiteのみ使用
- 全**104 Migration**適用: PASS
- P2 Migration rollback / reapply: PASS
- P2 table 4件: 存在確認PASS
- `PRAGMA foreign_key_check`: violation **0**
- integrity: **ok**
- temporary SQLite / synthetic data: Verification後削除済み
- 通常local DB: 未使用・非変更

## 7｜MariaDB 10.11 Evidence

- Version: **MariaDB 10.11.19**
- loopback専用instance `127.0.0.1:13359`
- dedicated temporary datadir / synthetic database `s11cd_b_p2`のみ使用
- Event Scheduler: OFF
- 全**104 Migration**適用: PASS
- P2 Migration rollback / reapply: PASS
- P2 table: **4**
- P2 FK: **16**
- P2 4 tableの`CHECK TABLE`: すべてOK
- P2＋B-P1＋Scope 11 / C02回帰: **47 PASS / 235 assertions**

### 7.1 Concurrency / idempotency

2 independent processから同じConversation / actor / operation ID / payloadのShared CO Requestを同時実行した。

- Shared AI Request: 1
- Assistant response: 1
- Provider attempt / Usage row: 1
- One Shared CO state: `answer_ready / published / sequence 1`
- duplicate / partial row: 0

片方のprocessは処理中の既存Requestを受け取り、別Provider callを開始しない。処理完了後は同じauthoritative state / responseを取得できる。

### 7.2 Cleanup

- synthetic database: 削除済み
- listener `127.0.0.1:13359`: 停止済み
- temporary datadir / marker / log: 削除済み
- 通常local MariaDB: 未使用

## 8｜Full Suite

Command: `php artisan test --compact`

- **614 PASS**
- **2 FAIL**
- **16 SKIP**
- **4,681 assertions**
- Duration: **242.70 seconds**
- P2由来Regression: **0**

### 8.1 既知FAIL分類

1. `CompanyNavigationTest` 1件
   - stale forbidden intended URLの既知baseline。
   - P2 Shared Context / Proposal / Notification / One CO差分と非接触。
2. `ReleaseHardeningTest` 1件
   - 固定済みIR-1 R0がMigration repository count `95`を期待し、現在RepositoryがP2追加後`104`である差。
   - P2 Migration不良ではなく、固定済みRCと後続Product Developmentとの差。
   - IR-1 Test / Manifest / RC / Artifactは変更していない。

FAILをPASSへ変更せず、期待値弱体化やSKIP追加も行っていない。

### 8.2 SKIP

16 SKIPは既存MariaDB RG02 approved isolated profile未指定による既知SKIP。MariaDB 10.11 Evidenceは別の承認済み隔離profileで取得した。SKIP追加・期待値変更はない。

## 9｜Compatibility C02〜C13への影響

| Compatibility | P2 disposition | Evidence / 引継ぎ |
| --- | --- | --- |
| B-C02 Private principal / Shared schema | **Resolved candidate維持** | P1のadditive Shared principalを利用。Private変換・backfillなし |
| B-C03 Source lineage | **P2対象 Resolved candidate** | 全Participant intersection、immutable revision sidecar、transitive lineage、unknown fail-closed、retry / publish再認可 |
| B-C04 Proposal snapshot | **P2対象 Resolved candidate** | 全員read、actor / recipient write、snapshot、既存Engine / Writer / stale / idempotency |
| B-C05 Notification authorization | **P2対象 Resolved candidate** | 3種限定、S10 timing / delivery再利用、current source authorization、dedupe |
| B-C06 Human Message / AI Request分離 | **Resolved candidate維持** | explicit CO RequestだけがProviderを呼び、Human Postは0 call |
| B-C07 Browser Mic / Codec / Playback | **Implementation Verification継続** | P2対象外。P3以降 / Close Evidenceへ引継ぎ |
| B-C08 Schema / Storage / Environment | **P2 schema Evidence追加** | additive 4 table、SQLite / MariaDB、rollback / reapply、通常local DB非変更 |
| B-C09 Long Context | **Implementation Verification継続** | P2はbounded history / Source上限のみ。P4 Long Contextを先行実装していない |
| B-C10 Gateway / Usage | **P2接続 Evidence追加** | attempt直前再認可、response-loss / retry、Provider call重複0。目的別長時間UsageはP3 / P4 |
| B-C11 Diarization | **Implementation Verification継続** | P3対象。先行実装なし |
| B-C12 Shared-room boundary | **P2 basic state Evidence追加** | Conversation-level authoritative CO stateを追加。Session / stream / multi-device cursorはP3以降 |
| B-C13 CO Presence / Atmosphere | **P2 basic state Evidence追加** | 実state / phaseを表示。audio / ASR / Presence / AtmosphereはP3 / P4 |

P2はP2対象のC03 / C04 / C05をResolved candidateとする。C02〜C13全体を一括PASSへは変更しない。

## 10｜Technical Pending更新

P2でEvidenceを追加した主なTechnical Pending:

- TP10: all-active Participant intersection / current authorization / audience fingerprint
- TP11: immutable Source Revision / transitive lineage / unknown fail-closed
- TP12: CO Requestの既存Gateway / Usage接続。ASR / maintenance等の目的別計量は後続
- TP13: Conversation単位のCO Request 1件制御
- TP14: operation ID / payload fingerprint / concurrent idempotency / publish再認可
- TP15: 3種Notification adapter / dedupe / current authorization
- TP24: One Shared COのserver-authoritative request / response / state / phase / sequence基礎

P3〜P5へ維持する主なOpen:

- Session / Consent / capture stream / ASR / Transcript / bounded anonymous Diarization
- Long Context / incremental maintenance / retrieval
- Multi-device cursor / reconnect / Shared-room実機
- CO Presence / Conversation AtmosphereのSession連携
- retention / cleanup / Browser / 390px / device / operational quality
- TP23 Distributed multi-mic acoustics / clock: **Later維持**

Technical Blocker: **0**

## 11｜Release Pending

次の4区分はOpenを維持し、P2 Evidenceで代替PASSにしていない。

1. B-RP01: retention / Legal / deletion / Provider retention / backup purge
2. B-RP02: 実ASR / Chat / Diarization Provider、Model、Price、Secret、実Provider smoke
3. B-RP03: 実host Storage、scanner / audio probe、resource limit、keys、backup / cleanup / restore
4. B-RP04: Scope 10 実SMTP Provider受信、Android Chrome実機PWA / Push / Deep Link

Scope 11 TP11 Knowledge / RAGもOUT-LATER維持。

## 12｜Product Decision / P3引継ぎ

- Product Decision追加: **不要**
- Product Pending / Product Blocker: **0 / 0**
- Compatibility Blocker: **0**
- Corrective Delta: **不要**
- Master update: **不要**

P3ではv003 Contractに従い、Session、Consent、単一shared-room capture stream、ASR、Transcript Revision、bounded anonymous Diarization、unsupported mode / second streamのserver拒否を実装・検証する。P2はこれらを先行実装していない。

## 13｜Git / 完了状態

- Branch: `s11cd-b-shared-ai-conversation-p2`
- Base: `1075cd01d4a7914f86c99836b9c787f7f9ac15ae`
- Implementation commit: `0ce019fb51666bf948e35e2dc7e33928ae429906`
- Evidence commit: this Report commit; exact hashはcompletion handoffへ記録
- origin: Evidence commit後に同branchへpushし、HEAD一致を確認する
- Working tree: push後にtask-related tracked差分0を確認する
- user-owned untracked Master 2点: 保持・非stage・非commit

## 14｜P2終了判定

| 項目 | 判定 |
| --- | --- |
| B-G01 | OPEN / 通過済み |
| B-P1 | DONE |
| B-P2 | **DONE candidate** |
| P2由来Regression | 0 |
| Compatibility Blocker | 0 |
| Product Decision追加 | 不要 |
| Release Pending | 4区分Open維持 |
| B-P3 | 未開始 |
| 次の停止地点 | 人＋ChatGPT B-P2 Done Review待ち |

**B-P2の実装・Verification・Evidence作成まで完了候補。B-P3へは進行しない。**
