# Company OS｜S11 Companion Delta B｜B-P1 Implementation Report

- Verification date: 2026-09-28 JST
- Phase: `B-P1｜Shared Conversation Foundation`
- Decision input: `S11-CD-B-G01 = OPEN / 通過`
- Base commit: `904c49782560a34d09d11526d58d0d0ecdf897b9`
- Development branch: `s11cd-b-shared-ai-conversation-p1`
- Implementation commit: `acfb2c731f53a8db2e01fc8f0fb316a54572d7ea`
- Evidence commit: this Report commit; the immutable hash is recorded in the completion handoff
- P1 disposition: **DONE candidate / Human＋ChatGPT B-P1 Done Review待ち**
- P2: **未開始**

## 1｜正本と保護境界

### 1.1 参照した正本

- `CompanyOS_v153_Shared_AI_Conversation_Product_Design_Fixed.pptx`
- `CompanyOS_Ver1_要件仕様書_v048_Shared_AI_Conversation_Product_Design_Fixed.xlsx`
- `CompanyOS_S11CD_B_Implementation_Preparation_v003.md`
- `CompanyOS_S11CD_B_Decisions_Pending_v003.md`
- `CompanyOS_S11CD_B_Codex_Instructions_v003.md`
- `docs/company-os-s11cd-b-p0-compatibility-readiness-report.md`
- Delta A Formal Close commit `64d1c3f1d2755ef3c6d44be3fc9e434ceca9be00`
- `docs/company-os-s11cd-a-p5-close-verification-report.md`

Product Decisionの再解釈・拡張は行わず、v003とP0 Evidenceで定義されたP1範囲だけを実装した。

### 1.2 非変更

- IR-1：非変更
- Product Master：非変更
- `master` branch：非変更
- Production / Deploy：未接続・未実施
- 通常local DB：未使用・非変更
- Delta A Formal Closed Contract：再Openなし・非変更
- Repository内の未追跡Master 2点：変更・stage・commitなし
- Release Pending 4区分：Open維持

## 2｜P1実装結果

### 2.1 Shared / Private分離

- `ai_common_conversations.conversation_kind`をadditiveに追加し、既定値を`private`とした。
- 既存Private query / authorizationは`private`を明示し、既存ID・本人認可・Source semantics・Proposal hashを維持した。
- 既存Private rowをSharedへ変換していない。
- 既存DataからParticipant / authorを推測backfillしていない。
- Sharedは専用sidecar model / access / writer / controller / viewとして追加した。

### 2.2 Conversation / Owner / Participant

- Shared ConversationのName、current Purpose Revision、Owner、Participant、Invitationを実装した。
- Ownerは作成時にaccepted Participantとして1名だけ生成する。
- Participant上限はOwner込み10名。
- Invitationは同一Organizationかつcurrent eligibilityを満たすUserだけに発行できる。
- 未acceptのUserはShared本文を参照できない。
- Organization Owner / Admin / System Adminであっても、accepted ParticipantでなければShared本文を参照できない。
- suspend / left / global inactive / stale membership epoch / stale credential generationはfail-closedとした。
- remove / leave / archiveを実装し、Owner自身のleave / removeを禁止した。
- Owner移管は明示requestとsuccessor acceptの2段階とし、移管前OwnerをParticipantとして維持した。

### 2.3 Name / Purpose Revision

- Name / PurposeはShared Conversation作成時に必須。
- Purpose変更ごとにimmutable revisionを追加し、過去revisionを書き換えない。
- current pointerは最新revisionを指す。
- P1ではSessionを作成せず、P3以降が当時のPurposeをsnapshotできるadditive境界だけを用意した。

### 2.4 Shared Human Message / author principal

- Delta AのHuman Message WriterへShared用entry pointを追加した。
- Shared Human Postはaccepted Participantのcurrent authorizationをserver側で再評価する。
- Message本文とは別にimmutable author/principal sidecarを保存し、User、Participant、membership epoch、credential generation、audience epochを固定する。
- 同一operation ID / payloadは同一Messageへ収束する。
- Human Message保存によるProvider call、CO Request、Context Maintenance、Proposal生成は0。
- Organization AI PolicyがOFFでもHuman Conversation / Human Postは成立する。
- Shared AttachmentはP1で先行有効化していない。

### 2.5 P1で先行しなかったCapability

以下はschema上の将来接続可能性とCapability実装を分離し、P1では未実装・未PASSである。

- Shared AI Context intersection
- Shared Proposal本接続
- S10 Notification本接続
- Session / lease / coordinator
- Continuous ASR / bounded anonymous Diarization
- Rolling Context / Historical Retrieval
- CO Presence / Conversation Atmosphere
- Multi-device View / shared-room stream
- Session-end整理

