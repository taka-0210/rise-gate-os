# Company OS｜S11 Companion Delta A｜P1 Implementation Report

- 実施日：2026-09-27（JST）
- 対象：Conversation Input / Attachment / Voice のうち P1 Foundation
- Base HEAD：`2008e76a16872967f3c9c40b6154fbd8b16f324b`
- Development Line：`s11cd-a-conversation-input-attachment-p1`
- Scope 11本体：Formal Closed維持
- S11-CD-A-G01：OPEN / 通過済み（人＋ChatGPT Final Decision）
- CD-AB-C02：Resolved維持

## 1｜P1結論

P1は、確定Contract内で次のFoundationを実装し、P2以降へ進まず完了した。

- Private Attachmentのadditive schema / model / permission / writer
- upload予約と既存File参照を分離したAttachment lifecycle
- AttachmentのAI利用を初期値OFFとする明示境界
- Human Message保存とAI Request送信の分離
- operation IDとpayload fingerprintによるidempotency
- archive / revoke / origin失権時のfail-closed
- SQLite / MariaDB 10.11でのMigration適用・rollback・reapply確認

実Binary upload、scanner、content extraction、Attachment UI、Voice / ASR / playbackはP1対象外であり、P2以降のVerification対象として残す。

## 2｜確定Contractとの対応

### 2.1 Human MessageとAI Requestの分離

`POST /company/co/conversations/{conversation}/human-messages`を追加し、Human Messageだけを保存する専用Writerへ接続した。この経路はAI Gateway / Providerを呼ばない。AI PolicyがOFFでも、Private Conversationへの現在権限を持つ本人はHuman Messageを保存できる。

Attachmentの追加・選択・解除もAI送信を発生させない。既存のAI Request経路は変更していない。

### 2.2 Attachment境界

- 許可種別：PDF / DOCX / XLSX / JPEG / PNG
- size上限：10 MiB
- 1 Human Messageあたり最大5件
- private disk、非公開storage key、direct public servingなし
- reservation時は`receiving`、既存File参照時は`quarantine`
- AI利用は`ai_reference_enabled = false`を初期値とする
- 既存Fileはcopyせず、origin identity / version / SHA-256をrelationとして保持する
- origin消失・hash不一致・現在権限喪失はfail-closed
- revokeはidempotentで、Conversation archive後も安全に実施可能

### 2.3 Voice境界

VoiceはP1で実装していない。後続でも次の確定経路を維持する。

`Temporary Voice → Transcription → Human Confirm / Edit → Human Message`

Voice inputとAudio Attachmentは別Contractとし、Realtime Voice、Shared Conversation、Delta Bは今回対象外とする。

## 3｜Architecture / Files

### 3.1 Migration / Models

- `database/migrations/2026_09_27_000004_add_s11_companion_delta_a_p1_foundation.php`
- `app/Models/AiCommonAttachment.php`
- `app/Models/AiCommonAttachmentOperation.php`
- `app/Models/AiCommonInputOperation.php`

additive table：

1. `ai_common_attachments`
2. `ai_common_attachment_operations`
3. `ai_common_input_operations`
4. `ai_common_message_attachments`

既存Conversation / Message / Source / ProposalのID・意味は変更していない。履歴が存在する状態でのdestructive rollbackは拒否する。

### 3.2 Services / HTTP

- `app/Services/AiCommon/AiCommonAttachmentAccess.php`
- `app/Services/AiCommon/AiCommonAttachmentWriter.php`
- `app/Services/AiCommon/AiCommonHumanMessageWriter.php`
- `app/Http/Controllers/AiCommonController.php`
- `routes/web.php`
- `config/filesystems.php`

Provider I/OやBinary I/O中にDB transaction / row lockを保持する構造は追加していない。

## 4｜Permission / Privacy / Idempotency

- Organization / active Membership / credential epoch / Private Conversation ownerを現在値で再評価する。
- Attachment metadataとMessage relationはOrganization / Conversation境界を越えない。
- Client roleからの既存Project Internal Note参照は拒否する。
- 同一operation ID・同一payloadは同一結果へ収束する。
- 同一operation ID・異なるpayloadは拒否する。
- archive後の新規Attachment / Human Messageは拒否する。
- replay responseは既存結果を返し、重複Messageや重複relationを作らない。

## 5｜CD-AB-C02 Regression

C02の以下Contractを変更していない。

- immutable Source Revision
- transitive Source lineage
- 各Provider attempt直前のcurrent authorization
- response取得後のcurrent authorization
- source / membership / policy失権時のfail-closed

