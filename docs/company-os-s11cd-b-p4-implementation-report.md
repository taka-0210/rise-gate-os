# Company OS｜S11 Companion Delta B｜B-P4 Implementation Report

- 実施日時: 2026-09-28 06:07 JST
- Base: `7a717fc5af4a75bcb5dbe715c2f0826a7859db41`（B-P3 DONE承認HEAD）
- Development branch: `s11cd-b-shared-ai-conversation-p4`
- Implementation commit: `817a2d7`（最終full hashは完了報告に記録）
- Evidence commit: 本Report commit
- B-P4 disposition: **DONE candidate / 人＋ChatGPT B-P4 Done Review待ち**
- B-P5: **未開始**

## 1｜正本・開始状態・保護境界

次を正本として再読・照合した。

- `CompanyOS_v153_Shared_AI_Conversation_Product_Design_Fixed.pptx`
- `CompanyOS_Ver1_要件仕様書_v048_Shared_AI_Conversation_Product_Design_Fixed.xlsx`
- `CompanyOS_S11CD_B_Implementation_Preparation_v003.md`
- `CompanyOS_S11CD_B_Decisions_Pending_v003.md`
- `CompanyOS_S11CD_B_Codex_Instructions_v003.md`
- `docs/company-os-s11cd-b-p0-compatibility-readiness-report.md`
- `docs/company-os-s11cd-b-p1-implementation-report.md`
- `docs/company-os-s11cd-b-p2-implementation-report.md`
- `docs/company-os-s11cd-b-p3-implementation-report.md`
- Delta A Formal Close commit `64d1c3f1d2755ef3c6d44be3fc9e434ceca9be00`
- Scope 11 Formal Closed Contract / C02 Corrective Evidence

維持した保護境界:

- IR-1、Product Master、`master`、Production、Deploy、通常local DB: **非変更**
- Delta A Formal Closed Contract: **非変更 / 再Openなし**
- 未追跡Master 2点: **変更・stage・commitなし**
- Provider実接続、P5実機Verification: **未実施**

## 2｜P4 Implementation

### 2.1 Incremental Long Context

- Transcriptのcurrent Revision確定後にSession単位のdirty markerを立てる。
- Human Transcript Revision / Human-confirmed Identity Revisionでも同じmarkerを更新する。
- dirtyがないmaintenance再実行は既存checkpointを返し、Provider callを発生させない。
- maintenanceは前checkpointと未処理deltaだけをbounded payloadへ含め、Full Transcriptを毎回再送しない。
- conservative token estimatorで1 request最大12,000 tokens相当を越えるpayloadを拒否する。
- Rolling Contextは`current_topic / main_views / agreement_candidates / open_questions / to_confirm / source_refs`の最小構造とし、Company Memoryや正式Decisionとして扱わない。

### 2.2 Immutable checkpoint / provenance

- checkpoint、Transcript chunk、dependencyをadditive schemaで保持する。
- dependencyは使用したTranscript segment / immutable Revision / Identity Revision / anonymous speaker scope / time rangeを保持する。
- 本文budgetから落ちたdependencyもauthorization lineageから削除しない。
- Transcript / Identity / Purpose / audience / Consent / AI Policy変化時は旧checkpointをfail-closedで再利用しない。
- 既存C02のSource Revision / current authorization経路は変更せず、Shared AI Requestから今回のcheckpointをadditive relationで参照する。
- 不変由来を推測backfillしていない。

### 2.3 Bounded Historical Retrieval

- 同一Sessionのcurrent chunkだけを対象に、先に最大100 candidateへ絞り、lexical scoreで最大5 chunk / 合計4,000文字を返す。
- 数値、反対意見、訂正後Revisionを元Transcript range付きで再取得できる。
- embedding、全社vector DB、Scope 11 Knowledge / RAGを追加していない。

### 2.4 Explicit CO Request / Context budget

- CO Request時にdirty区間がある場合だけmaintenanceを1回補完する。
- Purpose、Rolling、Recent、bounded Historical、既存認可済みSource、User questionを既存Gatewayへ接続する。
- Human Message、Transcript確定、Session end、wake phrase文字列だけではProviderを起動しない。
- `AiUsageLedger`は`transcription / context_maintenance / shared_co_request`を分離し、logical resultとattemptを既存Contractどおり保持する。
- unknown costは`null`を維持し、0へ変換しない。

### 2.5 CO Presence / Conversation Atmosphere

- One Shared CO stateへ`ready / queued / provider_processing / answer_ready / unavailable`を実処理に応じて投影する。
- capture、ASR、Context watermarkを別stateとして表示する。
- ASR送信前、成功、failure/discardをserver stateへ反映する。
- queuedを「考え中」、Context lagを「理解済み」と表示しない。
- audio energyは既存browser-local recorder表示のままserver AI stateと混同しない。
- Text fallback、`prefers-reduced-motion`、390px 2-column縮退、no-store snapshotを追加した。

