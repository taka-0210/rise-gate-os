# Company OS｜S11 Companion Delta A｜P5 Close Verification Report

- 実施日：2026-09-27 JST
- Base：P4 Evidence HEAD `14235da6a0553e137cf8c43058c26aca4151d89c`
- Branch：`s11cd-a-conversation-input-attachment-p5`
- 対象：S11 Companion Delta A P5 Close Verification
- 初回判定：**BLOCKED（Voice cancel late-event defectを実動再現。Corrective Delta required）**
- Corrective継続後判定：**P5 Done候補 / Delta A Formal Close候補（Formal Close未実施）**
- Human＋ChatGPT Final Review後判定：**S11 Companion Delta A｜Conversation Input / Attachment：FORMAL CLOSED**

## 1｜停止判断

P5の最初に、Delta B Repository監査から追加されたVoice cancel所見をDesktop EdgeでFocused確認した。その結果、cancel直後に遅れて配送された`dataavailable` / `stop` eventにより、取り消した録音がFile inputへ再生成される不具合を再現した。

ユーザー指示の停止条件に従い、実装修正、P5の残りの統合Verification、iPhone Safari / PWA本人実機確認、Formal Close候補化へは進んでいない。Scope 11本体はFormal Closed、C02はResolved、P1〜P4はDoneのまま維持する。

## 2｜Focused scenario

### 2.1 環境

- Browser：Microsoft Edge 154（Chromium 154、Windows、headless mode）
- Origin：loopback `127.0.0.1`の今回専用一時HTTP server
- Product asset：`public/js/ai-common-input.js`を変更せず読み込み
- MediaRecorder：Browser上の`EventTarget`として制御し、cancel後10msでlate `BlobEvent('dataavailable')`と`Event('stop')`を配送
- Data：合成Blob `late-audio`のみ。Business Data、通常local DB、Provider、Productionは不使用
- Harness：
  - `tests/Browser/s11cd-a-p5-cancel-late-event-reporting.html`
  - `tests/Browser/s11cd-a-p5-cancel-late-event-result.php`

### 2.2 再現手順

1. Product JavaScriptを読み込んだVoice formで「録音開始」を発火する。
2. recorderが`recording`になった後、「録音取消」を発火する。
3. cancel直後のFile件数と表示を記録する。
4. `recorder.stop()`後に遅れて到着する`dataavailable`と`stop`を実Browser event loopで配送する。
5. late event後のFile件数と表示を記録する。

### 2.3 Assertion / 実測

期待Contract：cancel後はlate eventが到着してもFile inputを再生成せず、取消表示を維持する。

実測：

```json
{
  "recorderCreated": true,
  "trackStops": 1,
  "immediatelyAfterCancel": 0,
  "afterLateEvents": 1,
  "status": "録音を準備しました。保存ボタンを押すまで送信されません。",
  "pass": false
}
```

判定：**FAIL / 再現**

### 2.4 原因

Product JavaScriptは`dataavailable`と`stop`を`addEventListener()`で登録している。一方、cancel時は`recorder.ondataavailable = null`と`recorder.onstop = null`を設定しているため、登録済みlistenerは解除されない。`recorder.stop()`後のlate eventが元listenerへ配送され、BlobとFileが再生成される。

## 3｜影響範囲

- cancel表示と実際のFile input状態が不一致になる。
- 本人が取り消した録音が再び「保存可能」なFileとして現れ、誤ってTemporary Voiceへ保存され得る。
- cancel自体でnetwork送信やserver保存は発火しないため、この再現だけで自動外部送信・自動投稿は発生していない。
- server-side Temporary lifecycle、Transcription、Usage、Source lineage、C02 Correctiveに不具合波及を示すEvidenceはない。
- UIのVoice cancel / failure境界に限定されるが、本人同意・Privacyに関係するためFormal Close前に解消が必要である。

## 4｜Contract影響

