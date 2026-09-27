# Company OS｜S11 Companion Delta A P2 Implementation Report

作成日：2026-09-27 JST
対象：Conversation Input / Attachment / Voice P2
判定：P2 Done候補（P3未開始、人＋ChatGPT Review待ち）

## 1｜Base / Development Line

- Base：S11-CD-A P1 DONE commit `1e65828209dd9d1680524f9e1bbffa8da077bc33`
- Branch：`s11cd-a-conversation-input-attachment-p2`
- Scope 11：Formal Closed維持
- CD-AB-C02：Resolved維持
- A-G01：OPEN / 通過済み維持
- IR-1 / master / Production / Deploy：非変更
- 未追跡Master 2点：変更・stage・commitなし

正本は、Master v149 / v044、Delta AB Preparation / Decisions / Codex Instructions v002、A-P0 Readiness Report、C02 Corrective Evidence、P1 Implementation Reportである。

## 2｜P2で実装したCapability

1. Upload binaryをprivate storageへ保存し、quarantineからinspection通過後だけreadyにするingest経路
2. Upload / Existing Resource relationを分離し、既存Fileはcopyせずcurrent permissionとhashを再確認する経路
3. inspection未完了、実体消失、hash不一致、同operation異payloadをfail-closedにする公開境界
4. 本人Private Conversationに限定した認証Download（`no-store` / `nosniff`）
5. Attachment追加・Human Message保存・AI Requestを別操作にしたInput UI / route
6. Temporary Audioの暗号化private保存、10 MiB / 3分上限、1時間TTL、取消・期限切れ・投稿後cleanup
7. Organization transcription policy default OFF、本人明示同意、current membership / policy再認可
8. Provider-neutral Transcription AdapterとOpenAI初期Adapter。Chat purposeとは別の`transcription` purpose
9. `Temporary Audio → Transcription draft → Human Confirm / Edit → Human Message`の分離
10. Provider I/O中にDB transaction / row lockを保持しない処理、応答後失権・取消・unknown resultのlate publish / blind retry防止
11. Human Postのconversation→audio lock順、Atomicity、operation idempotency、archive後response-loss replay
12. cleanup commandと5分間隔・重複禁止schedule

## 3｜P2 Contract / Done条件

P2では次をDone条件とした。

- 保存・Upload・文字起こし完了だけではAI Requestを開始しない
- 保存済みであることとAI参照可能であることを分離し、AI参照はdefault OFF
- ready stateだけで公開せず、inspection `passed`、current authorization、binary integrityをすべて要求する
- Existing Resourceは元Permission・元File・元hashを現在値で再確認する
- Voice InputとAudio Attachmentを混同しない
- TranscriptionはOrg policyと本人同意を別々に要求し、各処理境界でcurrent authorizationを再確認する
- Transcript draftは本人Privateであり、本人の確認・編集・明示投稿前にHuman Messageへしない
- 同operation再送、response-loss、Provider unknown、Provider中取消で二重投稿・二重送信・late publishを発生させない
- Temporary Audioのphysical cleanup完了とDB stateを区別し、TTL cleanupを継続実行する
- P1 / Scope 11 / C02 F01〜F05を回帰させない
- SQLite / MariaDB 10.11双方でadditive Migrationを適用可能にする

## 4｜Product Contractとの対応

- Human Message / AI Request分離：実装・Focused PASS
- Attachment / Source原則：保存とAI参照許可を分離。Source / Citation接続はP3〜P4へ維持
- Voice Boundary：Temporary Audio、Transcription、Human Confirm / Edit、Human Messageを分離
- Provider-neutral：Transcription interface / adapterを追加し、OpenAIは初期Adapterとしてのみ接続
- current authorization / fail-closed：Upload時の許可を後続利用へ流用せず、利用・送信・応答確定時に再認可
- C02：独自Context経路を作らず、既存immutable revision / transitive lineage / retry再認可を非変更

新しいProduct Decisionは追加していない。

## 5｜P1からの変更点

P1のmetadata / relation / writer / permission / lifecycleを作り直さず、次をadditiveに追加した。