### 2.6 One Shared CO / Multi-device View

- Session / request / response / state / phase / monotonic sequenceをserver-authoritative snapshotとして返す。
- Participant/Userとdevice cursorを分離し、deviceごとのProvider Requestを作らない。
- cursor gap / future cursorはfull snapshot再取得を指示する。
- snapshot取得ごとにcurrent Participant / Membership / Consentを再評価する。
- Ver.1のcaptureはP3どおり1 streamであり、Multi-device Viewをdistributed multi-micへ拡張していない。

### 2.7 Explicit Session-end organization

- Session `ended`だけではProvider call 0。
- 認証Participantの明示操作だけがbounded整理を開始する。
- `decision / unresolved / action_candidate / rationale / next_check`は全てcandidateであり、checkpoint dependency provenanceを保持する。
- 正式Decision / Learning / Knowledge / Company Memory Writerを追加していない。
- Actionや他Unitへ自動writeせず、既存Proposal Engine / Approval / Unit Writer境界を維持した。

## 3｜Migration

- Migration: `2026_09_28_000004_add_s11cd_b_p4_long_context.php`
- Repository migration total: **106**
- P4 table: **6**
- P4 FK: **19**
- 既存tableへの変更: Session context watermark/state、AI Request checkpoint relation、CO Presence projectionのadditive列のみ
- Private ID / Source意味 / Proposal hash /既存Data変換: **なし**
- 推測backfill / destructive conversion: **なし**
- history存在時のdestructive rollbackはfail-closedする。
- empty isolated SQLite / MariaDB 10.11.19でrollback / reapply: **PASS**

## 4｜Focused Verification

`tests/Feature/S11CompanionDeltaBP4Test.php`

- Result: **8 PASS / 47 assertions**
- incremental checkpoint / no-change maintenance Provider call 0
- immutable dependency、Transcript correction stale、Identity Revision stale
- old numeric fact / opposing view bounded retrieval
- Human post / wake phrase / Session end Provider call 0、explicit COだけ起動
- Usage 3区分
- Consent / Organization AI Policy current authorization
- multi-device same authoritative snapshot / cursor gap
- explicit Session-end candidates / provenance / idempotency
- Browser surface / Text fallback / no-store snapshot

## 5｜Regression / C02

SQLite integrated bundle:

- B-P4、B-P3、B-P2、B-P1
- Delta A P1〜P4
- Scope 11 / C02 F01〜F05
- Result: **90 PASS / 498 assertions**

MariaDB 10.11.19 integrated bundle:

- Result: **90 PASS / 498 assertions**

確認結果:

- C02 F01〜F05: **全PASS / Resolved維持**
- P1 Shared principal / Human Writer: regression 0
- P2 Shared Context / Proposal / Notification / One Shared CO: regression 0
- P3 Session / Consent / Audio / Transcript / Diarization / generation fencing: regression 0
- Delta A Attachment / Voice / Source / Citation / Proposal: regression 0
- Scope 11 existing Project AI / S9 payload / Proposal Engine / Unit Writer: regression 0
- P4由来Regression: **0**

## 6｜SQLite Evidence

- 全**106 Migration**適用: PASS
- P4 rollback / reapply: PASS
- P4 table: 6 / FK: 19
- `PRAGMA foreign_key_check`: violation 0
- `PRAGMA integrity_check`: `ok`
- 専用SQLite / synthetic data: Verification後に削除済み
- 通常local DB: 未使用・非変更

## 7｜MariaDB 10.11 / Concurrency Evidence

- Version: **MariaDB 10.11.19**
- listener: `127.0.0.1:13361`限定
- Event Scheduler: OFF
- 専用一時datadir / synthetic database `s11cd_b_p4`のみ使用
- 全106 Migration / P4 rollback / reapply: PASS
- P4 table 6 / FK 19 / `CHECK TABLE`: 全件OK
- integrated regression: **90 PASS / 498 assertions**

2 independent processが同一Conversation / actor / operation IDで同時CO Requestを実行:

- Shared AI Request: 1
- Context checkpoint: 1
- Context Maintenance Usage: 1
- Shared CO Usage: 1
- Assistant response: 1
- final state: `answer_ready / published / answer_ready`
- 片方が処理中receiptを取得してもdevice-specific Provider callは追加されない。

Cleanup:

- MariaDB listener: 停止済み（port 13361 listener 0）
- P4専用datadir / database: 削除済み
- barrier / worker output: 削除済み
- 通常local MariaDB: 未使用

## 8｜Full Suite

Command: `php artisan test --compact`

- **630 PASS**
- **2 FAIL**
- **16 SKIP**
- **4,784 assertions**
- P4由来FAIL: **0**

既知FAIL:

