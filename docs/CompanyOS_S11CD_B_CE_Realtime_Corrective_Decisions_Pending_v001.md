# Company OS｜S11CD-B Realtime Corrective Decisions / Pending v001

- Document status: **PRODUCT DECISIONS INCORPORATED / TECHNICAL FINAL REVIEW REQUIRED**
- Review date: 2026-09-29 JST
- CE-G01: **CLOSED**
- CE-P1: **NOT STARTED**
- Corrective Design recommendation: **APPROVE candidate（not Approved）**
- Provider communication: **Deepgram 0 / Azure 0**
- Audio send: **Deepgram 0 / Azure 0**
- Public GitHub push: **0 / prohibited**

## 1. Register rules

- P-01〜P-07は人＋ChatGPT ReviewでResolved。Product Decision Pendingへ戻さない。
- P-04の秒数とP-05の保持期間等は`Approved Principle + Deferred Parameter`として管理する。
- Technical choiceが新Infra、persistent process、external service、public port、package、SDK、security boundaryを生む場合は、人＋ChatGPT承認前に採用しない。
- `Resolved by Approved Design candidate`はBlocker Resolved / Implementation Done / PASSではない。
- Corrective Design全体の最終承認までは8 BlockerをOPEN維持する。
- CE-G01を本書でOPENしない。

## 2. Count summary

| Register | Count | Current state |
|---|---:|---|
| Product Decision Pending | **0** | P-01〜P-07 resolved |
| Approved Product Decisions | 7 | Corrective Designへ同期済み |
| Deferred UX / Operational Parameters | 2 groups | P-04 numeric / P-05 privacy-retention detail |
| Technical Pending | 10 | classified below |
| Compatibility Blocker | 8 | OPEN / Resolved by Approved Design candidate |
| CE-G01 | 1 | CLOSED / Final Gate Review required |

## 3. Human + ChatGPT Product Decision Review result

| ID | Final decision | State |
|---|---|---|
| P-01 | Pause / Normal Endは新規audio生成・egressを即時停止し、current authorization / Consent下で既送信audioのbounded final-only drainだけを許可。drain完了/timeout後close。Cancel / RevokeはFinalize待ちなしのHard Abort | **RESOLVED** |
| P-02 | Deepgram / Relay failure時はCaptureも停止。No fallback、Text Conversation継続、truthful表示、auto retryなし。明示retryはnew generation / lease / Provider Session | **RESOLVED** |
| P-03 | background / OS suspension時はPause / Close。録音中と誤表示せず、foregroundで自動Resumeしない。明示Resume時にcurrent authorization / Consent再確認 | **RESOLVED** |
| P-04 | bounded finalization graceをProduct Contractとして採用。具体秒数はHuman UX Verificationで決定 | **RESOLVED / Numeric deferred** |
| P-05 | data class別の必要最小保持、raw realtime audio / raw payload / partial非永続、session relation、Transcript lineage非連動削除を採用。期間/暗号化/legal/cascade detailはPrivacy / Release Review | **RESOLVED / Operational detail deferred** |
| P-06 | 旧55秒pathはRealtime実装・Regression・Provider/Human UX Evidence期間だけ隔離保持。User選択やsilent fallbackに使わず、Evidence成立後の撤去は別Cleanup / Migration承認 | **RESOLVED** |
| P-07 | grace失敗時はpartialを昇格・推測せずtruthfulに扱い、押下時base durable snapshotでCO Requestを続行。late finalは進行中Requestへ追加しない | **RESOLVED** |

## 4. Approved Principle + Deferred Parameter

| Origin | Approved now | Deferred without reopening Product Pending | Evidence owner |
|---|---|---|---|
| P-04 | graceは必ずbounded、partial非昇格 | concrete duration / user-perceived wait | Human UX Verification |
| P-05 | minimum retention、raw/partial非永続、session boundary | duration、encryption format、legal/audit preservation、cascade detail | Privacy / Release Review |

実装・VerificationでProduct Principle自体の変更が必要になった場合だけ、新しいProduct Decisionとして人＋ChatGPTへ戻す。

## 5. Technical Pending T-01〜T-10 review