## 3｜Migration

- Migration: `2026_09_28_000001_add_s11cd_b_p1_shared_conversation_foundation.php`
- Repository migration total: **103**
- 既存table変更: `ai_common_conversations`へ`conversation_kind`とindexをadditive追加
- 新規table: **5**
  - `ai_common_shared_conversations`
  - `ai_common_shared_purpose_revisions`
  - `ai_common_shared_participants`
  - `ai_common_shared_message_authors`
  - `ai_common_shared_operations`
- destructive migration: なし
- Private→Shared変換: なし
- 推測backfill: なし
- SQLite / MariaDB双方のidentifier制約に合わせ、FK名は明示的な短縮名を使用した。
- rollbackはShared historyが存在する場合に拒否し、Evidence消失を防ぐ。空状態でのrollback / reapplyを実測した。

## 4｜Focused Verification

`tests/Feature/S11CompanionDeltaBP1Test.php`

- 結果: **7 PASS / 33 assertions**
- Shared作成、operation idempotency、AI OFFでのHuman Conversation
- Invitation / acceptance、accept前の本文非公開
- same Organization / inactive / suspended / left / cross-Organization negative
- non-ParticipantのOrg Owner / Admin / System Admin access拒否
- Shared author principal、Human Post idempotency
- Provider call 0 / Usage 0 / Proposal 0
- remove / leave / Owner protection / explicit Owner transfer
- immutable Purpose Revision / archive後の変更拒否
- Owner込み10名上限

Pint後に同Focused Testを再実行し、同じ`7 PASS / 33 assertions`を確認した。

## 5｜Regression

SQLiteで次を同一bundleとして実行した。

- B-P1
- Delta A P1〜P4
- Scope 11 / C02 F01〜F05

結果: **66 PASS / 363 assertions**

確認事項:

- Private Conversation本人認可を維持
- Delta A Attachment / Human Writer / Voice / Extraction / Source / Citation / Proposal接続を維持
- Scope 11 Proposal Engine / Gateway / Unit Writer contractを維持
- C02 F01〜F05は全PASS / Resolved維持
- Human PostからAI Requestが発生しない

## 6｜SQLite Evidence

- 隔離temporary SQLiteのみ使用
- 全**103 Migration**適用: PASS
- P1 Migration rollback / reapply: PASS
- `PRAGMA foreign_key_check`: violation **0**
- integrity: PASS
- temporary SQLite / synthetic data: Verification後削除済み
- 通常local DB: 未使用・非変更

## 7｜MariaDB 10.11 Evidence

- Version: **MariaDB 10.11.19**
- loopback隔離instance / 専用datadir / synthetic database `s11cd_b_p1`のみ使用
- 全**103 Migration**適用: PASS
- P1 Migration rollback / reapply: PASS
- P1 table: **5**
- P1 FK: **18**
- `CHECK TABLE`: `ai_common_conversations`およびP1 5 tableすべてOK
- SQLiteと同じFocused＋Regression bundle: **66 PASS / 363 assertions**
- listener、database、datadir、archive、log: Verification後停止・削除済み

### 7.1 Concurrency / idempotency

独立2 processから同じShared Conversation create operationを同時実行した。

- 両processの返却Conversation ID: 同一
- Conversation: 1
- Shared sidecar: 1
- Owner Participant: 1
- Purpose Revision: 1
- Operation: 1
- duplicate / partial row: 0

## 8｜Full Suite

Command: `php artisan test`

- **606 PASS**
- **2 FAIL**
- **16 SKIP**
- **4,649 assertions**
- Duration: 255.48 seconds
- B-P1由来Regression: **0**

### 8.1 FAIL分類

1. `CompanyNavigationTest` 1件
   - stale forbidden intended URLに関する既知baseline。
   - B-P1 Shared Conversation差分と非接触。
2. `ReleaseHardeningTest` 1件
   - 固定済みIR-1 R0がMigration repository count `95`を期待し、現在RepositoryがP1追加後`103`である差。
   - P1 Migration不良ではない。
   - IR-1 Test / Manifest / RC / Artifactは変更していない。

過去に日付条件で発生したScope 9 fixture 3件は今回PASSした。未発生のFAILをPASSへ書き換えたものではなく、今回の実測値を記録する。

### 8.2 SKIP

16 SKIPは既存MariaDB RG02 approved isolated profile未指定による既知SKIP。P1のMariaDB Evidenceは別の承認済み隔離profileで実取得済み。SKIP追加・期待値弱体化は行っていない。