- `S11-CD-A-DC16`（録音取消）：**FAIL / Corrective required**
- `S11-CD-A-DC24`（Edge・iPhone Safari/PWAを含む実機Close Evidence）：**BLOCKED / 未完了**
- P1〜P4：Done維持
- CD-AB-C02：Resolved維持
- A-G01：OPEN / 通過済み維持
- Scope 11本体：Formal Closed維持
- Product Contract変更：不要
- Migration：不要

この不具合があるため、A-DC01〜24の最終Done判定、Delta A Formal Close候補、iPhone実機Journeyへは進まない。

## 5｜限定Corrective案

確定Contract内の最小修正候補：

1. 録音開始ごとに固有session / generationを作り、listenerが自分のsessionだけを更新できるようにする。
2. cancelでは`stop()`より前に当該sessionをcancelled / inactiveへ遷移させる。
3. `dataavailable` / `stop` listenerは、cancelledまたはcurrentでないsessionのeventを無視する。
4. cancel後のFile input空状態と取消messageをlate eventから保護する。
5. normal stopでは従来どおりFileを1件生成し、trackを停止する。

Focused corrective verification候補：

- cancel直後のlate `dataavailable` / `stop`でFile 0件・取消表示維持
- 旧sessionのlate eventが次の録音へ混入しない
- normal stopはFile 1件へ収束
- repeated cancel / pagehide / visibility hiddenでtrack停止・重複Fileなし
- File保存だけでAI / Transcription request 0件
- Desktop Edge後、iPhone Safari / PWA実機で許可・拒否・停止・取消・再生を再確認

第二Recorder、Product UX変更、Permission緩和、server Contract変更は不要と見込む。Corrective実装は今回の承認範囲外のため実施していない。

## 6｜P5 / Formal Close状態

- P5：**Blocked / 未完了**
- Compatibility Blocker：**1件（Voice cancel late-event）**
- Formal Close Blocker：**1件**
- Product Pending：0件
- Technical Pending：1件（限定Correctiveと再Verification）
- Release Verificationへの移管：不可。A-DC16 / A-DC24のScope Development Close境界に該当する。
- iPhone Safari / PWA：未実施（Blocker解消前に本人操作を要求しない）
- A-DC01〜24最終判定：未実施。DC16 FAIL、DC24 BLOCKEDを先に固定

## 7｜保護境界

- IR-1：非変更
- Product Master：非変更
- master：非変更
- Production / Deploy：未実施
- 通常local DB：未接続・非変更
- Delta B：未開始・非変更
- 保護対象の未追跡Master 2点：変更・stage・commitなし

## 8｜次の停止地点

人＋ChatGPTへ、Voice cancel late-eventの限定Corrective Delta要否をReview依頼する。承認前に修正、P5再開、iPhone実機確認、Delta A Formal Close、Delta Bへ進まない。

---

## 9｜承認後Corrective継続

人＋ChatGPT Reviewにより初回判定を承認し、Voice cancel / late-event境界だけを対象とする限定Corrective Deltaが明示承認された。Scope 11本体のFormal Closed、P1〜P4 Done、CD-AB-C02 Resolved、A-G01 OPEN / 通過済みは再Openせず維持した。

### 9.1 実装

- Implementation Commit：`4665197ae94d3fb3d7961874b6a18ab56484fa13`
- 対象：`public/js/ai-common-input.js`
- 録音開始ごとに固有session / generationを生成する。
- cancel / pagehideでは、`stop()`より先に当該sessionをinactive / cancelledへ遷移させる。
- `dataavailable` / `stop` handlerは、自分がcurrent sessionであり、かつcancelledでない場合だけFile / UIを更新する。
- cancel済み・終了済み・旧generationのlate eventは安全に無視する。
- 正常stopは従来どおり1 Fileへ収束する。
- Migration、server Contract、Permission、Product Contractの変更はない。

### 9.2 Corrective Focused結果

実Browser event loopで次を確認した。