| ID | Topic | Recommended Candidate | Human Approval Before CE-P1 | CE-P1 Technical Verification | Stop Condition |
|---|---|---|---|---|---|
| T-01 | Realtime Relay runtime topology | existing HTTPS reverse proxy配下のsame-origin WSS + isolated persistent relay worker。new public portなし | **Required** | approved topology上でprocess lifecycle、capacity、failure recoveryをverify | persistent process/WSS/reverse proxy/process manager境界が未承認、public port/tunnel/firewallが必要 |
| T-02 | Lease TTL / refresh / forced-close latency | configurable short TTL + refresh。control eventをprimary、expiryをsafety net | **Not Required**（Product SLA化時はRequired） | fault testでDB load、jitter、revoke latencyから値を決定 | safety boundを満たせない、値がProduct promiseへ影響 |
| T-03 | Canonical audio frame format | PCM signed 16-bit / 16 kHz / mono、bounded 20–100ms候補 | **Not Required**（new codec/package時はRequired） | Browser quality/bandwidth/sample-countをverifyし範囲内で決定 | Japanese quality不成立、iPhone/PWA非互換、新package/codec必要 |
| T-04 | Out-of-order reorder buffer | bounded in-memory reorder + short timeout。unresolved gapはclose/new generation、silence補完なし | **Not Required** | property/fault/load testでwindowを決定 | unbounded memory、latency悪化、架空audio補完が必要 |
| T-05 | Deepgram timing → source cursor mapping | provider send ledgerによるdeterministic sample mapping。`verified`以外commit不可 | **Not Required for CE-P1 synthetic work** | official protocol/existing Evidence→synthetic→approved limited real Evidenceでverify | exact/verified mapping不能、新Provider Evaluationが必要 |
| T-06 | Writer transaction / checkpoint integration | sole `RealtimeDurableFinalCommitter` + unique operation + existing Writer transaction/lineage | **Not Required** | rollback/retry/revoke race/multiple segment/CO snapshot tests | existing Writer/Revision/lineage再利用不能、destructive schema change必要 |
| T-07 | Browser continuous capture engine | native same-MediaStream Web Audio/AudioWorklet候補。unsupportedならtruthful unavailable | **Not Required for native API**（package/polyfill時はRequired） | Chrome/Edge/iPhone/PWA、cleanup、single stream、continuous frames | second mic acquisition、silent batch fallback、新package/polyfill必要 |
| T-08 | Control outbox / bus / connection registry | transactional DB outbox + relay connection registry。deliveryはapproved runtime内で実現 | **Required** | duplicate/loss/delay/worker restart/forced close ACKをverify | Redis等external service、新port、unapproved process/security boundaryが必要 |
| T-09 | Final timing / speaker serialization and cleanup | normalized final-only encrypted data + safe code + class-based cleanup | **Not Required using existing encryption boundary**（new KMS/service時はRequired） | existing Laravel encryption、redaction、deletion jobをverify | raw payload/partial保存が必要、key boundary変更、P-05原則不成立 |
| T-10 | Accessible partial / waveform update cadence | visual partialはfluid、screen readerはdurable final/important stateへ抑制 | **Not Required** | reduced motion、keyboard、screen reader、390px Human UXで調整 | truthful stateまたはaccessibilityを満たせない |

### 5.1 Human approval required before CE-P1 / relevant phase

| Approval ID | Required decision | Timing |
|---|---|---|
| H-TECH-01 | T-01 relay runtime、persistent process、same-origin WSS、reverse proxy、process manager boundary | **Before CE-G01 OPEN / CE-P1** |
| H-TECH-02 | T-08 control delivery topology。DB outboxだけか、internal busを使うか。external service追加有無 | **Before CE-G01 OPEN / CE-P1** |
| H-TECH-03 | Deepgram SDK vs direct protocol、runtime language、package/version、supply-chain boundary | **Before Deepgram Adapter implementation; Final Gateで方針承認候補** |
| H-TECH-04 | Additive Migrationの作成許可と8-table scope | **Before Migration creation** |
| H-TECH-05 | 正式Credential storeとEvaluation Credential非流用 | **Before Adapter network phase** |
| H-TECH-06 | Limited real Provider Verificationのaudio/data、attempt、cost、privacy、stop condition | **Before first Provider communication** |

### 5.2 Codex may decide inside CE-P1 with Evidence

