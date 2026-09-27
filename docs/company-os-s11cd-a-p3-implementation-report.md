# Company OS｜S11 Companion Delta A P3 Implementation Report

作成日：2026-09-27 JST  
対象：Conversation Input / Attachment / Voice P3  
判定：P3 Done候補／P4未開始／人＋ChatGPT P3 Done Review待ち

## 1｜Base / Development Line

- Base：S11-CD-A P2 Evidence commit `f60ae4f4ac1749ec77d3d983fb0c1982dc6ed095`
- Branch：`s11cd-a-conversation-input-attachment-p3`
- Implementation commit：`cf8aa267491a2101a388eccc171cfdf45903a9f5`
- Scope 11：Formal Closed維持
- CD-AB-C02：Resolved維持
- A-G01：OPEN / 通過済み維持
- P2：Doneとして継承
- IR-1 / Product Master / Production / Deploy / 通常local DB：非変更
- 未追跡Master 2点：変更・stage・commitなし

## 2｜P3実装内容

### 2.1 Bounded extraction

- PDF：明示page range、最大10 page。`pdftotext` adapterはtimeout付きprocessとして実行する。
- DOCX：明示paragraph range、最大50 paragraph。OOXMLをZip / DOMで限定展開する。
- XLSX：明示sheet / row range、最大100 row、最大20 sheet。formulaは評価せず`[formula omitted]`とする。
- AI Sourceへ渡せる抽出本文は最大2,000文字に制限した。
- archive entry数、展開量、XML量にも上限を設けた。
- unsupported、corrupt、空抽出、範囲超過、tool unavailableはfail-closedとし、読めなかった内容を読めた扱いにしない。
- Upload、preview、extractだけではAI Request / Provider送信を発生させない。

### 2.2 Safe preview / playback

- Text preview：認証・現在権限確認済みの`text/plain`、`no-store`、`nosniff`、CSP付き応答。
- Image preview：JPG / PNGのみ。認証・現在権限確認、`no-store`、`nosniff`、sandbox CSP付き応答。
- Audio playback：認証・現在権限確認、`no-store`、`nosniff`、Range requestの`206`および不正rangeの`416`に対応。
- UIは`preload="none"`とし、autoplayしない。
- filename / storage path / binary本体をProvider projectionへ含めない。

### 2.3 Audio Attachment / Transcript Revision

- P2のTemporary Voice経路とは分離して、保存済みAudio Attachmentの明示transcription経路を追加した。
- 対象MIME / codec、10 MiB上限、duration / codec metadataを既存inspection結果へ接続した。
- private conversation、uploader、ready audio、Organization policy、明示consent、current authorizationを要求する。
- Provider I/O中にDB transaction / row lockを保持しない。
- Provider response確定時にも現在権限・archive / revoke・versionを再評価し、失権後のlate publishを破棄する。
- Provider transcript revisionとHuman transcript revisionをimmutableに保存した。
- operation ID / payload fingerprintによるresponse-loss replayのidempotencyを追加した。
- unknown resultをblind retryしない。

### 2.4 Source / Citation / lineage

- `attachment_extract`と`attachment_transcript`を既存Source Manifestへadditiveに接続した。
- Attachment uploaderによる明示AI reference opt-inをdefault OFFで追加した。
- Organization AI PolicyへAttachment categoryを追加し、Common policyとの両方を要求する。
- 同一private conversation、Attachment / origin resourceのcurrent authorization、ready / hash / revoke / archiveを毎回確認する。
- immutable Source Revisionにはresource identity、content hash、selector / range、policy / access version、opaque handleを接続する。
- C02で確立したimmutable revision、transitive lineage、各attempt直前再認可、response取得後fail-closedを変更せず再利用する。
- Attachment / TranscriptをSourceとした回答は、元Attachmentまたはorigin resourceのrevoke / archive / permission loss後、履歴表示・Citation・next-turn contextからfail-closedとなる。
- Providerが返すCitationだけをlineageの唯一の根拠にしない。

## 3｜P3 Done条件への適合

- Full contentを無条件にAIへ送らない：PASS。明示選択されたbounded derivative / transcript revisionだけをprojectionする。
- Upload / preview / extractでAI Requestを発火しない：PASS。
- extraction failure / unsupported / partial / corruptをfail-closed：PASS。
- Attachment → derivative / transcript → Source / Citation → transitive lineage：PASS。
- 元Resource / Attachment / Transcriptのrevoke / archive /失権を派生Dataへ伝播：PASS。
- current Permission / Policyを各利用境界で再確認：PASS。
- C02 immutable revision / lineage / retry / response-side reauthorization：PASS維持。
- P4 Proposal / Privacy / Audit / Usage Ledger本体：未実装。P3に混在させていない。

## 4｜Schema / Migration

Additive Migration：

- `database/migrations/2026_09_27_000006_add_s11_companion_delta_a_p3_derivatives.php`
- `ai_common_attachments`へ`media_codec`、`duration_ms`を追加。
- `ai_common_attachment_derivatives`を追加。
- `ai_common_transcript_revisions`を追加。
- `ai_common_attachment_transcription_operations`を追加。
- FK、unique、lookup index、operation idempotency境界を追加。
- 既存Attachment / Message / Source / Proposal IDおよび意味は変更していない。
- 既存Dataへ真の抽出内容・Transcript・lineageを推測backfillしていない。
- P3 Evidence rowが存在する状態でのdestructive rollbackはfail-closedとした。

## 5｜Focused Verification

`tests/Feature/S11CompanionDeltaAP3Test.php`

