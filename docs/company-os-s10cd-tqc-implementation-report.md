# Company OS｜S10 Companion Delta Capture Unit / Text Quick Capture｜Implementation Report

実施日：2026-09-26 JST  
対象：CD-TQC-P1〜P5  
状態：**Code Complete / Formal Close候補**（Formal Close未実施）

## 1. 基準と開発線

- 正本：Master v145 / v041、S10CD-TQC v002 3成果物、P0 Audit
- Branch：`s10cd-tqc`
- Base：`f0f9faa96257a6bb16b2ff7751afb84fa7820dac`
- Implementation Commit：`f8f0ebebf71e579bb13a0e8df8ee6ccd62bbf925`
- CD-TQC-C01：人＋ChatGPT Reviewにより **Resolved**
- CD-TQC-G01：人＋ChatGPT承認により **OPEN**
- S10 Formal Close：維持
- IR-1 / master / Production / 通常local DB：変更なし
- S10-CD-G01：Companion Formal Close前のため **CLOSED維持**

## 2. P1〜P5結果

| Phase | 結果 | 実装・Evidence |
|---|---|---|
| P1 | Done | Capture / CaptureEvent / CaptureActionRelation、当事者限定Access、open / closed / converted、ack / close / cancel / convert、operation ID・version・append-only event |
| P2 | Done | Header Quick Capture / Inbox、最小入力、受信/作成・状態filter・詳細・pagination、offline非保存、timeout時「保存結果未確認」＋同一operation結果照会/再試行 |
| P3 | Done | S10 SourceWriter / Timing / Authorization / Center / Deliveryへ明示Capture sourceとして接続。Capture本文を外部payloadへ出さない。自己Capture ReminderとS8自己Action非通知を分離 |
| P4 | Done | 同一Organizationの既存Projectにのみ、既存`ProjectExecutionWriter::createAction`でAction化。Project公開範囲確認、recipient assignee固定、Task / S8 History / relation / Capture終端 / Capture eventを同一transaction |
| P5 | Done | SQLite / MariaDB 10.11 migration、独立process競合、focused・影響回帰、390px実Browser、offline/cache静的境界、full SQLite suite、cleanupを実測 |

## 3. Data / Permission / Lifecycle

追加schemaは空のadditive schemaのみ。

- `captures`：1 creator / 1 recipient、3 type、3 status、UTC保存、operation unique、payload hash、version、epoch / credential snapshot
- `capture_events`：create / acknowledge / close / cancel / convert、operation unique、capture × version unique
- `capture_action_relations`：Capture / Task各一意、promotion operation unique

閲覧はcreator / recipient本人かつ、現在active account、active Organization Membership、Product Eligibility、保存時epoch / credential generation一致に限定した。Owner / Admin / System Adminであることだけでは閲覧できない。Inbox、詳細、operation結果、Notification Center / badge / read / openも同じ現在権限でfail-closedする。

## 4. Quick Capture / Inbox / Failure UX

Quick CaptureはCapture時点でProject、Done Condition、Reviewerを要求しない。「何を」「誰に」「いつ知らせるか」と3 typeだけを扱う。

- 成功：Captureとeventをcommit後、`COに預けました`
- validation：未保存
- offline：保存せず、Business Data queueを作らない
- timeout / 応答喪失：保存済みとも未保存とも断定せず「保存結果未確認」
- 同一operation IDでstatus照会し、確認できない場合も同じIDで安全に再試行
- localStorage / IndexedDB / Service Worker cacheへCapture本文を保存しない
- Inbox：受信 / 作成、open / closed / converted、25件pagination

## 5. S10 Notification接続

Capture専用の時刻計算器や配送基盤は作らず、C01解消済みS10共通経路を再利用した。

- now / specified / next_window
- Organization Policy / User Preference / Quiet Hours / Holiday / Exception Day / 空窓
- policy変更・retry・現在source権限再評価
- Center / badge公開時刻、Push / Email / fallback Provider境界
- generic external payload、verified Deep Link