F01〜F05を含む`ScopeElevenAiCommonEntryTest`は全件PASSした。

## 6｜Verification Evidence

### 6.1 Focused / Contact Regression

- P1 Focused：7 PASS
- P1 + Scope 11：30 PASS / 156 assertions
- P1 + Scope 11 + existing Attachment / Proposal：47 PASS / 302 assertions
- formatter / PHP syntax / route registration：PASS

Focused assertion：

1. private upload reservation / idempotency / AI reference default OFF
2. type / MIME / size rejection
3. existing resource relation / no copy / origin消失fail-closed
4. Human Message idempotency / Provider call 0 / AI OFF / archive replay
5. readyかつcurrent authorizedなAttachmentだけをMessageへbind
6. revoke idempotency / archive後の安全なrevoke
7. archive後の新規Attachment / Human Message拒否

### 6.2 SQLite Migration

- isolated SQLiteで全99 Migration：PASS
- P1 Migration rollback：PASS（98 Migration / P1 table 0）
- P1 Migration reapply：PASS（99 Migration）
- integrity check：ok

### 6.3 MariaDB 10.11

- Version：MariaDB 10.11.19
- loopback限定・一時datadir・synthetic databaseのみ使用
- 全99 Migration適用：PASS
- P1 Migration rollback / reapply：PASS
- P1 4 table / FK / index / check：PASS
- P1 Focused scenario：process exit 0
- listener停止・一時datadir削除：完了

通常local DB、Production DB、IR-1 DBには適用していない。

### 6.4 Full SQLite Suite

- 567 PASS
- 5 FAIL
- 16 SKIP
- 4,436 assertions

5 FAILはP1追加による回帰ではなく、事前baselineと同じ既知分類である。

1. Scope 9日付依存fixture：3件
2. `CompanyNavigationTest`の既存baseline：1件
3. 固定済みIR-1 Release Hardening Migration件数と現在Repository Migration件数の差：1件

期待値弱体化、PASS化、SKIP追加、IR-1 Test / Manifest / RC / Artifact更新は行っていない。

## 7｜Compatibility再判定

- C01｜既存File / Storage：P1 Foundation成立。実Binary / scanner / extraction / storage faultはP2以降。
- C02｜Source lineage：Resolved維持。F01〜F05 PASS。
- C03｜Proposal Snapshot：既存Proposal Engine / canonical hash / Unit Writer / Applyを非変更。Attachment由来Proposalの実接続は後続。
- C04｜Notification authorization：Delta Aでは非変更。Delta Bへ持越し。
- C05｜Schema / Storage / Environment：additive schemaとSQLite / MariaDB互換はPASS。実storage environmentはRelease Verification対象。
- C06｜Human Message / AI Request分離：P1で成立しFocused PASS。
- C07｜Browser Mic / Codec / Playback：未実装。P2〜P5 / Release Verificationへ移行。
- C08｜Transcription / Temporary / Usage：未実装。P2〜P5へ移行。

既存Closed Contractを再Openする互換問題、追加Product Decision、Tenant / Permission / Privacy緩和は発生していない。

## 8｜Known Issues / P2 Handoff

P1終了時点で、次を未実装として明示的にP2以降へ渡す。

- binary ingest完了処理、content hash確定、quarantine / scan state遷移
- malware / unsafe archive / MIME spoof等の検査
- PDF / DOCX / XLSX / image extractionとAI projection
- Attachment選択・解除・送信分離を示す390px UI
- Voice temporary storage / transcription / confirm-edit / usage ledger
- Browser mic / codec / playback実機Compatibility
- Attachment由来Proposal / Citation / Source Manifestの後続接続

これらは「既存実装の互換問題」ではなく、P2〜P5で初めて実装・検証する項目である。

## 9｜保護境界

- Scope 11本体：Formal Closed維持
- S1〜S10＋Capture Unit：Formal Closed維持
- IR-1：非変更
- master：非変更
- Product Master：非変更
- Production / Deploy：未実施
- 通常local DB：非変更
- Delta B / Scope 12：未着手

## 10｜P1終了判定

- S11-CD-A-G01：OPEN / 通過済みを反映
- Delta A P1：Done候補
- CD-AB-C02：Resolved維持
- Product Decision追加：不要
- P2開始可否：P1技術Evidence上は開始候補。ただし本Reportを人＋ChatGPTがReviewし、明示承認するまで開始しない。