| Scenario | 最終結果 |
|---|---|
| cancel → late `dataavailable` / `stop` | PASS：File 0、取消表示維持 |
| cancel後のUI逆戻り | PASS：発生なし |
| old generation → new recording | PASS：旧Blob混入0、新File 1 |
| normal stop | PASS：File 1 |
| repeated cancel | PASS |
| cancel → immediate new recording | PASS |
| `pagehide` | PASS：track停止、late eventによるFile生成0 |
| `visibility hidden` | PASS：track停止、正常終了File 1 |
| stop / data event ordering差 | PASS：dataなしstopはfail-closedでFile 0 |
| permission拒否 | PASS：Text入力を維持 |

原不具合Harnessも再実行し、`immediatelyAfterCancel = 0`、`afterLateEvents = 0`、取消表示維持へ変化した。期待値弱体化、SKIP追加、正常系削除は行っていない。

## 10｜Regression / DB Evidence

- P1〜P4＋Scope 11 / C02：**59 PASS / 330 assertions**
- C02 F01〜F05：**全PASS / Resolved維持**
- Full SQLite Suite：**596 PASS / 5既知FAIL / 16 SKIP / 4,610 assertions**
- P5 Corrective由来Regression：**0**
- SQLite：Migration全102件、rollback / reapply / integrity Evidenceを維持
- MariaDB 10.11.19：Migration全102件、P4同回帰 **59 PASS / 330 assertions**、CHECK / rollback / reapply Evidenceを維持
- concurrency：同一operationのoperation / revision / usage / audit / provider callが各1件へ収束するP4 Evidenceを維持
- Correctiveはclient JavaScript限定であり、Migration / server schema / transaction経路を変更していない。

既知5 FAILの分類はP4から不変：

1. Scope 9日付依存fixture：3件
2. `CompanyNavigationTest`既存baseline：1件
3. 固定済みIR-1 R0のMigration期待値95とRepository現在値102の差：1件

上記をPASSへ変更せず、SKIP追加、期待値弱体化、IR-1更新を行っていない。

## 11｜Desktop Edge / 390px継続Evidence

- Microsoft Edge actual runtimeでConversation、Voice UI、認証Audio playbackを確認
- 認証Audio Range：HTTP 206、`audio/wav`、bytes 0–31 / 16,044
- autoplay：なし
- Desktop horizontal overflow：なし
- 390px：viewport 390 / scrollWidth 390、Voice controlsはviewport内、入力focus可
- Cache Storage：`company-os-shell-v2`のみ
- IndexedDB：0
- cancel / late event、old→new、normal stop、event ordering、visibility、pagehide、permission拒否を実Browserで確認

## 12｜iPhone Safari / PWA本人実機Evidence

### 12.1 環境

- iPhone Safari通常起動とHome Screen追加後のstandalone PWAを使用
- Scope 11 Delta A専用の隔離SQLite、synthetic Organization / User / Conversation / Audioだけを使用
- 遠隔地からの本人実機接続のため、Scope 11専用Cloudflare Quick Tunnelを一時使用
- login必須、実Providerなし、Production / 通常local DB / 実業務Dataなし
- Transcriptionは`p5-synthetic-provider` / `p5-synthetic-transcription-v1`

### 12.2 Safari通常起動

| Scenario | 結果 |
|---|---|
| mic permission | PASS：本人操作後にiOS許可prompt、許可後に録音開始 |
| normal recording / stop | PASS：`録音を準備しました`、保存成功 |
| cancel | PASS：`録音を取り消しました` |
| cancel後のlate event待機 | PASS：3秒後も取消表示維持、File再生成なし |
| cancel → immediate new recording | PASS：新規録音・stop・保存成功 |
| repeated cancel | PASS：2 session連続で取消成功 |
| visibility hidden / foreground | PASS：Home移行でtrack終了、復帰後は1 Fileを準備し保存成功 |
| pagehide / navigation | PASS：録音中の画面移動後、Voice初期表示へ復帰しFile再生成なし |
| Audio playback | PASS：無音synthetic WAVの再生bar進行 |

### 12.3 standalone PWA