## 9｜B-C02〜C13への影響

| Compatibility | P1時点のDisposition | Evidence / 引継ぎ |
| --- | --- | --- |
| B-C02 Private principal / Shared schema | **P1対象Resolved candidate** | Private条件を維持し、Shared kind / Participant / author sidecarをadditive実装。変換・推測backfillなし |
| B-C03 Source lineage | **Implementation Verification継続** | P1はSource semanticsを変更せず、Shared Source接続は後続工程 |
| B-C04 Proposal snapshot | **Implementation Verification継続** | Proposal Engine / hash / Writer非変更。Shared Proposalは後続工程 |
| B-C05 Notification authorization | **Implementation Verification継続** | S10通知非変更。Shared通知は先行実装なし |
| B-C06 Human Message / AI Request分離 | **P1対象Resolved candidate** | Human PostでProvider / CO / Context Maintenance / Proposal各0 |
| B-C07 Browser Mic / Codec / Playback | **Implementation Verification継続** | P1対象外。Delta A Closed Contract非変更 |
| B-C08 Schema / Storage / Environment | **P1 foundation Resolved candidate** | additive schema、SQLite / MariaDB、rollback / reapply、通常local DB非変更 |
| B-C09 Long context | **Implementation Verification継続** | 未実装。P2以降 |
| B-C10 Gateway / Usage | **P1 negative boundary Resolved candidate** | Human-only経路のProvider call / Usage 0。Shared AI usageは後続工程 |
| B-C11 Diarization | **Implementation Verification継続** | 未実装。P3以降 |
| B-C12 Shared-room boundary | **P1 identity foundation Resolved candidate** | User / Participant / authorを分離。Session / streamは未実装 |
| B-C13 CO Presence / Atmosphere | **Implementation Verification継続** | 未実装。P4以降 |

C02〜C13全体をP1で一括PASSにはしていない。

## 10｜Technical Pending更新

- P0 Resolved: TP20を維持。
- P1で解消候補:
  - TP01のShared kind / principal / Participant / author / Purpose Revision部分
  - TP10のParticipant current authorization / epoch foundation部分
  - TP14のcreate / Human Post operation idempotency部分
  - TP22のUser / Participant identity分離部分
- P2〜P5継続:
  - Session、consent、source DAG、context intersection、usage、queue、notification、retention、browser、session-end、shared-room stream等
  - P1で将来接続用の境界を追加した事項も、Capability未実装部分はOpenのまま
- Later: TP23を維持。
- Technical Blocker: **0**
- Product Pending / Product Blocker: **0 / 0**
- Product Decision追加: **不要**

### 10.1 Release Pending（4区分Open維持）

- B-RP01: retention / Legal Hold / deletion request / Provider retention / backup purge
- B-RP02: 実ASR・Chat Model、価格、usage unit、Secret、保持条件、実Provider smoke
- B-RP03: 実host private Storage、scanner / audio probe、resource limit、鍵、backup除外、cleanup / restore
- B-RP04: Scope 10 実SMTP受信、Android Chrome実機PWA / Push / Deep Link

P1 Evidenceで上記を代替PASSにしていない。

## 11｜P2への引継ぎ

P2ではv003 Contractに従い、P1のaccepted Participant / Purpose Revision / author principalを基礎として、Shared AI Context intersection、明示AI Request、Shared Proposal・Notification等のP2範囲を接続する。P1 ReportはP2 Capabilityの実装・PASSを示さない。

## 12｜Git / 完了状態

- Branch: `s11cd-b-shared-ai-conversation-p1`
- Base: `904c49782560a34d09d11526d58d0d0ecdf897b9`
- Implementation commit: `acfb2c731f53a8db2e01fc8f0fb316a54572d7ea`
- Evidence commit: this Report commit; exact hashはcompletion handoffに記録
- origin: code / Evidence commit後に同Branchへpushし一致確認する
- Working tree: push後にtask-related tracked差分がないことを確認する
- user-owned untracked Master 2点: 保持、非stage、非commit

## 13｜P1終了判定

| 項目 | 判定 |
| --- | --- |
| B-G01 | OPEN / 通過済み（人＋ChatGPT Decision） |
| B-P1 | **DONE candidate** |
| P1 Compatibility Blocker | 0 |
| Product Decision追加 | 不要 |
| B-P2 | 未開始 |
| Release Pending | 4区分Open維持 |
| 次の停止地点 | 人＋ChatGPT B-P1 Done Review待ち |

**B-P1の実装・Verification・Evidence作成まで完了候補。B-P2へは進行しない。**