1. `CompanyNavigationTest` 1件: B-P3以前と同じstale intended URL baseline。
2. `ReleaseHardeningTest` 1件: 固定IR-1 R0 expectation 95に対し、Formal Closed Product Development＋B-P1〜P4のRepository migration countが106となった差。

IR-1 Test / Manifest / RC / Artifactを更新せず、期待値弱体化・SKIP追加を行っていない。16 SKIPも既存MariaDB RG02 isolated profile未指定の既知分類であり、P4で追加していない。

## 9｜Browser / UI Evidence

- Laravel Feature TestでShared画面、Presence / Atmosphere Text projection、Rolling checkpoint、Historical Retrieval、390px CSS、reduced-motion、snapshot no-storeを確認した。
- JSはMulti-device snapshotを5秒pollし、hidden中は停止、network loss時は最後のtruthful stateを保持して架空進捗を作らない。
- Node runtime: **環境になく`node --check`未実施**。
- Desktop / 390px / Edge / iPhone Safari / PWAの物理実機、長時間60/120/180分、10 Participant、実ProviderはP4でPASSにしていない。P5 / Release Verificationへ引き継ぐ。

## 10｜Compatibility C02〜C13

| Compatibility | P4 disposition |
| --- | --- |
| B-C02 Private principal / Shared schema | Resolved candidate維持。Private変換なし |
| B-C03 transitive lineage | P4 Evidence追加。Transcript/Identity/checkpoint/request/candidate dependencyをadditive追跡。既存Source C02回帰PASS |
| B-C04 Proposal snapshot | Resolved candidate維持。Session-end Action自動write 0、既存Engineのみ |
| B-C05 Notification authorization | Resolved candidate維持。P4新規通知0 |
| B-C06 Human Message / AI Request分離 | Resolved candidate維持。Human / Transcript / End / wake phrase call 0 |
| B-C07 Browser Mic / Codec / Playback | P3 code Evidence維持。P5実機へ継続 |
| B-C08 Schema / Storage / Environment | P4 additive schema、SQLite/MariaDB可逆性追加 |
| B-C09 Long Context | **P4 Resolved candidate**。bounded checkpoint/retrieval/budget/provenance |
| B-C10 Gateway / Usage | **P4 Resolved candidate**。ASR / maintenance / CO purpose分離 |
| B-C11 Diarization | P3 Resolved candidate維持。Identity RevisionをP4 staleへ接続 |
| B-C12 Shared-room / Multi-device View | **P4 Resolved candidate**。server snapshot / cursor / 1 shared result |
| B-C13 Presence / Atmosphere | **P4 Resolved candidate**。truthful state / watermark / fallback。物理UXはP5 |

Compatibility Blocker: **0**

## 11｜Technical Pending / Release Pending

P4で解消候補またはEvidence追加:

- TP06〜09: Chunk / Checkpoint / Rolling / bounded retrieval / budget
- TP10〜14: current audience / dependency / Usage / coalesce / idempotency
- TP17 / TP18: Presence projection / Session-end candidate
- TP24 / TP25: One Shared CO multi-device snapshot / truthful Atmosphere

P5へ継続:

- 60/120/180分、10 Participant、context quality / latency / resource budget
- physical Browser / 390px / Edge / iPhone Safari / PWA / background / reconnect
- operational retention / cleanup / host resource limit
- DC01〜40の統合Close Verification

Later維持:

- TP23 distributed multi-mic acoustics / clock: **OUT-LATER**
- Scope 11 Knowledge / company-wide RAG: **OUT-LATER**

Release Pending 4区分:

1. B-RP01 retention / Legal / deletion / Provider retention / backup purge
2. B-RP02 real ASR / Chat / Diarization Provider / Model / Price / Usage / Secret / Smoke
3. B-RP03 real host Storage / scanner / audio probe / resource limit / key / backup / cleanup / restore
4. B-RP04 Scope 10 SMTP / Android Chrome PWA・Push・Deep Link

全て**Open維持**。fake / isolated / iPhone既存Evidenceを実Provider・実host・Android PASSへ読み替えていない。

## 12｜P4終了判定 / P5引継ぎ

- B-P1: DONE
- B-P2: DONE
- B-P3: DONE
- B-P4: **DONE candidate**
- P4 Product Decision追加: **不要**
- Product Pending / Product Blocker: **0 / 0**
- P4 Compatibility Blocker: **0**
- C02: **Resolved維持**
- B-G01: **OPEN / 通過済み維持**
- Master update: **不要**（確定v153 / v048 Contract内）
- IR-1: **非変更**
- B-P5: **未開始**

P5では今回PASSにしていない実Browser/device、長時間品質、retention/cleanup、全DC01〜40統合Evidenceを確認する。Codex判断でP5へ進まず、人＋ChatGPT B-P4 Done Review待ちで停止する。
