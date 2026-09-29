# Company OS｜S11CD-B Realtime Corrective Decisions / Pending v001

- Document status: **DECISION REGISTER CANDIDATE / REVIEW REQUIRED**
- Date: 2026-09-29 JST
- CE-G01: **CLOSED**
- CE-P1: **NOT STARTED**
- Design source: `CompanyOS_S11CD_B_CE_Realtime_Corrective_Design_v001.md`
- Provider communication: **Deepgram 0 / Azure 0**
- Audio send: **Deepgram 0 / Azure 0**

## 1. Register rules

- Product Contractへ影響する事項は人＋ChatGPTが決める。
- Product Contractを変えないimplementation detailは推奨案と理由を提示し、CE-P1開始時にtechnical verificationで確定する。
- `Design Candidate Ready`はBlocker Resolved / PASSではない。
- Blockerは人＋ChatGPTが設計を承認した後にだけ`Resolved by Approved Design`候補へ移せる。
- 設計承認後も、Implementation / Automated / Provider / Human UX Evidenceが必要な項目はOPEN verification itemとして残す。
- CE-G01を本書でOPENしない。

## 2. Count summary

| Register | Count | Current state |
|---|---:|---|
| Product Decision Pending | 7 | Human + ChatGPT decision required |
| Technical Pending | 10 | Recommended candidateあり / verification required |
| Compatibility Blocker | 8 | OPEN / design candidate prepared |
| CE-G01 | 1 | CLOSED / human gate required |

## 3. Product Decision Pending

| ID | Decision | Options / recommended candidate | Why Product decision | Blocks |
|---|---|---|---|---|
| P-01 | Pause / normal End / browser disconnect時に、既送信audioのfinal-only drainを許可するか | **推奨:** 新規audioを即時停止し、authorization/ConsentがcurrentなPause/Endだけbounded drain。Cancel/revokeはhard abort | Userが「停止」と理解する意味、privacy、最新発話包含に影響 | CE-C01、CO |
| P-02 | Deepgram/Relay failure後にBrowser captureを継続するか | **推奨:** captureも停止し、Text Conversationのみ継続。明示retryでnew generation | 文字起こしされない音声を取り続ける誤認・privacyに影響 | CE-C08、No Fallback UX |
| P-03 | background / foreground / installed PWAでcaptureを継続するか | **推奨安全側:** hidden/OS suspensionを検知したらpause/closeし、foregroundで明示resume | iPhone/PWA制約と利用者期待に影響 | CE-C01、CE-C08、HUX |
| P-04 | CO bounded finalization graceの上限と表示 | 秒数は本設計で固定しない。Human UX測定後にbounded値を決定 | 会話応答性と最新発話包含のProduct tradeoff | CO-D01 |
| P-05 | Source/Provider/Commit receipt、speaker hint、usage/errorのretention / deletion relation | **推奨:** data class別最小期間、raw audio/payloadなし、session deletion連動を基準にPrivacy Review | 監査、privacy、契約、運用コストに影響 | MIG-D01、DH-D01 |
| P-06 | Realtime導入後の旧55秒bounded path | 選択肢: 撤去 / 非Realtime用途へ隔離 / Migration期間だけ共存。**禁止:** silent fallback | 既存利用、説明責任、運用scopeに影響 | rollout / regression |
| P-07 | CO grace timeout / Provider error / invalid range時の動作 | **推奨:** partial除外をtruthfulに表示し、押下時base durable snapshotで相談続行。別案はUser再確認 | User intentとCO回答contextに影響 | CO-D01、CE-C08 UX |

Product Decisionが未確定でもCorrective Design Reviewは可能だが、関連実装scopeを開始する前に解消または明示的に後工程へ分離する。

## 4. Technical Pending