- 結果：8 PASS / 53 assertions
- 実PDF / DOCX / XLSX fixtureによる明示rangeと上限確認：PASS
- unsupported / corrupt / overbroad extraction：PASS（fail-closed）
- immutable derivative / operation replay / AI Request非発火 / private preview：PASS
- Audio Range playback / Provider revision / Human revision / idempotency：PASS
- bounded Provider projection / filename・storage path非送信：PASS
- revoke後の履歴非表示 / Citation・next-turn遮断：PASS
- uploader opt-in / policy category / same-conversation境界：PASS
- Provider I/O中revoke後のlate result破棄：PASS
- archive後の新規処理拒否 / autoplayなし：PASS

## 6｜Regression / C02

P3 + P2 + P1 + Scope 11 / C02 bundle：

- 50 PASS / 278 assertions
- P3：8 PASS
- P2：12 PASS
- P1：7 PASS
- Scope 11 / C02：23 PASS

C02 Focused：

- F01｜推移的Source lineage：PASS維持
- F02｜immutable revision / reselect：PASS維持
- F03｜retry直前のcurrent Policy再認可：PASS維持
- F04｜Provider I/O中のMembership失権：PASS維持
- F05｜Provider I/O中のreselect：PASS維持

既存Scope 11 Proposal Engine、canonical hash、Unit Writer、L1 / L2 / L3、stale、Atomic Apply、Idempotency、Undo、S9 AI payloadの意味は変更していない。

## 7｜SQLite Evidence

通常local DBではなく一時SQLiteを使用した。

- 全101 Migration：PASS
- P3 table：3
- `PRAGMA integrity_check`：`ok`
- P3 rollback：PASS（100 Migration、P3 table 0、integrity `ok`）
- P3 reapply：PASS（101 Migration、P3 table 3、integrity `ok`）
- 検証後、一時DBを削除した。

## 8｜MariaDB 10.11 Evidence

通常local DB / Production DB / IR-1 DBではなく、loopback専用port、一時datadir、synthetic databaseを使用した。

- Version：MariaDB 10.11.19
- 全101 Migration：PASS
- P3 table：3
- P3 FK：8
- P3 index entry：30
- collation：`utf8mb4_unicode_ci`
- P3 3 tableの`CHECK TABLE`：すべてOK
- P3 rollback / reapply：PASS
- P3 + P2 + P1 + Scope 11 / C02：50 PASS / 278 assertions
- 検証後、listener停止、一時database / datadir削除：完了

### Independent process concurrency

- 2つの独立PHP processが同一extraction operationを同時開始。
- 両workerは同一derivative public IDを返した。
- DB確定値：derivative 1、ready 1、operation 1。
- duplicate derivative / duplicate operationなし。
- 検証後、一時storage / database / datadirを削除した。

## 9｜Full SQLite Suite

- 587 PASS
- 5 FAIL
- 16 SKIP
- 4,558 assertions

5 FAILはP2 Final Review時と同じ既知baseline分類であり、P3由来Regressionは0。

1. Scope 9の日付依存fixture：3件
2. `CompanyNavigationTest`既存baseline：1件
3. `ReleaseHardeningTest`：固定済みIR-1 R0のMigration 95に対し、現Repositoryが101である差：1件

期待値弱体化、PASS化、SKIP追加、IR-1 Test / Manifest / RC / Artifact変更は行っていない。

## 10｜UI / Runtime Evidence

- Blade compile：PASS
- compiled view cleanup：完了
- P3 UIの認証 / private route、explicit extraction、explicit transcription consent、AI reference opt-in、preview / playback、`preload="none"`をFeature Testで確認した。
- この環境ではNode.js / npmを利用できないためfrontend buildは未実測。P3ではbundled JavaScript変更はない。
- 実Browser / 390px、実codec playback、実PDF tool / host scanner、実ProviderはP5 / Release Verificationで取得する。未実測をPASS扱いにしていない。

## 11｜Compatibility / Product Decision

- Scope 11 Formal Closed Contract：変更なし
- P1 / P2 Contract：変更なし
- CD-AB-C02：Resolved維持
- 第二Proposal Engine：追加なし
- Unit Writer迂回：なし
- Tenant / Permission / Privacy緩和：なし
- 既存Data推測変換：なし
- destructive / irreversible Migration：なし
- Providerへの許可外送信：なし
- Product Decision追加：不要
- C01 / C03〜C08へ新規Compatibility Blockerの波及：なし

## 12｜残Pending / 次工程

P3 Done Review後も、次の項目は未取得または後工程対象として残す。

- P4：Attachment / Transcript由来Proposal、Privacy、Audit、Usage Ledger本体
- P5：実Browser / 390px、実Mic / codec / playback、追加failure / degraded mode統合Evidence
- Release Verification：実malware scanner、実audio probe、実Provider / Secret / Model / Price / retention、host storage / backup / orphan cleanup運用
- Scope 10 Release Verification：実SMTP Provider受信、Android Chrome PWA / Push / Deep Link

これらをP3 PASSへ読み替えていない。

## 13｜保護境界

- IR-1：非変更
- Product Master：非変更
- master branch：非変更
- Production：未接続
- Deploy：未実施
- 通常local DB：未接続・非変更
- 未追跡Master 2点：変更・stage・commitなし
- P4：未開始

## 14｜最終判定

- P3：Done候補
- Focused / Regression / SQLite / MariaDB / concurrency：必要Evidence取得済み
- Full Suite：既知baseline 5 FAIL、新規Regression 0
- C02：Resolved維持
- Product Decision追加：不要
- P4開始：未承認のため実施しない
- 停止地点：人＋ChatGPT P3 Done Review待ち

Git最終値はEvidence commit / push後に本節へ反映する。
