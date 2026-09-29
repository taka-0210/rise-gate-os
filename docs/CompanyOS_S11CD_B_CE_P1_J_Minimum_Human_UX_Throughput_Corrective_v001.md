# Company OS｜CE-P1 P1-J Minimum Human UX Throughput Corrective v001

記録日時：2026-09-30 08:24 JST
基準HEAD：2fda0aa
対象：P1-J Minimum Human UX Gate（Human Start 1回）

## 1｜Disposition

- P1-I：**INCONCLUSIVE / Technical Verification Required**を維持
- P1-J：**AUTHORIZED WITH KNOWN P1-I LIMITATION / Minimum Gate INCONCLUSIVE**
- Deepgram Provider Failure：**NOT ESTABLISHED**
- Current Relay live end-to-end PASS：**NOT ESTABLISHED**
- 今回承認されたHuman Start：1 / 1 consumed
- retry / reconnect / resend：0 / 0 / 0
- Corrective中のProvider通信 / 音声送信：0 / 0

Minimum Gate全体はPartial未確認のためPASSにしない。ただし、次の個別Evidenceは成立した。

- HumanがMicrosoft Edge desktopで発話
- 同一MediaStreamによるWaveform変化をHumanが確認
- Browser Capture：continuous
- same-origin WSS：authorized WSS
- Provider acceptance：ready
- Transcript state：listening
- mip_opt_out=true
- reconnect：0
- Providerへ送信済み：144000 samples / 9.0 seconds

## 2｜Sanitized Runtime Evidence

| 項目 | 結果 |
|---|---|
| runtime ready | 2026-09-30 07:46:56 JST |
| Provider accepted | 2026-09-30 08:02:26 JST |
| Session close | 2026-09-30 08:03:51 JST |
| Close mode | normal=false / hard abort |
| Provider session | 1 |
| Samples sent | 144000 |
| Audio duration sent | 9.0 seconds |
| Partial displayed | 0 / 未成立 |
| Durable Final | 0 / 未成立 |
| persisted Provider event Evidence | 0 |
| late internal event route attempts | 9（hard abort後。event typeはUnknown） |
| actual billed cost | Unknown |
| audio-based conservative estimate | USD 0.001455 |

Raw Credential、Authorization Header、raw Provider payload、raw realtime audio、transcript本文はEvidenceへ保存していない。

## 3｜Root Cause

Providerは接続を受理し、音声も受信した。失敗地点はProvider handshakeではなく、Current Relayのlocal lineage transportである。

1. Browserは100msごとにPCM frameを生成した。
2. Relayは各frameについて、Laravelへframeとsentの2 HTTP requestを直列実行した。
3. local runtimeでは動的requestが概ね約0.5秒単位で進み、85秒のwall timeに対してProviderへ送信できた音声は9秒分に留まった。
4. Provider event handlerはevent到着時点の送信済み範囲ではなく、その時点でBrowserから受信済みの将来frameを含むmessageChain全体を待っていた。
5. そのためPartial / FinalのUI projectionは大きなframe backlogの後ろへ遅延した。
6. hard abort後にinternal event requestが到着したため、canonical Transcriptへはcommitされなかった。

この結果はDeepgram Provider Failureを示さない。

## 4｜Provider-free Corrective

Product Contract、100ms frame、Ground Truth、Provider設定、Credential、Migrationは変更していない。

- 100ms Source Rangeを10件単位のatomic batchでLaravelへ渡す
- Source Rangeは引き続き100msごとに個別保存
- PCMはloopback internal requestのmemory上でのみ受け渡し、永続化しない
- Laravel側でも各PCMのbyte長とSHA-256を独立再検証
- Provider sessionのstream / generationと各frameを再照合
- gap / overlap / identity conflict / stale leaseはbatch全体をrollback
- Provider送信後のSend Rangeも10件単位でatomic保存
- memory queueは最大30 framesでFail Closed
- hard abort時は未送信queueを破棄し、新規Provider egressを停止
- Provider eventは「event到着時点で実際に送信済みのEvidence watermark」だけを待つ
- event到着後の将来frameはPartial / Finalをブロックしない

## 5｜Verification

- PHP syntax：PASS
- Laravel Pint：PASS
- Node relay：17 PASS
- CE-P1 focused：11 PASS / 1 gated live test SKIP / 142 assertions
- batch atomic rollback：PASS
- 10個の100ms Source Range保持：PASS
- Send ordinal 1..10：PASS
- Source mapping 0..16000 samples verified：PASS
- future batchから独立したevent watermark：PASS
- full Laravel regression：670 PASS / 17 SKIP / 1 known out-of-scope FAIL / 5458 assertions
- known failure：CompanyNavigationTest::regular login ignores a stale forbidden intended url
- Corrective起因の新規Regression：0
- Secret scan：実値0（既存synthetic placeholder 3件のみ）
- Repository .env / normal local DB SHA-256：変更なし
- loopback listener：停止済み

## 6｜Recommended Next Action

**修正済みRuntimeで、P1-J Minimum Human UX GateのHuman Startを最大1回だけ新たに承認することを推奨する。**

Synthetic Provider Requestの追加は推奨しない。次回はActual Product Pathで次だけを最初に確認する。

Mic → Waveform → same-origin WSS → Relay → Provider acceptance → Partial

条件：

- Human Start最大1回
- retry / automatic reconnect / manual reconnect / resend：0 / 0 / 0 / 0
- mip_opt_out=true
- 既存DPAPI Evaluation Credentialのみ
- Cost hard limit：USD 0.05
- raw Credential / Header / payload / realtime audioを永続化しない
- Partial成立までは画面をBackgroundへ移さない
- PASS / FAIL / INCONCLUSIVEのいずれでも再Startしない

Partial成立時だけ、同一Session / StreamでP1-J Human UX Verificationを継続する。成立しない場合は停止し、追加Provider通信なしでEvidenceを分類する。

この推奨は新しいHuman Startの承認ではない。Human + ChatGPT Review待ちで停止する。

Public Push、Production DB、Production Credential、Deploy、Azure / 他Providerは未実施・未承認のまま維持する。