| ID | Technical item | Recommended candidate | Verification / stop condition | Related design |
|---|---|---|---|---|
| T-01 | Realtime Relay runtime topology | existing HTTPS reverse proxy配下のsame-origin WSS + isolated persistent relay worker候補。新公開portなし | WSS対応、persistent process / proxy / infra承認が必要なら実装前停止 | C01-D01/D02 |
| T-02 | Lease TTL / refresh / forced-close latency | single-digit秒refresh、短いdouble-digit秒以下TTLのconfigurable候補 | fault testでDB負荷、jitter、revoke latencyを測定。数値をProduct SLA化しない | C01-D01 |
| T-03 | Canonical audio frame format | PCM signed 16-bit / 16 kHz / mono候補、bounded 20–100ms frame | Desktop/iPhone/PWAと日本語品質をverify。追加codec/package承認が必要なら停止 | C03-D01/C08-D01 |
| T-04 | Out-of-order reorder buffer | bounded memory + short timeout。未解決gapはnew generation | memory/latency/fault test。架空silence補完禁止 | C03-D01 |
| T-05 | Deepgram timing → source cursor mapping | provider send ledgerを介したdeterministic sample mapping。verified以外commit不可 | existing Minimum Connectivity Evidence / official protocolで精度確認。不足時は新Provider送信前に停止 | C03-D01/D02/DG-D01 |
| T-06 | Writer transaction / checkpoint integration | sole `RealtimeDurableFinalCommitter`、unique operation、existing Writer内transactionへ統合 | partial commit、retry、DB failure、Long Context checkpoint test | C03-D03/CO-D01 |
| T-07 | Browser continuous capture engine | same MediaStream + AudioWorklet候補。unsupportedならtruthful unavailable、batch fallbackなし | Chrome/Edge/iPhone Safari/PWA compatibility。新package必要時停止 | C08-D01/D02 |
| T-08 | Control outbox / bus / connection registry | transactional outbox + process-local registry + reliable internal delivery候補 | existing infraで成立しない、外部service/portが必要なら停止 | C01-D02 |
| T-09 | Final timing/speaker serialization and cleanup | normalized final-only encrypted data、safe code、class別deletion job | encryption format / key boundary / retention decisionをverify | C03-D02/DH-D01 |
| T-10 | Accessible partial/waveform update cadence | visual partialは高頻度、screen reader announcementはdurable final/state changeへ抑制 | reduced motion、keyboard、screen reader Human UX | C08-D02/D03 |

Technical Pendingは推奨案を採用してよい候補だが、Closed Contract、security、Product Decisionに抵触した場合は自動決定せず停止する。

## 5. Compatibility Blocker register

| Blocker ID | Original finding | Corrective design | Candidate status | Resolved? |
|---|---|---|---|---|
| CE-B-C01-01 | DB interruptだけでProvider egress停止を保証できない | C01-D01 lease + C01-D02 control/forced close + C01-D03 late/reconnect | **Design Candidate Ready** | **NO — OPEN** |
| CE-B-C03-01 | exact continuous source rangeがない | C03-D01 source cursor + send ledger + MIG-D01 | **Design Candidate Ready** | **NO — OPEN** |
| CE-B-C03-02 | provider-neutral streaming receiptがない | C03-D02 two-level normalized receipt | **Design Candidate Ready** | **NO — OPEN** |
| CE-B-C03-03 | Provider FinalとRevisionのone-to-one commit evidenceがない | C03-D03 committer + commit/item receipt | **Design Candidate Ready** | **NO — OPEN** |
| CE-B-C08-01 | 55秒stop/upload/awaitでcontinuous captureでない | C08-D01 same-stream continuous pipeline | **Design Candidate Ready** | **NO — OPEN** |
| CE-B-C08-02 | audio energy / visible waveformがない | C08-D02 local Web Audio + accessible visible UI | **Design Candidate Ready** | **NO — OPEN** |
| CE-B-C08-03 | realtime partial/final projectionがない | C08-D03 ephemeral partial / committed final | **Design Candidate Ready** | **NO — OPEN** |
| CE-B-CO-01 | graceとdurable context snapshotが未確定 | CO-D01 base snapshot → targeted grace → final snapshot | **Design Candidate Ready** | **NO — OPEN** |

