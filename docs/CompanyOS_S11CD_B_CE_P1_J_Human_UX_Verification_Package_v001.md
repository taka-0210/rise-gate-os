# Company OS｜CE-P1 P1-J Human UX Verification Package v001

- Date: 2026-09-30 JST
- P1-I: **INCONCLUSIVE / Technical Verification Required**
- P1-J: **AUTHORIZED WITH KNOWN P1-I LIMITATION / READY FOR HUMAN MICROPHONE GATE**
- Additional Synthetic Limited Request: **NOT AUTHORIZED / NOT PLANNED**
- Provider Failure: **NOT ESTABLISHED**

## 1｜Evidenceの分離

| Evidence区分 | 現在の扱い |
|---|---|
| Provider Capability Evidence | CE-PD08B E1でDeepgram Nova-3 Streamingの接続、同一承認済み日本語WAV、Partial、Final、Metadata、Result identity、Word timing、Provider timing、Speaker information、normal close、`mip_opt_out=true`が成立済み。 |
| Current Company OS Relay Integration Evidence | P1-Iはlive end-to-end PASS未取得。`INCONCLUSIVE / Technical Verification Required`を維持する。 |
| P1-J Human UX Evidence | Human操作前。P1-IのPASSを意味せず、実Product Pathで新たに取得する。 |

過去E1 EvidenceはProvider Capabilityだけを支え、現在のRelay Integration PASSへ転用しない。

## 2｜準備済み環境

- 端末: 現在のWindows PC
- 推奨Browser: 最新のGoogle ChromeまたはMicrosoft Edge
- URL: `https://localhost:8443/company/co`
- LaravelとWSS Relay: `127.0.0.1`限定
- BrowserからProviderへの直接接続: なし
- Provider Credential: Server-side DPAPIだけ
- DB: 通常local DBの隔離コピー。通常local DBは非変更
- Migration: 隔離コピーだけへ2件適用済み
- Retry / automatic reconnect: `0 / 0`
- 最大Provider Session: 3
- 最大送信音声: 合計300秒
- Cost hard limit: USD 0.05（保守単価で最大見積USD 0.0485）
- 準備完了時のProvider request / audio send: `0 / 0`

## 3｜Human操作前の画面準備

1. ChromeまたはEdgeで `https://localhost:8443/company/co` を開く。
2. localhost証明書の警告が表示された場合だけ、詳細表示からlocalhostへ進む。外部サイトの警告では進まない。
3. ログイン画面が出た場合は、普段のlocal Company OSアカウントでログインする。
4. 「COに相談」で既存のShared Conversationを開く。なければ次を一度に入力して作成する。
   - Name: `P1-J Human UX確認`
   - Purpose: `Realtime音声のHuman UXとCurrent Relay live integrationを確認する`
5. 「Prepare shared-room Session」を押す。
6. Recording / External ASR / Transcript Sharing / AI Referenceをすべて`Grant`にして「Record my consent decisions」を押す。
7. 「Start Session」を押す。
8. `Start realtime voice`が押せることを確認する。

ここまではProvider通信・音声送信を開始しない。

## 4｜最初に行う最小Gate

1. `Start realtime voice`を1回だけ押す。
2. Browserのマイク許可は「許可」を押す。
3. 次を自然な速さで1回だけ、約8〜10秒話す。

   `これはリアルタイム音声確認です。今日は九月三十日です。よろしくお願いします。`

4. 話しながら次を確認する。
   - Waveformが声に合わせて動く
   - Capture: `continuous`
   - Relay: `authorized WSS`
   - Provider: `ready`
   - Transcript: `partial / ephemeral`
   - 話した日本語の一部がPartial欄へ表示される
5. Partialは通常数秒以内を目安とし、10秒待っても出ない、`Safe stop`、`unavailable`、`rejected`、予期しない切断のいずれかが出た場合は、**Startをもう一度押さない**。

最小Gateが失敗した場合は、表示中の4状態とstatus文を残したままスクリーンショットを撮り、人＋ChatGPT Reviewへ戻る。追加Provider Requestを行わない。

## 5｜最小Gateが成立した場合の継続確認

最小Gateが成立した場合だけ、同じCaptureのまま次を続ける。

1. `続けて確認します。確定した文章だけを記録してください。`と話す。
2. `Stop normally`を1回押し、最大12秒待つ。
3. 次を確認する。
   - Partial表示が消える
   - Transcriptが`durable final`
   - Durable Final一覧へ確定文が表示される
   - Providerが`closed`、Captureが`stopped`
4. 画面を再読込し、Session Transcriptに確定文と匿名speaker表示があることを確認する。
5. `Pause`を押し、Stateがpausedになったことを確認する。続いて`Resume`を押し、activeへ戻ることを確認する。
6. 2回目の`Start realtime voice`で短く話し、別タブへ切り替えてから戻る。Background移行でCaptureがtruthfulに停止し、勝手に再接続しないことを確認する。失敗表示時は再試行しない。
7. 3回目を開始できた場合、Partial表示中に`COに相談`へ`いま確定している内容を一文で整理して`と入力し、「現在の確定TranscriptでCOに相談」を1回押す。bounded finalization grace後も未確定Partialが正式記録として混入しないことを確認する。
8. 最後に`End Session`を1回押し、ended状態を確認する。

最大3 Provider Sessionを超えない。失敗後の再試行、Browser更新によるやり直し、追加端末での同時実行は行わない。

## 6｜PASS / FAIL基準

### PASS候補

- 最小GateのMic → Capture → same-origin WSS → Relay → Deepgram acceptance → Partialが成立する。
- 同一MediaStreamでWaveformと音声frameが動作する。
- verified Source Rangeに対応するFinalだけがDurable Finalへcommitされる。
- normal stop、Pause / Resume、Background / Foreground、End、CO graceがtruthfulな状態を示す。
- 自動retry / reconnect、raw audio永続化、Credential露出がない。

### FAIL / INCONCLUSIVE

- Provider acceptance前、Partial前、Source mapping、Durable Finalのいずれかが確認できない。
- UIが成功を装う、Partialを確定扱いする、停止後に送信を続ける、またはEvidenceが不足する。
- この場合は推測でPASSにせず、追加Requestなしで停止する。

## 7｜Automated Verification済み / Humanでのみ確認するもの

Automated済み: single `getUserMedia`、AudioWorklet PCM 16 kHz mono 100 ms、same-stream Waveform、WSS/bridge fence、DPAPI server-held Credential、`mip_opt_out=true`、retry/reconnect 0、source cursor、verified lineage、Durable Final transaction、stop handshake、truthful fail-closed表示。

Humanのみ: 実端末のpermission UX、実マイク音量とWaveformの体感、日本語Partial/Finalの見え方、操作待ち時間、Pause/Resume・Background/Foregroundの理解しやすさ、CO相談までの一連の使いやすさ。

## 8｜停止条件

このPackage提示時点で、Humanが実際にマイクへ話す直前で停止する。Public Push、Production DB、Production Credential、Deploy、Public Port、Tunnel、Firewall変更は行わない。