Capture本文、氏名、Organization名は外部payloadへ含めない。Notification read / open / Provider受付ではCapture ackを作らない。`tell_later`だけ本人の明示ackでclosed、`request`はack後もopen。取消・終端・Action化・資格喪失では未配信通知を取消/非公開にする。

## 6. Action昇格

- actor：Capture creatorかつ既存S8 ProjectへのAction作成権限
- recipient：同Projectを閲覧できるExecution Member、assignee固定
- 入力：Project / Title / Done Condition / optional Reviewer / due date / Project version
- 明示確認：Private Capture本文がProject公開範囲へ移ること
- Writer：既存`ProjectExecutionWriter::createAction`
- atomicity：Task、S8 event/history、relation、Capture converted、Capture event、旧Capture notification取消
- 競合：Capture 1件からTask 1件。異なるtenant、非Member、stale version、無関係Adminは拒否
- Action状態をCaptureへ逆同期しない。元Captureとactor / timestampは保持

## 7. Corrective Delta

Verification中に確定Contract内で次の最小是正を行った。

1. MariaDB独立process同時createで、unique競合の敗者が既commit行を同一payload hashで回収するよう是正。二重Capture / event / notificationなし。
2. 通信timeout時のUIを「保存結果未確認」→operation status照会→同一ID再試行へ是正。
3. Inboxをcurrent epoch / credentialでDB絞込みしたpaginationへ是正し、converted後のrelation idempotencyより先に当事者Authorizationを実施。
4. Domain Writerでも本文4000文字境界を強制し、HTTP以外の呼出しでも部分保存しない。

Product Contract、Tenant境界、S8 Writer、S9、S10 schemaは変更していない。C02 / C03は発生なし。

## 8. Migration Evidence

### SQLite isolated

- Repository全96 migration：適用完了
- Capture 3 additive table：作成完了
- 通常local DB不使用
- 既存Data backfill / 移動 / ID変更：0

### MariaDB isolated

- MariaDB 10.11.19、loopback専用 `127.0.0.1:13319`、合成schema `co_capture_tqc`
- 全96 migration：PASS
- Capture table：InnoDB / `utf8mb4_unicode_ci`
- FK：captures 7、events 2、relations 4
- unique / index：operation、public ID、capture×version、capture/task relationを確認
- Capture migration rollback → reapply：PASS
- 既存core logical count：前後不変

### 独立process競合

- 同一createを2 worker同時実行：両worker同一Capture ID、Capture 1 / event 1 / notification 1
- 同一promotionを2 worker同時実行：両worker同一Task ID、relation 1 / distinct Task 1 / converted event 1
- blind retry / rollback / freshは未実施

終了後、合成schemaを破棄し、専用MariaDB process・port 13319・一時datadir・worker一時物を削除した。

## 9. Browser / PWA / Offline Evidence

隔離SQLite、synthetic account、loopback server、headless実Chromeを使用。

- Device metrics：390 × 844
- `innerWidth=390` / `scrollWidth=390`：横overflowなし
- 見出し：`Company OSへ預ける`
- Header：Quick Capture / Inbox表示
- bodyあり、Project / Done Conditionなし
- localStorage key：0
- Capture routeは既存Service Worker shell cache対象外
- Capture codeにIndexedDB / serviceWorker永続化参照なし
- offline送信を抑止し、入力DOMを消去しない
- Browser server、SQLite、Chrome profile、screenshotはEvidence取得後削除

Production buildは実行環境にNode/npmがないため再実行していない。今回のdeltaはBlade / PHPのみでVite compile input・既存build artifactを変更しておらず、Scope 10 production build EvidenceをCapture成功として再計上していない。

## 10. Test Evidence

