# Company OS｜CE-P1 P1-J Minimum Human UX Gate｜Outcome v001

記録日時：2026-09-30 05:56 JST

## 判定

- P1-J Minimum Gate：`INCONCLUSIVE / TECHNICAL VERIFICATION REQUIRED`
- Provider Failure：`NOT ESTABLISHED`
- P1-I：`INCONCLUSIVE / TECHNICAL VERIFICATION REQUIRED`を維持
- 再試行：`0`（追加Request未承認）
- Current Company OS Relay live integration PASS：`NOT ESTABLISHED`

## Human観察

- Microsoft Edge desktopを使用した。
- `Start realtime voice`は1回だけ操作した。
- HumanはWaveformの変化を確認できなかった。
- 日本語Partialは表示されなかった。
- 再度Startは操作していない。

## Sanitized Evidence

| 項目 | 結果 |
|---|---|
| Browser microphone API | MediaStream取得後にserver streamを作成する実装順序とserver stream作成記録から、permission取得は成立したと推定。ただしHuman Waveform Evidenceは不成立 |
| Browser → same-origin WSS | 成立。Relay bridge `open`まで到達 |
| Relay Provider session record | 1 |
| Provider connection attempt | 旧Evidence Contractではexact field欠落のため`Unknown`。推測でPASS/FAILにしない |
| Provider acceptance | `false` |
| Source ranges | 0 |
| Provider send ranges | 0 |
| Sent samples | 0 |
| Provider event receipts | 0 |
| Partial / Final | 0 / 0 |
| Durable Final commits | 0 |
| Close | `hard_abort` / fail closed |
| Provider usage Evidence | なし |
| Audio-based estimated cost | USD 0.00（0 samples）。実請求はUsage Evidence不在のためUnknown |

Raw Credential、Authorization Header、raw Provider payload、raw realtime audioはEvidenceへ保存していない。

## Root Cause Classification

Current RelayはProvider acceptance前、音声送信前にSDK/transport開始境界で停止した。旧P1-J Product RelayはSDK `ErrorEvent`のnested `error.message`を正規化せず、`unknown_runtime_failure`へ縮退させた。このため、Authentication rejection、request rejection、transport failure、SDK runtime failureのいずれかは確定できない。

これはDeepgram Provider FailureのEvidenceではなく、Current Relay側のFailure Evidence completeness不足である。

## Provider-free Corrective

- P1-Iで検証済みの`safeFailureReason`をP1-J Product Relayでも共用
- nested SDK `ErrorEvent`、Error、string、close reasonをsanitized化
- `failure_stage`を追加
- `provider_connection_attempted`を追加
- `provider_accepted`、`samples_sent`を同一Envelopeへ保存
- diagnostic不足時は`evidence_completeness=incomplete`
- UnknownをPASSまたはProvider Failureへ推測しない
- Secret redactionをSynthetic Testで確認

Provider通信0件でNode Synthetic / Regressionを実施し、`14 PASS`。DPAPI Credentialはraw値を表示せず復号可能=`true`を確認した。

## Recommended Next Action

**追加のDeepgram Requestは自動実施しない。** 現在のP1-Jを停止し、人＋ChatGPT Reviewへ戻す。

次のHuman Gateで再開する場合は、修正済みEvidence Contractを有効にした新しいloopback runtimeで、同じMinimum Gateを最大1回だけ実施することを推奨する。条件はretry / reconnect / resendすべて0、最初の`Mic → WSS → Relay → Provider acceptance → Partial`だけを確認し、PASS / FAIL / INCONCLUSIVEのいずれでも再送しない。

Public Push、Production DB、Production Credential、Deployは未実施・未承認のまま維持する。

## Re-authorization / New Runtime Ready

人＋ChatGPT Reviewにより、修正済みEvidence ContractでのP1-J Minimum Human UX Gate最大1回が再承認された。

- 起動日時：2026-09-30 06:05 JST
- Evidence Contract：`p1-j-failure-v2`
- 必須field：`failure_stage` / `provider_connection_attempted` / `provider_accepted` / `samples_sent` / sanitized `reason` / `evidence_completeness`
- Listener：`127.0.0.1:8443`、Laravel upstream：`127.0.0.1:8765`
- HTTPS login form：`https://localhost:8443/login`
- retry / reconnect / resend：`0 / 0 / 0`
- Provider requests at ready：`0`
- Audio seconds at ready：`0`
- 状態：`READY_FOR_HUMAN_MICROPHONE_GATE`

P1-Iは`INCONCLUSIVE / Technical Verification Required`、P1-Jは`AUTHORIZED WITH KNOWN P1-I LIMITATION`を維持する。

## Re-authorized Minimum Gate Outcome