| Scenario | 結果 |
|---|---|
| Home Screen追加 / standalone起動 | PASS |
| PWA個別mic permission | PASS |
| cancel → late event待機 | PASS：取消表示維持、File再生成なし |
| cancel → immediate new recording / normal stop | PASS：保存成功 |
| visibility hidden / foreground | PASS：復帰後1 Fileを準備し保存成功 |
| pagehide / navigation | PASS：File再生成なし |
| Audio playback | PASS：再生bar進行 |
| 390px相当の操作 | PASS：横scrollなし、開始・停止・取消・保存を操作可能 |

### 12.4 Voice → Human Message

本人が実際に発話した音声をPWAで録音・保存した。実Providerは禁止されているため、音声認識内容そのものはsynthetic固定Draftで確認した。

1. 本人が発話して録音・stop・Temporary Voice保存。
2. 本人がTranscription同意を明示。
3. Fake Transcriptionが`Synthetic device transcript for explicit human confirmation.`をDraftとして返却。
4. 本人がDraftを`これは音声入力の実機確認です`へ編集。
5. 本人がHuman Message投稿を実行。
6. Conversationに「あなた／これは音声入力の実機確認です」と表示。
7. 画面は「AI Requestは送信していません」と明示。

隔離DBのcleanup前read-only照合：

- `ai_common_temporary_audios`：6件（実機で明示保存した期待件数と一致）
- state：recorded 5件、posted 1件
- 実機録音形式：全件 `video/webm` / `webm` / `opus`
- `ai_common_transcription_operations`：1件、synthetic provider success
- `ai_common_messages`：1件
- `ai_requests`：**0件**
- `ai_usage_ledgers`：1件（synthetic transcription operationのLedger）
- cancelしたsession、pagehideで破棄したsessionからの余分なTemporary Voice：0件

## 13｜Cleanup Evidence

iPhone側：

- Home Screenへ追加した一時PWAを削除済み
- 今回はpublic test CAを使わずQuick Tunnelを使用したため、iPhoneへTest CA / profileはインストールしていない

Windows / 検証環境側：

- Cloudflare Quick Tunnel停止、公開一時URLは到達不能を確認
- Cloudflared process：0
- 一時backend listener：0
- TCP 8086 / 8444 / 18775 listener：0
- Scope 11一時Firewall rule：0
- Cloudflared binary / temp directory：削除済み
- 隔離SQLite 2点：削除済み
- 隔離Storage 2点：削除済み
- Edge一時profile：削除済み
- synthetic Audio / Voice / User / Conversation：隔離Storage / DBとともに削除済み
- CA / CA private key / leaf certificate / leaf private key：残存0（異Network判明時にCA方式を中止し削除済み）

削除対象はいずれも検証専用一時Artifactであり、通常local DB、Production、Repository secretは削除・変更していない。

## 14｜C01〜C08最終Disposition

| Compatibility | 最終Disposition |
|---|---|
| C01｜既存File / Storage | Resolved。P1〜P4回帰、認証download / Range / storage failure境界を維持。実host scanner / storage運用はRelease Evidenceとして分離 |
| C02｜Source lineage | Resolved。F01〜F05全PASS維持 |
| C03｜Proposal Snapshot | Resolved。既存Proposal Engine / canonical hash / Writer境界に回帰なし |
| C04｜Notification authorization | Resolved。既存境界非変更、重複通知なしEvidence維持 |
| C05｜Schema / Storage / Environment | Resolved。additive schema、SQLite / MariaDB 102 Migration。実host運用はRelease Evidence |
| C06｜Human Message / AI Request分離 | Resolved。iPhone本人JourneyでもHuman Post時`ai_requests = 0` |
| C07｜Browser Mic / Codec / Playback | Resolved。Edge、390px、iPhone Safari / PWA実機Evidence取得 |
| C08｜Transcription / Temporary / Usage | Resolved。fake provider、同意、投稿、cleanup、Usageを確認。実Provider条件はRelease Evidence |

新規Corrective、Product Contract変更、Compatibility Blockerはない。

## 15｜S11-CD-A-DC01〜24最終判定