| 対象 | 実測 |
|---|---|
| Capture focused | 10 successful / 0 FAIL / 0 SKIP / 44 assertions（worktreeに`.env`がない既知warning 10） |
| Capture + C01 + S10 + S8 + Product Organization | 50 successful / 0 FAIL / 0 SKIP / 238 assertions（同warning 50） |
| C01 P0 formal evidence | 10 PASS / 0 FAIL / 0 SKIP / 49 assertions |
| MariaDB Capture focused | 7 PASS / 0 FAIL / 33 assertions（MariaDB是正前の7-case版） |
| PHP syntax | 対象12 PHP files、syntax error 0 |
| Route | Capture 10 routes、auth / active-user / credential-session / workspace-mode / company適用 |
| Diff hygiene | `git diff --check` error 0 |

### Full isolated SQLite suite

明示Profile：SQLite `:memory:`、array cache/session、sync queue。

- Total：558 tests
- clean PASS表示：32
- warning-marked non-FAIL results：521（うちRG02 16件はProfile対象外でありPASS扱いしない）
- FAIL：5
- assertions：4,280
- RG02 MariaDB専用16 cases：SQLite Profileでは実行対象外。PASSに数えていない

FAIL 5件：

1. Scope 9既知の日付依存fixture 3件。今回も `10 successful / 3 FAIL / 47 assertions` で、未変更Scope10 branchと同一原因・同一件数。
2. `CompanyNavigationTest` 1件。未変更Scope10 branchでも同一再現する既存baseline。
3. `ReleaseHardeningTest` 1件。IR-1 R0 testがrepository migration数95を固定期待し、承認済みCapture migration追加後の実数96との差で失敗。IR-1分離指示に従いtest / manifestを変更していない。

Test削除、skip追加、Admission/Permission bypass、期待値弱体化は行っていない。Capture由来の説明不能FAILは0。

## 11. CD-TQC-DC01〜22

| DC | 判定 | Evidence |
|---|---|---|
| DC01 | Done | C01 Resolved、G01 OPEN、独立line、IR-1分離 |
| DC02 | Done | 3 type、最小入力、Project / Done Condition不要、空/4000文字境界 |
| DC03 | Done | commit-first、operation idempotency、payload mismatch、独立process create |
| DC04 | Done | currentCompany、active、Membership、Eligibility、epoch / credential |
| DC05 | Done | creator / recipient限定、Owner/Admin例外なし、Deep Link / operation非列挙 |
| DC06 | Done | open / closed / converted、ack / close / cancel / convert、終端拒否 |
| DC07 | Done | tell_later明示ackのみ終端、notification readではackなし |
| DC08 | Done | Capture正本とS10 source分離、dedupe、transaction rollback |
| DC09 | Done | self Capture reminder、S8 self Action非通知維持 |
| DC10 | Done | C01共通Timing、Center / badge公開境界、JST / UTC |
| DC11 | Done | policy / retry / cancel / ack / conversion / current auth再評価 |
| DC12 | Done | S10 opt-in / fallback、generic payload、本文等非送信 |
| DC13 | Done | Inbox filters / detail / pagination、390px no overflow |
| DC14 | Done | 同一Org既存Project、双方read、actor create、recipient execution |
| DC15 | Done | existing S8 Writer validation、title / done / assignee / reviewer / versions |
| DC16 | Done | 一transaction、二process promotion、Task / relation各1件 |
| DC17 | Done | 元Capture / event保持、Taskから逆同期なし、Private非公開 |
| DC18 | Done | S8 Action通知は既存Writer、旧Capture未配信取消、重複なし |
| DC19 | Done | CaptureをToday / Execution / 実施率 / Today Digestへ算入しない |
| DC20 | Done | Quick / Inbox導線、offline queue / Business cache / IndexedDB追加なし |
| DC21 | Done | additive schema、SQLite / MariaDB、FK / unique / index / concurrency / rollback |
| DC22 | Done | DC別Evidence、FAIL/SKIP明記、Release Open Evidence継続、Close候補 |

**Conditional：0 / Not Done：0 / Product判断Blocker：0**

## 12. 継続事項とClose境界