- T-02 TTL / refreshの具体値（approved safety boundary内）
- T-03 frame duration / canonical formatの最終technical値（new packageなし）
- T-04 reorder buffer window
- T-05 mapping algorithm details（verified-only contractを維持）
- T-06 transaction / idempotency implementation detail
- T-07 native browser API implementation detail（package/polyfillなし）
- T-09 existing encryption boundary内のserialization detail
- T-10 visual/accessibility cadence

## 6. Compatibility Blocker register after Product Review

| Blocker ID | Candidate status | Technical | Provider | Human UX | Current resolution |
|---|---|---|---|---|---|
| CE-B-C01-01 | **Resolved by Approved Design candidate** | Required | forced-close real Evidence required | revoke/stop truthful UX required | **OPEN until design approval** |
| CE-B-C03-01 | **Resolved by Approved Design candidate** | exact range required | timing mapping required | not primary | **OPEN until design approval** |
| CE-B-C03-02 | **Resolved by Approved Design candidate** | receipt/order required | event shape Evidence required | not primary | **OPEN until design approval** |
| CE-B-C03-03 | **Resolved by Approved Design candidate** | atomic Writer tests required | not independently required | durable projection observation required | **OPEN until design approval** |
| CE-B-C08-01 | **Resolved by Approved Design candidate** | runtime/browser required | continuous streaming required | continuous capture required | **OPEN until design approval** |
| CE-B-C08-02 | **Resolved by Approved Design candidate** | repository/cleanup required | not required | waveform silence→speech→silence required | **OPEN until design approval** |
| CE-B-C08-03 | **Resolved by Approved Design candidate** | partial/final state required | realtime events required | perceived transition required | **OPEN until design approval** |
| CE-B-CO-01 | **Resolved by Approved Design candidate** | grace/snapshot tests required | final latency input required | grace/timeout UX required | **OPEN until design approval** |

Design approval後もTechnical / Provider / Human UX列はImplementation Done EvidenceとしてOPEN維持する。

## 7. MIG-D01 Final Readiness

### 7.1 Eight-table responsibility check

| Table candidate | Single responsibility | Overlap finding |
|---|---|---|
| relay leases | authorization capability and lifecycle | control event payloadを持たない |
| relay control events | transactional revoke/close outbox and ACK | lease current stateと分離 |
| source ranges | accepted Company OS audio sample Evidence | Provider offsetを正本化しない |
| provider sessions | adapter session / generation mapping | event/contentを持たない |
| provider send ranges | source sample ↔ provider audio offset ledger | source receipt/provider eventと多対多化しない |
| provider event receipts | normalized final/metadata/error/close Evidence | partial text/raw payloadを持たない |
| durable final commits | one accepted final → one Writer operation result | segment/revision明細を重複格納しない |
| durable final commit items | commit → one/many Segment/Revision links | commit headerと分離 |

8 tablesはlifecycle、cardinality、immutabilityが異なるため、Ver.1でも統合すると責任とunique constraintが曖昧になる。**table数変更なし**を推奨する。将来汎用化は行わない。

### 7.2 Readiness result

- Product decisions alignment: PASS
- source cursor / receipt / commit / lease / control boundary: PASS as design
- P-05 retention principle: PASS
- exact duration/encryption/cascade: deferred operational detail
- existing record compatibility / no fabricated backfill: maintained
- Migration design candidate: **DESIGN READY**
- Migration creation / execution: **NOT AUTHORIZED**

## 8. Infra / Runtime Gate

### 8.1 Existing environment can provide

- Laravel authorization / Consent / Writer / Revision / Long Context
- existing HTTPS origin and reverse proxy baseline
- Browser native `getUserMedia` / Web Audio candidates
- DB transaction / outbox tables candidate
- server-side secret configuration boundary

### 8.2 New runtime elements requiring approval

- persistent realtime relay process
- same-origin WSS endpoint and reverse proxy upgrade routing
- process manager / restart / health check
- cross-worker connection registry / control delivery method
- Deepgram SDK or direct protocol runtime/package

No new public port / tunnel / firewall changeを前提にしない。external service（Redis等）が必要と判明した時点で停止し、別承認を得る。

## 9. Deepgram implementation gate

Adapter implementation前に分離して承認する。