| DC | 判定 | Evidence要約 |
|---|---|---|
| DC01 | Done | Private本人 / Org / current資格、他User・他Org陰性、Archive回帰 |
| DC02 | Done | 共通Attachment adapter / Writer境界。Shared UIはDelta Bへ分離 |
| DC03 | Done | allowlist、size、structure、quarantine、検査不能fail-closed |
| DC04 | Done | Storage / metadata / operation fingerprint / failure / retry整合 |
| DC05 | Done | 認証Download / Preview / Range / no-store / current authorization |
| DC06 | Done | Existing Resource Relation、元Permission、コピーなし |
| DC07 | Done | Human SharingとAI opt-in分離、default OFF |
| DC08 | Done | bounded extraction、unsupported / partial / corrupt fail-closed |
| DC09 | Done | immutable Source Revision / Manifest / Citation / opaque handle |
| DC10 | Done | C02 F01〜F05、transitive lineage、reselect / retry / revoke |
| DC11 | Done | revoke / archive / cleanup / immutable audit |
| DC12 | Done | Privacy / Audit / Usageへ本文・binaryを複製しない |
| DC13 | Done | SQLite / MariaDB additive Migration全102件 |
| DC14 | Done | degraded mode、Edge、390px、Closed Scope回帰 |
| DC15 | Done | Development / Release分離、保護境界維持 |
| DC16 | Done | Corrective後、permission / stop / cancel / hidden / pagehide / late event実機PASS |
| DC17 | Done | Temporary lifecycle、TTL / failure / late response / cleanup Evidence |
| DC18 | Done | 専用Transcription purpose、同意、response-loss / unknown / failure回帰 |
| DC19 | Done | iPhone本人がDraft確認・編集・Human Message投稿、Chat Provider 0 |
| DC20 | Done | Audio upload / quarantine / auth playback / Range、Edge・Safari・PWA再生 |
| DC21 | Done | ASR同意とChat AI opt-in分離、保存だけではProvider 0 |
| DC22 | Done | Transcript Revision / Citation / revoke / current authorization |
| DC23 | Done | Voice Privacy / Audit / Usage、unknown cost、S9非変更 |
| DC24 | Done | Desktop Edge、390px、iPhone Safari通常 / standalone PWA本人実機 |

- Done：**24**
- Conditional：**0**
- Not Done：**0**
- Compatibility Blocker：**0**
- Formal Close Blocker：**0**
- Product Decision追加：**不要**

## 16｜Release Verification Open Evidence

Scope DevelopmentのDoneへ読み替えず、Release Ready前のOpen Evidenceとして維持する。

- 実malware scanner / signature更新 / 実audio probe
- 実Provider / Secret / Model availability / Price / retention / Legal / Smoke
- host storage / encryption key / backup exclusion / orphan cleanup運用
- Scope 10：実SMTP Provider受信
- Scope 10：Android Chrome PWA / Push / Deep Link
- S11-TP11 Knowledge / RAG：OUT-LATER

今回のiPhone実機Evidenceは実Transcription Provider Evidenceを代替しない。

## 17｜本人実機UX所見

本人実機Reviewで、Voice欄は「ボタンが多く、かなり慣れたUserでなければ感覚的に分かりにくい」との所見があった。今回の画面はテスト専用UIではなく、同一画面に複数のsynthetic Temporary Voiceが蓄積して通常より密集していた点を除き、Production候補と同じUIである。

- 機能・誤送信防止・明示同意・本人確認Contract：成立
- 390px / Safari / PWAの操作不能・overflow：なし
- UX改善候補：progressive disclosure、段階別CTA整理、完了済みTemporary Voiceの折りたたみ等
- 今回の限定Voice cancel Correctiveには混在させず、勝手に再設計しない
- 現行確定Contractに対するProduct Decision Pending：0
- Human＋ChatGPT Formal Close Reviewで扱う明示的UX observation：1

この所見を自動的にPASSへ吸収せず、またCodex判断だけでFormal Close Blockerへ変更しない。

## 18｜P5 / Formal Close最終状態

