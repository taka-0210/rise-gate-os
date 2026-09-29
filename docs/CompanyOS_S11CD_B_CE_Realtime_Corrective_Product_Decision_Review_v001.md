# Company OS｜S11CD-B Realtime Corrective Product Decision Review v001

- Document status: **PRODUCT REVIEW INCORPORATED / FINAL GATE REVIEW READY**
- Review date: 2026-09-29 JST
- Corrective Design recommendation: **APPROVE candidate（Human + ChatGPT approval pending）**
- CE-G01: **CLOSED**
- CE-P1: **NOT STARTED**
- Product Decision Pending: **0**
- Technical Pending: **10 classified**
- Compatibility Blocker: **8 OPEN / Resolved by Approved Design candidates**
- Provider communication: **Deepgram 0 / Azure 0**
- Audio send: **Deepgram 0 / Azure 0**
- Public push / Deploy: **0 / 0**

## 1. Review scope

Human + ChatGPTでP-01〜P-07をReviewし、Corrective Design、Decisions Register、Future Codex Handoffへ同期した。今回はDocument / Design Reviewのみであり、Product Code、Migration、DB、Storage、Master、Provider Evaluation Evidenceを変更していない。

`CompanyOS_S11CD_B_CE_Realtime_Corrective_Design_v001.md`の基本Architectureを維持し、Product判断以上のscope拡張は行っていない。

## 2. P-01〜P-07 final decisions

| ID | Final Product Contract | Review result |
|---|---|---|
| P-01 | Pause / Normal Endは新規audio生成・egressを即時停止し、current auth/Consent下で既送信audioのbounded final-only drainだけを許可。Cancel / RevokeはHard Abort | RESOLVED |
| P-02 | Deepgram / Relay failure時はCaptureも停止。No automatic fallback、auto retryなし、Text Conversation継続、明示retryはnew generation / lease / Provider Session | RESOLVED |
| P-03 | background / OS suspension時はPause / Close。false active表示禁止。foreground自動Resumeなし、明示Resume時にauth/Consent再確認 | RESOLVED |
| P-04 | bounded finalization graceを採用。具体秒数は実装後Human UX Verificationで決定 | RESOLVED / Approved Principle + Deferred UX Parameter |
| P-05 | data class別最小保持、raw realtime audio / raw Provider payload / partial非永続、session relation、Transcript lineage保護 | RESOLVED / Approved Principle + Deferred Operational Detail |
| P-06 | 旧55秒pathをRealtime移行・Regression・Provider/Human UX Evidence期間だけ隔離保持。User選択やsilent fallback禁止。Evidence成立後の撤去は別承認 | RESOLVED |
| P-07 | grace失敗時はpartialを昇格・推測せず、click-time base durable snapshotでCO Requestを続行。late finalは進行中Requestへ追加しない | RESOLVED |

Product Decision Pendingは`7 → 0`。P-04の秒数、P-05の保持期間・暗号化・legal/audit・cascade detailはProduct Pendingではなく後工程parameterである。

## 3. Corrective Design impact

- C01-D02のLifecycle closeをP-01へ固定した。
- C08-D01のfailure / background behaviorをP-02 / P-03へ固定した。
- CO-D01をP-04 / P-07へ固定した。
- DH-D01 / MIG-D01のdata principleをP-05へ固定した。
- 旧55秒pathのtransition ruleをP-06へ固定した。
- Product Pendingによる設計上の分岐は解消した。
- Provider-neutral、MIP Fail Closed、No Automatic Fallback、Partial ephemeral、Durable Final canonical境界は不変。

Corrective Design recommendation: **APPROVE candidate**。CodexはApprovedへ変更せず、人＋ChatGPT Final Gate Reviewへ提出する。

## 4. Compatibility Blocker impact

| Blocker | Design status candidate | Remaining Technical / Evidence |
|---|---|---|
| CE-B-C01-01 | Resolved by Approved Design candidate | relay runtime、forced close、Provider egress stop、Human UX |
| CE-B-C03-01 | Resolved by Approved Design candidate | source cursor implementation、exact mapping、Migration、Provider timing |
| CE-B-C03-02 | Resolved by Approved Design candidate | normalized receipt、ordering、Provider event Evidence |
| CE-B-C03-03 | Resolved by Approved Design candidate | atomic Writer/Revision/commit receipt verification |
| CE-B-C08-01 | Resolved by Approved Design candidate | continuous capture repository/Provider/Human UX Evidence |
| CE-B-C08-02 | Resolved by Approved Design candidate | same-stream waveform automated + Human UX Evidence |
| CE-B-C08-03 | Resolved by Approved Design candidate | partial/final implementation、Provider + Human UX Evidence |
| CE-B-CO-01 | Resolved by Approved Design candidate | grace/snapshot implementation、latency + Human UX Evidence |