- binary ingest、inspection state、private authenticated delivery、binary integrity
- transcription policy
- Temporary Audio / Transcription Operation
- Provider-neutral transcription adapter
- Voice record / transcribe / confirm / post / cancel UIとroute
- cleanup command / schedule

P1回帰fixture 1件は、P2でready Fileに実体hashとinspection passを必須化したため、対応binaryとinspection Evidenceをfixtureへ追加した。期待値弱体化ではない。

## 6｜変更File

主な追加：

- `database/migrations/2026_09_27_000005_add_s11_companion_delta_a_p2_input_pipeline.php`
- `app/Http/Controllers/AiCommonInputController.php`
- `app/Models/AiCommonTemporaryAudio.php`
- `app/Models/AiCommonTranscriptionOperation.php`
- `app/Services/AiCommon/AiCommonAttachmentIngestor.php`
- `app/Services/AiCommon/AiCommonTemporaryAudioWriter.php`
- `app/Services/AiCommon/OpenAiCommonTranscriptionProvider.php`
- `app/Contracts/AiCommonAttachmentInspector.php`
- `app/Contracts/AiCommonAudioInspector.php`
- `app/Contracts/AiCommonTranscriptionProvider.php`
- `app/Console/Commands/CleanupAiCommonTemporaryAudio.php`
- `resources/views/ai-common/_input.blade.php`
- `public/js/ai-common-input.js`
- `tests/Feature/S11CompanionDeltaAP2Test.php`

既存変更：Attachment / Conversation / Policy model・access・writer、Provider transport、service binding、filesystem / service config、Policy / Conversation view、web / console route、P1 regression fixture。

## 7｜Migration

Migrationはadditiveな1件。

- `organization_ai_policies.allows_transcription`：default false
- `ai_common_attachments`：inspection status / driver / version / safe code / inspected time
- `ai_common_temporary_audios`：Temporary Audio state、暗号化object key、hash、codec、duration、draft、TTL、cleanup
- `ai_common_transcription_operations`：operation / fingerprint / logical request / provider / model / result / safe error / latency
- FK、unique、expiry / result indexを追加
- 既存rowの真のinspection / provenanceは推測backfillしない。default pendingとしてfail-closed
- 履歴row、inspection Evidence、transcription policy ONがある場合のdestructive rollbackは禁止

## 8｜新規Test

`S11CompanionDeltaAP2Test` 12 scenario：

1. private binary upload / quarantine / inspection / idempotency / no-store Download
2. spoof / inspection unavailableの非ready
3. quarantine中の同operation異payload上書き拒否
4. ready stateだけではinspectionを迂回できない
5. storage tamper / revoke fail-closed
6. explicit transcription → draft → Human Post → cleanup / archive後replay
7. consent / Org policy / current membership再認可
8. Provider I/O中取消とlate response破棄
9. Temporary Audio実体消失時のfailed確定、Provider未送信、processing残留禁止
10. unknown resultのblind retry禁止
11. cancel / expiry physical cleanup
12. Human / Attachment / Voice / AI actionを分離したPrivate UI

## 9｜Focused / Regression結果

- P2 Focused SQLite：12 PASS / 69 assertions
- P2 + P1 + Scope 11 / C02 SQLite：42 PASS / 225 assertions
- Proposal regressionを含む先行bundle：P2 fixture是正前の1 fixture差を特定。fixture是正後はFull Suiteで全関連Test Green
- C02 F01〜F05：全PASS
- Scope 11 Formal Closed Contract：回帰なし
- P1 Contract：回帰なし
- formatter / PHP syntax（33 files）/ route registration / cleanup schedule：PASS
- application timezone：`Asia/Tokyo`

## 10｜SQLite Evidence

- isolated SQLite：全100 Migration PASS
- P2 Migration rollback：PASS
- P2 Migration reapply：PASS
- `ai_common_temporary_audios` / `ai_common_transcription_operations`：存在確認
- `PRAGMA integrity_check`：`ok`
- 通常local DBは使用・変更していない

## 11｜MariaDB 10.11 Evidence