- P1：**DONE**
- P2：**DONE**
- P3：**DONE**
- P4：**DONE**
- P5：**DONE**
- A-G01：OPEN / 通過済み維持
- C01〜C08：Resolved
- CD-AB-C02：Resolved、F01〜F05全PASS維持
- A-DC01〜24：**DONE**
- Conditional / Not Done：0 / 0
- Product Decision Pending：0
- Product UX Follow-up：1件（Conversation Input UIの段階表示・導線整理）
- Technical Pending：0
- Compatibility Blocker：0
- Formal Close Blocker：0
- Migration総数：102
- S11 Companion Delta A｜Conversation Input / Attachment：**FORMAL CLOSED**
- Formal Close承認・実施日時：**2026-09-27 23:58 JST**
- Delta B：未開始

## 19｜保護境界と停止地点

- Scope 11本体：Formal Closed維持
- IR-1：非変更
- Product Master：非変更
- master：非変更
- Production / Deploy：未実施
- 通常local DB：未接続・非変更
- Delta B：未開始・非変更
- 保護対象の未追跡Master 2点：変更・stage・commitなし

Corrective実装・P5継続Verification・iPhone cleanup・Human＋ChatGPT Final Review・Formal Close Evidence反映まで完了した。Delta Bへ自動進行せず、人＋ChatGPTの次工程指示待ちで停止する。

## 20｜Formal Close Decision

### 20.1 承認

2026-09-27 23:58 JST、人＋ChatGPT Final ReviewによりS11 Companion Delta A｜Conversation Input / AttachmentのFormal Closeが明示承認された。初回Voice cancel FAIL、限定Corrective、再Verification、iPhone Safari / PWA本人実機Evidenceの履歴を維持したまま、以下を正式状態として固定する。

- P1〜P5：DONE
- A-G01：OPEN / 通過済み
- A-DC01〜24：DONE
- C01〜C08：Resolved
- CD-AB-C02：Resolved、F01〜F05 PASS維持
- Conditional：0
- Not Done：0
- Compatibility Blocker：0
- Formal Close Blocker：0
- Product Decision追加：不要
- Migration最終数：102
- Scope 11本体：Formal Closed維持
- Delta A：FORMAL CLOSED

### 20.2 Formal Close Evidence

- Voice Cancel Corrective Implementation Commit：`4665197ae94d3fb3d7961874b6a18ab56484fa13`
- P5 Close Candidate Evidence Commit：`85b9049920a165417a28315ae8704e30f486d93d`
- Formal Close Commit：本節を含むEvidence-only Commit。確定hashはPush後の完了報告でHEAD / originとともに固定する。
- Formal Close開始時HEAD / origin：`85b9049920a165417a28315ae8704e30f486d93d` / `85b9049920a165417a28315ae8704e30f486d93d`
- Formal Close Commit後：HEAD / `origin/s11cd-a-conversation-input-attachment-p5`一致をPush後に再確認する。
- Working tree：tracked差分は本Reportだけ。保護対象の未追跡Master 2点は非変更・非stageのまま維持する。

### 20.3 Known FAIL / SKIP

- Full SQLite Suite：596 PASS / 5既知FAIL / 16 SKIP / 4,610 assertions
- 既知FAIL：Scope 9日付依存fixture 3件、`CompanyNavigationTest`既存baseline 1件、固定IR-1 Migration期待値95とRepository 102の差1件
- Delta A由来Regression：0
- 既知FAILをPASSへ変更せず、SKIP追加・期待値弱体化・IR-1更新を行っていない。

### 20.4 Release / UX分離

実Provider、実scanner / audio probe、保持 / Storage実運用、Scope 10 SMTP、Android関連EvidenceはRelease Verification Openを維持する。Formal Closeによって未取得EvidenceをPASSへ変更しない。

「Conversation Input UIはボタンが多く、初見では分かりにくい」という本人所見はFormal Close Blockerへ戻さず、Product UX Follow-upとして維持する。今回のFormal Close Evidence更新ではUI再設計を行わない。

### 20.5 保護境界

IR-1、Product Master、master、Production、Deploy、通常local DB、Delta Bは非変更。Formal Close後もDelta Bへ自動進行しない。