Corrective Design全体がまだ最終承認されていないため、8件とも**OPEN**。承認後もImplementation / Automated / Provider / Human UX verification itemをOPEN維持する。

## 5. Technical Pending final-review table

| ID | Topic | Recommended Candidate | Human Approval Before CE-P1 | CE-P1 Technical Verification | Stop Condition |
|---|---|---|---|---|---|
| T-01 | Realtime Relay runtime topology | same-origin WSS + isolated persistent relay worker、new public portなし | **Required** | lifecycle/capacity/recovery | runtime/proxy/process manager未承認、public port/tunnel/firewall必要 |
| T-02 | Lease TTL / refresh / forced-close latency | short configurable TTL/refresh、event primary + expiry safety net | **Not Required** | fault/load/jitter testで具体値 | Product SLA化、安全bound不成立 |
| T-03 | Canonical audio frame format | PCM16 / 16kHz / mono、20–100ms候補 | **Not Required**（package時Required） | browser/quality/bandwidth test | quality/compatibility不成立、新codec/package必要 |
| T-04 | Out-of-order reorder buffer | bounded memory + short timeout、unresolved gap close | **Not Required** | property/load/fault test | unbounded buffer、架空silence必要 |
| T-05 | Deepgram timing mapping | send ledger、verified rangeだけcommit | **Not Required for synthetic phase** | docs/existing Evidence/synthetic/approved real Evidence | exact mapping不能、新Evaluation必要 |
| T-06 | Writer / checkpoint integration | sole committer + existing Writer transaction/lineage | **Not Required** | rollback/retry/race/context tests | existing Writer再利用不能、destructive schema必要 |
| T-07 | Browser continuous capture | native same-stream Web Audio/AudioWorklet | **Not Required for native API**（package時Required） | Chrome/Edge/iPhone/PWA | second mic、silent fallback、package/polyfill必要 |
| T-08 | Control outbox / bus / registry | DB transactional outbox + relay registry、approved runtime内delivery | **Required** | delivery loss/delay/restart/ACK | external service/new port/unapproved security boundary必要 |
| T-09 | Final timing/speaker serialization | normalized final-only encrypted data + safe cleanup | **Not Required using existing boundary** | encryption/redaction/deletion tests | raw/partial保存、新KMS/key boundary必要 |
| T-10 | Accessible partial/waveform cadence | fluid visual + restrained assistive announcements | **Not Required** | Human UX / reduced motion / screen reader | truthful state/accessibility不成立 |

### 5.1 Human decision required before CE-P1 or relevant phase

- T-01 persistent relay process / same-origin WSS / reverse proxy / process manager
- T-08 control delivery / connection registry topology and external service prohibition
- MIG-D01 Migration creation permission and 8-table scope
- CE-P1 Scope / Branch / Done Contract
- Deepgram SDK vs direct protocol / runtime / package / version before Adapter implementation
- production credential store and Evaluation Credential non-reuse before network phase
- limited Provider Verification data / request / cost / privacy / stop condition before first communication

### 5.2 Codex technical decision allowed inside CE-P1

- T-02具体TTL / refresh（approved safety boundary内）
- T-03 frame duration / format detail（new packageなし）
- T-04 reorder window
- T-05 mapping algorithm detail（verified-only invariant維持）
- T-06 transaction/idempotency detail
- T-07 native Browser API detail（package/polyfillなし）
- T-09 existing encryption boundary内detail
- T-10 cadence / accessibility tuning

## 6. MIG-D01 readiness

8-table candidateを再確認した。

| Table | Responsibility |
|---|---|
| relay leases | authorization capability / lifecycle |
| relay control events | revoke/close transactional outbox / ACK |
| source ranges | accepted Company OS sample Evidence |
| provider sessions | adapter session / generation mapping |
| provider send ranges | source sample ↔ Provider offset ledger |
| provider event receipts | normalized final/metadata/error/close receipt |
| durable final commits | one final → one Writer operation result |
| durable final commit items | commit → one/many Segment/Revision link |

責任、cardinality、immutabilityが異なり、統合するとunique constraintと監査境界が曖昧になるため、Ver.1 candidateは8 tablesを維持する。不要な汎用化・追加tableは行わない。

- MIG-D01 Design: **READY**
- Migration creation: **NOT AUTHORIZED**
- Migration execution: **NOT AUTHORIZED**
- past record backfill: **NONE / no fabricated Evidence**

