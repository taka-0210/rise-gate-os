# Company OS｜S11 Companion Delta A｜P5 Close Verification Report

- 実施日：2026-09-27 JST
- Base：P4 Evidence HEAD `14235da6a0553e137cf8c43058c26aca4151d89c`
- Branch：`s11cd-a-conversation-input-attachment-p5`
- 対象：S11 Companion Delta A P5 Close Verification
- 判定：**BLOCKED（Voice cancel late-event defectを実動再現。Corrective Delta required）**

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