| Gate item | Current state |
|---|---|
| Production credential store | Human approval required; Evaluation Credential reuse prohibited |
| SDK vs direct protocol | **Recommended candidate only:** T-01でisolated Node relay workerが承認された場合、server-sideの公式`@deepgram/sdk` v5系を第一候補とする。actual requestのMIP guard、event identity、source mapping、retry禁止をSDK境界で保証できない場合だけdirect WSSを技術代替候補として比較する。Browser direct connectionは採用しない。現在は未承認 |
| Package / version | `@deepgram/sdk` v5系は候補のみ。exact versionはT-01とH-TECH-03承認後に固定し、lockfile・integrity・licenseを確認してから隔離導入する。現在は未選定・未導入 |
| Provider-neutral Port | Design Ready |
| `mip_opt_out=true` | builder + network-immediate double Fail Closed fixed |
| locale/model/diarization | `ja-JP` / Nova-3 Streaming / anonymous diarization profile candidate; adapter-only |
| Synthetic-only phase | first; Provider communication 0 |
| First network phase | separately approved Limited Real Provider Verification |
| Cost / attempt limit | explicit approval required before network |

候補根拠はDeepgram公式JavaScript SDK repository（Node.js 18+、Streaming WebSocket、AbortSignalによるautomatic reconnect無効化の記載）と公式Streaming STT資料である。候補化はPackage追加承認、T-01承認、Provider通信承認の代替ではない。

- Official SDK: <https://github.com/deepgram/deepgram-js-sdk>
- Official Streaming STT overview: <https://developers.deepgram.com/docs/getting-started-with-live-streaming-audio>

## 10. Waveform / Realtime UX gate

### 10.1 Fixed Product Contract

- one `getUserMedia`
- one MediaStream
- same-stream waveform
- browser-local energy
- second mic acquisition prohibited
- ASR wait must not stop Capture
- partial ephemeral
- only Durable Final canonical
- truthful independent Capture / Relay / Provider / Transcript state

### 10.2 Human UX adjustable parameters

- waveform animation speed / bar count / intensity
- local energy smoothing / visual threshold
- partial visual update cadence
- bounded grace concrete duration
- accessible announcement cadence

## 11. Master Update Candidate（not executed）

- Pause / Normal End bounded final-only drain vs Cancel / Revoke Hard Abort
- Provider / Relay failure stops Capture; Text Conversation remains
- background Pause / Close and explicit foreground Resume
- bounded CO finalization grace with deferred numeric value
- minimum retention / no raw audio-payload-partial persistence principle
- old 55-second path isolated during transition, then removed by separate approval
- grace failure continues CO with click-time base durable snapshot

Master v155 / Excel v050は今回変更しない。

## 12. CE-G01 OPEN readiness

CE-G01 OPENはImplementation Doneではなく、安全にCE-P1実装・Evidence取得を開始できる状態を意味する。

| Condition | State |
|---|---|
| Product Pending 0 | READY |
| Corrective Design recommendation | APPROVE candidate / final human approval pending |
| Compatibility Blocker design candidate | 8/8 ready / still OPEN pending approval |
| T-01 relay runtime approval | REQUIRED / pending Final Gate |
| T-08 control delivery approval | REQUIRED / pending Final Gate |
| Additive Migration creation approval | REQUIRED / not granted |
| SDK/direct protocol + package/version policy | REQUIRED before Adapter implementation / pending |
| CE-P1 Scope | REQUIRED / pending |
| CE-P1 Branch | REQUIRED / pending |
| CE-P1 Done Contract | candidate exists / final approval pending |
| Post-implementation Browser/iPhone/PWA/Provider/Human UX Evidence | CE-P1以降のDone condition。OPEN前PASSにはしない |

Current recommendation: **READY FOR HUMAN + ChatGPT FINAL GATE REVIEW / CE-G01 remains CLOSED**。

## 13. Review outcome placeholder

次回Reviewで人＋ChatGPTだけが確定できる。

- Corrective Design: Approved / Revision Required / Still Blocked
- Compatibility Blocker: Resolved by Approved Design / OPEN
- T-01 / T-08 / SDK-package / Migration scope: Approved / Revision Required
- CE-G01: OPEN / CLOSED
- CE-P1: authorized / not authorized

Current state:

- Product Decision Pending: **0**
- Technical Pending: **10 classified**
- Compatibility Blockers: **8 OPEN / Resolved by Approved Design candidates**
- Corrective Design: **APPROVE candidate**
- CE-G01: **CLOSED**
- CE-P1: **NOT STARTED**