## 7. Infra / Runtime gate

Existing XAMPP / Laravel / Browser環境で再利用可能:

- Laravel authorization / Consent / Writer / Revision / Long Context
- HTTPS application origin / DB transaction
- Browser `getUserMedia` / Web Audio API候補
- server-side secret boundary

Additional runtime requiring explicit review:

- persistent realtime relay process
- same-origin WSS endpoint
- reverse proxy WebSocket upgrade configuration
- process manager / health / restart
- cross-worker control delivery and connection registry
- Deepgram SDK/direct protocol runtime and package

new public port / tunnel / firewallを前提にしない。Redis等external serviceが必要なら停止する。

## 8. Deepgram implementation-start gate

| Item | Final Review state |
|---|---|
| Production Credential storage | approval required / Evaluation Credential reuse prohibited |
| SDK vs direct protocol | **Recommended candidate only:** T-01でisolated Node relay workerが承認された場合、server-sideの公式`@deepgram/sdk` v5系を第一候補とする。MIP actual-request guard、event identity、source mapping、retry禁止を保証できない場合だけdirect WSSを技術代替候補として比較する。Browser direct connectionは不採用。human technical choiceは未承認 |
| Package / version | `@deepgram/sdk` v5系候補。exact versionはT-01/H-TECH-03承認後にpinし、lockfile・integrity・license確認後に隔離導入する。現時点は未選定・未導入 |
| Provider-neutral Port | Design Ready |
| MIP | request builder + network-immediate double Fail Closed fixed |
| Provider profile | Nova-3 Streaming / Japanese / anonymous diarization candidate |
| Synthetic-only phase | first, communication 0 |
| First Provider communication | separately approved limited phase |
| Cost / attempt limit | approval required before communication |

Technical candidate source:

- Deepgram official JavaScript SDK: <https://github.com/deepgram/deepgram-js-sdk>
- Deepgram official Streaming STT overview: <https://developers.deepgram.com/docs/getting-started-with-live-streaming-audio>

上記はFinal Gateで比較可能な候補を具体化しただけであり、T-01、H-TECH-03、新Package導入、Credential、Provider通信を承認したものではない。

## 9. Waveform / Realtime UX gate

Fixed Product Contract:

- one `getUserMedia`
- one MediaStream
- same-stream waveform / browser-local energy
- second mic acquisition prohibited
- ASR wait must not stop Capture
- partial ephemeral
- Durable Final only canonical
- truthful independent state

Human UX adjustable:

- animation speed / bars / intensity
- energy smoothing / visual threshold
- partial cadence
- grace concrete duration
- accessible announcement cadence

## 10. Master Update Candidate（not executed）

- Pause / End final-only drain vs Cancel / Revoke Hard Abort
- Provider failure stops Capture; Text Conversation continues
- background Pause / explicit foreground Resume
- bounded finalization grace
- minimum retention / raw and partial non-persistence
- old 55-second path isolation then separately approved removal
- grace failure continues CO using base durable snapshot

Master v155 / Excel v050は変更していない。

## 11. CE-G01 OPEN readiness

CE-G01 OPENはImplementation Doneではなく、安全にCE-P1を開始してEvidenceを取りに行ける状態を意味する。Browser/iPhone/PWA/Provider/Waveform/latency EvidenceはCE-P1以降のDone条件であり、OPEN前PASS条件にしない。

| Gate condition | State |
|---|---|
| Product Pending 0 | READY |
| Corrective Design | APPROVE candidate / final approval pending |
| 8 design blockers | Resolved by Approved Design candidates / still OPEN |
| T-01 runtime topology | human approval required |
| T-08 control topology | human approval required |
| MIG-D01 creation | human approval required |
| SDK/direct protocol/package policy | human approval required before Adapter phase |
| CE-P1 Scope / Branch / Done Contract | human approval required |

Recommendation: **READY FOR HUMAN + ChatGPT TECHNICAL PENDING / CE-G01 FINAL GATE REVIEW**。

Codex自身はCE-G01をOPENせず、CE-P1を開始しない。

## 12. Final state

- Product Pending: **0**
- Corrective Design: **APPROVE candidate / not yet Approved**
- Compatibility Blockers: **8 OPEN**
- Technical Pending: **10 classified**
- CE-G01: **CLOSED**
- CE-P1: **NOT STARTED**
- Migration: **not created / not run**
- Product Code / DB / Storage: **unchanged**
- Provider communication / audio send: **0 / 0**
- Public push / Deploy: **0 / 0**
- Next stop: **Human + ChatGPT Technical Pending / CE-G01 Final Gate Review**