- Version：MariaDB 10.11.19
- loopback `127.0.0.1`、専用port、一時datadir、synthetic DBだけを使用
- 全100 Migration：PASS
- P2 rollback後：P2 table 0 / `allows_transcription` column 0
- P2 reapply：PASS
- P2 table 2、FK 6、対象index entry 10、`utf8mb4_unicode_ci`
- P2 table `CHECK TABLE`：2 tableともOK
- 最終P2 + P1 + Scope 11 / C02：42 PASS / 225 assertions
- listener正常停止、一時datadir削除：完了
- 通常local DB / Production DB / IR-1 DBは未使用

## 12｜Full SQLite Suite

- 579 PASS
- 5 FAIL
- 16 SKIP
- 4,505 assertions

5 FAILはP1基準と同じ既知分類で、P2由来Regressionは0。

1. Scope 9日付依存fixture：3件
2. `CompanyNavigationTest`既存baseline：1件
3. 固定IR-1 Release Hardening Migration件数と現在Repository Migration件数の差：1件

期待値弱体化、PASS化、SKIP追加、IR-1 Test / Manifest / RC / Artifact更新は行っていない。

## 13｜Compatibility Open Item現在地

- C01｜File / Storage：P2 ingest・private delivery・integrityはPASS。実scanner / signature更新 / host storage faultはP3〜P5・Release Verificationへ継続
- C03｜Proposal Snapshot：既存Engine非変更。Attachment / Transcript由来Proposal接続はP3〜P4へ継続
- C05｜Schema / Storage / Environment：SQLite / MariaDBはPASS。実host scanner / audio probe / storage / backup条件はOpen
- C06｜Human Message / AI Request：P2範囲はPASS。保存・Upload・TranscriptionでProvider自動呼出0、明示AI相談は既存経路のまま
- C07｜Browser Mic / Codec / Playback：feature detection / stop / cancel / hidden時track停止を実装。Node / Browser実動 / codec / playbackは未実測でOpen
- C08｜Transcription / Temporary / Usage：fake Providerで同意・Policy・current auth・unknown・cancel・cleanupはPASS。実Provider・正式usage / cost / retentionはOpen

C01 / C03 / C05 / C06 / C07 / C08をP2だけでCloseしていない。

## 14｜Known Issues / Release Verification

- 現hostにmalware scannerとaudio probeがないため、default inspectorは意図的にfail-closed。未導入をPASS扱いしていない
- Node.js / npmがこの実行環境にないため、JS runtime check / frontend buildは未実施。P2 JSはpublic assetでありbundle変更なし。Browser実動はP5 Evidenceへ継続
- 実Transcription Provider / Secret / Model availability / price / retention / smokeは未確認
- actual Browser Mic / codec / iPhone Safari / PWA / Desktop Edgeは未確認
- real Storage / encryption key / backup exclusion / orphan cleanup運用はRelease Verification対象
- Scope 10の実SMTP、Android Chrome PWA / Push / Deep LinkはOpenのまま

## 15｜P3 Handoff

P3開始の別承認後にのみ、次を扱う。

- bounded extraction / safe preview
- Audio Attachment / safe playback
- Transcript Revision / Source / Citation / transitive lineage
- revoke / current permissionの派生物伝播
- Attachment / Transcript由来Proposal境界
- privacy / Audit / Usage Ledgerの正式接続
- failure / storage / extraction / response-lossの追加Evidence

P3、Delta B、Scope 12、Production、Deployへは進んでいない。

## 16｜判定

- P2：Done候補
- Product Decision追加：不要
- Compatibility Blocker新規発見：0
- C02：Resolved維持
- A-G01：OPEN / 通過済み維持
- P3：未開始
- IR-1 / master / Production / Deploy：非変更

- Implementation commit：`66aa50929ab7b00c9fd2ec9fc02230662c1122fd`
- Branch：`s11cd-a-conversation-input-attachment-p2`
- Implementation commit push時点でHEAD / origin一致、tracked working tree clean
- 未追跡Master v148 / v043の2点は保護し、変更・stage・commitしていない
- 本Report最終化commitは同branchのGit履歴を正本とする