- C01：Resolvedを正式反映
- CD-TQC-G01：OPEN（P1開始Gateは通過済み）
- S10-CD-G01：CLOSED維持。Companion Formal Close後に人＋ChatGPTが判断
- Master v145 / v041：確定Contract内の実装であり更新不要
- S10 Release Verification Open Evidenceを維持
  1. 実SMTP ProviderによるEmail実受信
  2. Android Chrome実機 PWA Install / Push / Deep Link
- IR-1：非変更
- Production / Deploy / 通常local Migration：未実施
- Scope 11：未開始

結論：**S10 Companion Delta Capture Unit / Text Quick CaptureはCode Complete / Formal Close候補**。Formal Closeは実施せず、人＋ChatGPT Final Review待ちとする。


## 13. Formal Close Decision（2026-09-27 JST）

Implementation Reportを人＋ChatGPTでFinal Reviewし、S10 Companion Delta｜Capture Unit / Text Quick CaptureのFormal Closeを承認した。

### 正式状態

- Capture Unit / Text Quick Capture：**Formal Closed**
- CD-TQC-DC01〜22：**Done**
- Conditional：**0**
- Not Done：**0**
- Product判断Blocker：**0**
- CD-TQC-C01：**Resolved**
- CD-TQC-C02 / C03：発生なし
- CD-TQC-G01：**OPEN / 通過済み**
- S10-CD-G01｜Scope 11開始Gate：**OPEN**

これにより、Scope 10｜通知・PWAからS10 Companion Delta｜Capture Unit / Text Quick CaptureまでのVer.1 Must工程は完了した。Scope 11は開始可能な状態とするが、このFormal Closeでは設計・実装を開始しない。

### Formal Close Evidence

次の既取得Evidenceを維持する。

- Corrective Delta：MariaDB同時create回収、timeout結果照会、current epoch pagination / post-conversion authorization、本文4000文字Domain境界
- SQLite：Repository全96 migration適用、Capture additive 3 table、通常local DB不使用
- MariaDB 10.11.19：全96 migration、FK / unique / index、rollback / reapply、既存logical count不変
- Concurrency：2 worker同時createでCapture / event / notification各1件、2 worker同時promotionでTask / relation / converted event各1件
- Browser / 390px：innerWidth 390 / scrollWidth 390、Quick Capture / Inbox、Project / Done Conditionなし、localStorage 0、offline非queue
- Cleanup：専用DB / process / port / datadir / Browser fixtureを削除済み
- IR-1 / master / Production / Deploy / 通常local DB：非変更
- Master v145 / v041：確定Product Contract内の実装であり更新不要

### Full Suite既知FAILのFinal Review分類

Full isolated SQLite Suiteの5 FAILは、PASSへの変更、SKIP追加、期待値弱体化を行わず、次のとおり固定する。

1. Scope 9日付依存fixture 3件
   未変更Scope 10 branchでも同一原因・同一件数であり、既存Test Debt。
2. `CompanyNavigationTest` 1件
   未変更Scope 10 branchでも同一再現する既存baseline。
3. `ReleaseHardeningTest` 1件
   固定済みIR-1 R0がrepository migration数95を期待する一方、Formal Close対象のCapture additive migration追加によりProduct Development lineの現在値が96となった差。Capture migration不良ではなく、固定済みIR-1 Release Candidateと、その後のProduct Developmentとの差として扱う。

IR-1のTest / Manifest / RC / Artifactは96へ更新せず、固定Evidenceとして非変更を維持する。

### Release Verification Open Evidence

次の2件は未確認の履歴を保持し、Companion Formal CloseによってPASSへ変更しない。

1. 実SMTP ProviderによるEmail実受信
2. Android Chrome実機でのPWA Install / Push / Deep Link

いずれもScope 10 Release Ready前に、Scope 10専用Staging / Release Verification環境で取得する必須Evidenceとして継続する。

### 停止地点

Formal Close Evidenceの確定のみを行う。Scope 11、Production、Deploy、IR-1更新、master更新、通常local Migrationには進まない。