再承認された最大1回を、2026-09-30 06:29 JSTにMicrosoft Edge desktopから実施した。

- マイク許可promptは表示されなかった。Edgeに既存の許可状態が保存されていた可能性はあるが、許可成立を推測でPASSにはしない。
- Humanは指定文を発話し、待機した。
- Waveformの変化はなく、日本語Partialも表示されなかった。
- `Start realtime voice`の再操作、retry、reconnect、resendは行っていない。

修正済みEvidence Contractは、失敗を次のとおり完全に保存した。

| 項目 | Re-authorized run |
|---|---|
| failure stage | `provider_open_wait` |
| sanitized reason | `Unexpected server response: 400` |
| Provider connection attempted | `true` |
| Provider accepted | `false` |
| Audio samples sent | `0` |
| Evidence completeness | `complete` |
| Provider session | 1 / `failed` |
| Source ranges / send ranges | 0 / 0 |
| Event receipts / Durable Final commits | 0 / 0 |
| Partial / Final | 0 / 0 |
| retry / reconnect / resend | `0 / 0 / 0` |

したがって、画面が変化しなかった原因はHumanの発話音量や日本語認識ではない。Current RelayのDeepgram handshake requestがHTTP 400で拒否され、Provider acceptance前かつ音声送信前にFail Closedしたためである。これはCurrent Relay request compatibility failureであり、Deepgram Provider Capability Failureとは判定しない。

P1-Iは`INCONCLUSIVE / Technical Verification Required`、P1-Jは`AUTHORIZED WITH KNOWN P1-I LIMITATION`のまま維持する。Current Relay live integration PASSは成立していない。

## Provider-free Request Compatibility Corrective

固定済み公式SDK `@deepgram/sdk@5.10.0`の型・serializerと、実Provider接続PASS済みのCE-PD08B E1 requestをProvider通信なしで比較した。

- CE-PD08B E1成功request：`diarize_model=latest`のみ
- 失敗時Current Relay request：deprecated `diarize=true`と`diarize_model=latest`を同時指定
- SDK v5 Contract：`diarize_model`の指定自体がdiarizationを有効化し、deprecated `diarize=true`の併記は不要

HTTP 400と一致する最も強いRequest compatibility差分として、Deepgram Adapterのactual SDK requestからdeprecated `diarize` queryだけを除去した。Provider-neutral側の「diarization必須」、`diarize_model=latest`、Nova-3、Japanese、PCM linear16 / 16 kHz / mono、`mip_opt_out=true`、retry / reconnect 0は維持した。

Pinned SDKが生成するProvider-free queryを直接検査するAutomated Testを追加し、次を確認した。

- `diarize`：不存在
- `diarize_model=latest`：1方式のみ
- `mip_opt_out=true`
- `model=nova-3` / `language=ja`
- `encoding=linear16` / `sample_rate=16000` / `channels=1`
- Socketはstart closed、Provider通信0件
- Node relay tests：`15 PASS`
- CE-P1 focused：`9 PASS / 1 Gate skip`
- 全Laravel regression：`668 PASS / 17 skip / 1 known CE-P1外 failure`
- 既知failure：`CompanyNavigationTest::regular login ignores a stale forbidden intended url`。今回変更によるRegressionではない
- Secret pattern scan：PASS

このCorrective中のProvider通信 / 音声送信は`0 / 0`。Raw Credential、Authorization Header、raw Provider payload、raw realtime audioは保存していない。承認済み1回の終了後、loopback runtimeは停止した。

## Recommended Decision Package

**同じP1-J Minimum Human UX Gateを、新しいloopback runtimeで最大1回だけ再承認することを推奨する。**

理由：Provider-free検証により、過去E1成功requestとCurrent Relayの差分を解消し、SDK生成queryも決定的に検証できた。ただしHTTP 400の解消、Provider acceptance、Partialはlive Current Relay pathでのみ確定できるため、CorrectiveだけでP1-I/P1-JをPASSにはしない。

推奨条件：

- Humanの`Start realtime voice`：最大1回
- 最初に確認する経路：`Mic → same-origin WSS → Relay → Provider acceptance → Partial`
- retry / automatic reconnect / manual reconnect / resend：`0 / 0 / 0 / 0`
- `mip_opt_out=true`必須、既存DPAPI Evaluation Credentialのみ
- 推奨発話：10〜20秒、上限300秒
- Cost hard limit：USD 0.05（10〜20秒の想定costはUSD 0.01未満）
- PASS / FAIL / INCONCLUSIVEのいずれでも再送しない
- Missing Evidenceを推測でPASSまたはProvider Failureにしない

新しいHuman Gateが承認されるまでProvider通信は行わない。Public Push、Production DB、Production Credential、Deploy、Azure / 他Provider通信も未実施・未承認のまま維持する。