## 6. Gate candidate by area

4つの候補状態を次の意味で使う。

- **Design Candidate Ready**: 実装可能な設計候補を人＋ChatGPT Reviewへ提出できる。
- **Product Decision Required**: Product意味・UX・retention等の人判断が必要。
- **Technical Verification Required**: 実装・fault test・browser/provider evidenceで成立確認が必要。
- **Still Blocked**: 設計候補自体が成立していない、または停止条件に該当する。

| Area | Primary candidate state | Product | Technical / Evidence | Blocker state |
|---|---|---|---|---|
| CE-C01 | **Design Candidate Ready** | P-01/P-03 | T-01/T-02/T-08、forced-close実測 | OPEN |
| CE-C03 Source Cursor | **Design Candidate Ready** | P-05 | T-03/T-04/T-05、Migration/Range verification | OPEN |
| CE-C03 Receipt/Commit | **Design Candidate Ready** | P-05 | T-05/T-06/T-09、atomic/duplicate tests | OPEN |
| CE-C08 Capture/Waveform/UX | **Design Candidate Ready** | P-02/P-03/P-06 | T-03/T-07/T-10、Automated + Human UX | OPEN |
| CO Finalization Grace | **Design Candidate Ready** | P-01/P-04/P-07 | T-06、timeout/late/revoke verification | OPEN |
| Deepgram Adapter/MIP | **Design Candidate Ready** | Provider decision済み | T-05、synthetic + approved Provider Evidence | OPEN verification item |
| CE-G01 | **Still Closed** | 人＋ChatGPT Gate decision | checklist review required | CLOSED |

現時点で`Still Blocked`に分類すべき「Corrective Design不成立」は確認していない。ただし、すべてのCompatibility Blockerは設計未承認のためOPENであり、CE-P1は開始できない。

## 7. Migration decision candidate

`MIG-D01`はadditive designとして成立候補。Migrationの作成・実行承認ではない。

必要候補:

- relay leases
- relay control events / transactional outbox
- source ranges
- provider sessions
- provider send ranges
- provider event receipts
- durable final commits
- durable final commit items

Safety decisions:

- past bounded transcriptはbackfillしない。
- new relationは既存recordに対してoptional。
- feature activation前にschemaとold application compatibilityを確認。
- rollbackはfeature OFF、new table保持。即時dropしない。
- destructive cleanupは別Decision / Migration。

## 8. Evidence still required after design approval

| Layer | Required evidence |
|---|---|
| Product Design | Pending decision resolution、approved diagrams/contracts、Master update要否の別判断 |
| Repository | schema/code/adapter/UIがapproved designと一致するdiff |
| Automated | authorization/revoke/range/order/duplicate/transaction/cleanup/regression tests |
| Provider | actual request MIP、connection/events/timing/usageのlimited approved evidence |
| Human UX | Desktop/390/iPhone/PWA、waveform、partial/final、lifecycle、CO grace |

いずれか一層だけでDoneにしない。

## 9. Stop conditions

次が判明した場合は`Still Blocked`へ変更し、人＋ChatGPT Reviewへ戻す。

- Product Contract変更が必要
- Closed Contractとの矛盾
- security / provider-neutral boundaryを維持できない
- destructive Migrationが必要
- existing Writer / Revision / lineageを再利用できない
- BrowserへProvider Secretが必要
- new public network / port / tunnel / firewall変更が必要
- new Provider Evaluationが必要

## 10. Review outcome placeholder

人＋ChatGPT Reviewだけが以下を記録できる。

- Design: Approved / Revision Required / Rejected
- Product Pending: resolved / deferred with boundary
- Blocker: `Resolved by Approved Design` / OPEN
- CE-G01: OPEN / CLOSED
- CE-P1: authorized / not authorized

現時点の値:

- Design: **Candidate only**
- Compatibility Blockers: **8 OPEN**
- CE-G01: **CLOSED**
- CE-P1: **NOT STARTED**
