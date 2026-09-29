# Company OS｜S11CD-B Conversation Experience Realtime Corrective Design v001

- Document status: **DESIGN CANDIDATE / HUMAN + ChatGPT REVIEW REQUIRED**
- Design date: 2026-09-29 JST
- CE-G01: **CLOSED（維持）**
- CE-P1: **NOT STARTED**
- Product Code / Migration / DB / Storage change: **なし**
- Provider communication: **Deepgram 0 / Azure 0**
- Audio send: **Deepgram 0 / Azure 0**
- Public GitHub push: **禁止・未実施**

## 1. Purpose and authority

本書は、Focused Technical Verificationで確認されたCE-C01 / CE-C03 / CE-C08およびCO Finalization GraceのCompatibility Blockerを、実装可能なCorrective Design候補へ具体化する。設計候補を作成する工程であり、実装承認、Migration承認、Blocker解消、CE-G01 OPENを意味しない。

Authorityの優先順位:

1. PPT Master v155
2. Excel要件仕様書 v050
3. `CompanyOS_S11CD_B_CE_Realtime_Compatibility_Focused_Verification_v001.md`
   - SHA-256: `573C392E53615C299361D26B3E2534A3DD85CAFE64B85D70D868FDD6098F78B1`
4. Realtime Compatibility Corrective Design開始指示

既存Commit `89f2dea` は変更しない。CE-G01 Closed Contract、IR-1、Delta B Formal Closed Contract、Master、Provider Evaluation Evidenceも変更しない。

### 1.1 Human + ChatGPT Product Decision Review（2026-09-29 JST）

P-01〜P-07は人＋ChatGPT Reviewで全件Resolvedとなった。本書は候補設計の意味を拡張せず、次の承認結果へ同期する。

| ID | Approved Product Contract | State |
|---|---|---|
| P-01 | Pause / Normal Endは新規audioを即時停止し、current authorization / Consent下で既送信audioのbounded final-only drainだけを許可。Cancel / RevokeはHard Abort | RESOLVED |
| P-02 | Deepgram / Relay failure時はCaptureも停止。Text Conversationは継続し、明示retryだけをnew generation / lease / Provider Sessionで行う | RESOLVED |
| P-03 | background / OS suspension時はPause / Close。foregroundで自動Resumeせず、current authorization / Consent再確認後の明示Resumeを要求 | RESOLVED |
| P-04 | bounded finalization graceを採用。具体秒数はHuman UX Verificationで決める | RESOLVED / Approved Principle + Deferred UX Parameter |
| P-05 | data class別の必要最小保持、raw audio/payload/partial非永続、session relationを採用。具体期間・暗号化・legal preservation・cascade detailはPrivacy / Release Reviewで決める | RESOLVED / Approved Principle + Deferred Operational Detail |
| P-06 | 旧55秒pathをRealtime移行・Regression・Human UX期間だけ隔離保持し、silent fallbackに使わない。Realtime Evidence成立後の撤去は別Cleanup / Migration承認で行う | RESOLVED |
| P-07 | grace失敗時はpartialを昇格せず、押下時base durable snapshotでCO Requestを続行。late finalを進行中Requestへ追加しない | RESOLVED |

Product Decision Pendingは`7 → 0`。P-04 / P-05の後工程値はProduct Pendingへ戻さず、承認済み原則配下のVerification / Operational Parameterとして管理する。

Corrective Design全体の推奨は **APPROVE candidate**。Codex自身はApprovedへ変更せず、CE-G01もOPENしない。

## 2. Design scope and invariants

### 2.1 Corrective scope

| Trace ID | Scope |
|---|---|
| C01-D01 | Realtime Relay Authorization Lease |
| C01-D02 | Control Plane / Revoke / Forced Close |
| C01-D03 | Late Provider Event / Reconnect / Idempotency |
| C03-D01 | Continuous Source Cursor |
| C03-D02 | Provider-neutral Streaming Event Receipt |
| C03-D03 | Durable Final Commit Receipt |
| MIG-D01 | Additive Migration Candidate |
| DH-D01 | Data Handling / Retention / Deletion |
| C08-D01 | Single Continuous Capture |
| C08-D02 | Same-stream Waveform |
| C08-D03 | Realtime Partial / Durable Final UX |
| CO-D01 | Bounded Finalization Grace / Durable Context Snapshot |
| DG-D01 | Provider-neutral Port / Deepgram Adapter / MIP Fence |
| FAIL-D01 | Failure Matrix / Truthful State |
| REG-D01 | Regression Matrix |
| HUX-D01 | Human UX Verification Plan |
| DONE-D01 | Four-layer Done Contract Candidate |

### 2.2 Reused Closed assets

次を置換・緩和せず再利用する。

- organization / conversation / participant authorization
- membership epoch / credential generation / audience snapshot
- Consent revision
- capture generation / sequence / idempotency
- encrypted temporary audio boundary
- Transcript Writer / immutable Revision / human correction lineage
- Long Context / explicit CO Request
- tenant / visibility boundary

### 2.3 Non-negotiable invariants

- BrowserへProvider API Key、Authorization Header、raw credentialを渡さない。
- Provider timingをCompany OS source rangeの正本にしない。
- PartialをDB、Source、Citation、Revision、Long Context、CO Request、Decision、Action、Memoryへ永続化・昇格しない。
- Provider Final受信だけでcanonicalにしない。
- Controller / Relay / AdapterからTranscript Modelへ直接保存しない。
- Deepgram障害時にAzure、旧55秒batch、他Providerへ自動fallbackしない。
- actual Deepgram Requestの`mip_opt_out=true`が不明・欠落・falseならnetwork前にFail Closedする。
- 過去recordへ存在しないcontinuous source rangeを推測backfillしない。

## 3. Corrective architecture candidate

```text
┌──────────────── Browser trust zone ────────────────┐
│ getUserMedia (one MediaStream)                     │
│   ├─ AudioWorklet / canonical frame producer       │
│   └─ Web Audio local energy → visible waveform     │
│ ephemeral partial projection only                 │
└──────────────────────┬─────────────────────────────┘
                       │ Company OS HTTPS origin / candidate same-origin WSS boundary
                       ▼
┌──────────── Company OS Relay trust zone ───────────┐
│ current authorization + Consent                    │
│ lease / control plane / forced close               │
│ source cursor / range integrity / send ledger      │
│ provider-neutral streaming port                    │
│ normalized event validation                        │
│ durable-final commit service                       │
└──────────────────────┬─────────────────────────────┘
                       │ server-held credential only
                       │ mip_opt_out=true mandatory
                       ▼
┌────────────── Deepgram trust zone ─────────────────┐
│ Nova-3 Streaming / anonymous diarization           │
│ provider session and provider-native events        │
└──────────────────────┬─────────────────────────────┘
                       │ normalized by adapter
                       ▼
┌──────────── Company OS durable zone ───────────────┐
│ accepted source receipts / final event receipts    │
│ existing Transcript Writer / Revision / lineage    │
│ commit receipt / current Long Context              │
└────────────────────────────────────────────────────┘
```

Relay runtimeは既存HTTPS reverse proxy配下へsame-origin WSSを追加できる構成を候補とし、新しい公開portを前提にしない。persistent relay process、WSS対応可否、reverse proxy、internal control busの具体構成はTechnical Pending `T-01` とし、CE-P1開始前に承認・検証する。

## 4. Security / Privacy Trust and data boundary

| Boundary | 保持してよいもの | 禁止事項 |
|---|---|---|
| Browser | MediaStream、local energy、bounded audio frame buffer、public session/stream/generation ID、opaque lease handle、ephemeral partial | Provider secret、long-lived Provider token、raw credential、partial永続化 |
| Company OS Relay | current auth/Consent evidence、lease、source frame buffer、server credential、provider session mapping、normalized final candidate | unauthorized frame送信、raw Provider payloadの便宜保存、Model直接保存 |
| Deepgram | authorized audio、locale/model/diarization設定、random provider correlation、`mip_opt_out=true` | Company OS tenant名、membership情報、Source/Citation、unrelated context |
| Durable DB | source range receipt、safe control/event metadata、validated final mapping、Transcript Revision、commit receipt | raw credential、Authorization Header、ephemeral partial text、raw Provider payload |
| Log/Evidence | public IDs、safe reason code、counts、timing、hash、state transition | audio、transcript本文の重複、secret、header、raw request/response |

Consent boundaryはBrowser capture開始、Relay lease issue/refresh、Provider network開始、audio egress、Final commit、CO context snapshotで再確認する。Authorization boundaryも同じ箇所でcurrent stateを基準にする。

## 5. CE-C01｜Authorization Lease

### 5.1 Lease identity and bindings

LeaseはCompany OSが発行する短寿命のopaque capabilityとし、次へ固定する。

- `relay_lease_public_id`（UUID）
- organization / conversation / shared session
- capture stream / generation
- active participant roster fingerprint
- audience epoch fingerprint
- Consent revision fingerprint（recording / transcript sharing / external ASR）
- membership access epoch fingerprint
- credential generation fingerprint
- purpose revision
- issued / expires / refreshed / revoked / closed timestamps
- lease version / state / safe reason code

Browserが保持するのは推測困難なopaque handleだけとし、authorization claimやProvider secretをBrowserで正本化しない。Relayはserver-side recordまたはserver-signed internal proofを検証する。

### 5.2 Issue and refresh

1. issue transactionで既存authorization、active audience、Consent、session/stream/generationをlock付きで検証する。
2. 同一stream generationにactive leaseがある場合は新規発行せず、同一operationのretryだけをidempotentに返す。
3. lease発行後にのみProvider connection preparationへ進める。
4. audio frameごとのDB queryは行わず、Relayのactive connection registryでlease ID、expiry、generation、frame sequenceを検証する。
5. bounded intervalでrefreshし、その時点でDBのcurrent authorization / Consent / epochs / generationを再検証する。
6. control eventを即時経路、lease expiryをcontrol event欠落時のsafety netとする。
7. control busまたはrefresh検証が利用不能ならrefreshせず、expiry到達前に新規audio egressを停止する。

TTL / refresh値は固定Product SLAにせずTechnical Pending `T-02` とする。推奨候補は「single-digit秒のrefresh、短いdouble-digit秒以下のTTL」で、revocation latency、DB負荷、network jitterをfault testして確定する。

### 5.3 Per-frame current-state fence

各frameは最低限、lease ID、stream ID、generation、frame sequence、client event ID、start/end sample、content hashを持つ。RelayはDBへ毎回問い合わせず、次をlocal validationする。

- active lease / unexpired / correct lease version
- current registered connection
- matching stream / generation
- monotonic frame sequence
- accepted source cursor rule
- exact duplicateかintegrity conflictか
- forced-close cutoffを越えていないこと

一つでも不成立ならProviderへ送らない。

## 6. CE-C01｜Control Plane and Forced Close

### 6.1 Transactional control event

authorization / Consent / lifecycle変更と同じtransactionで、append-only control outboxへ一意な`control_operation_id`を記録する。Relay workerはoutbox/control busを購読し、対象lease / stream / generationのconnection registryを特定する。

Forced closeは次の順序をContractとする。

1. DB stateをrevoking / interruptedへ遷移し、cutoff generation / sampleを確定する。
2. control eventをoutboxへ同一transactionで記録する。
3. Relayは新規Browser frame受領とProvider audio sendを先に停止する。
4. 同じWSS control channelが生存していればBrowserへstop signalを送り、Browserはmic capture / audio graphをcleanupする。Provider egress停止はBrowser ACKへ依存させない。
5. Provider socketをcloseし、connection registryをclosedへ遷移する。
6. Browser / Provider close acknowledgementまたはtimeoutをsafe metadataとして記録する。
7. cutoff後のProvider eventはcanonical化しない。

DB interruptとProvider socket/audio egress停止を別Evidenceにする。DB stateだけでForced Close PASSにしない。

### 6.2 Event classification

**Hard revoke（即時停止、追加audio / finalize送信なし）**:

- Consent revoke / revision mismatch
- participant removal / deactivation
- organization membership / credential generation mismatch
- conversation archive
- cancel
- expiry
- stream generation supersede
- relay authorization timeout

**Lifecycle close（P-01 Approved: 新規audioを即時停止し、既送信audioだけをbounded final-only drain）**:

- pause
- normal stop / end
- browser disconnect / page close
- device / permission loss

Approved Contractとして、Hard revokeではFinalize待ちをせずuncommitted partial/finalをrejectする。Pause / Normal Endではauthorization / Consentがcurrentな場合に限って既送信audioのbounded final-only drainを許可し、drain完了またはbounded timeout後にcloseする。新しいaudioの追加送信はどちらでも禁止する。

### 6.3 Idempotency

- `control_operation_id`はglobal unique。
- 同じeventの再配送は同じclose resultを返す。
- stale generationのcontrol eventはcurrent connectionをcloseせず、stale acknowledgementを残す。
- current generationへのcloseは一度だけProvider socketへ適用する。
- late close acknowledgementでnew generationを閉じない。

## 7. Late Provider Event and reconnect

### 7.1 Late event

event受領時にprovider session、lease、stream generation、receive order、close cutoffを検証する。

- activeかつcurrent: normalized validationへ進む。
- close/revoke後: Transcriptへcommitしない。
- late partial: contentを即時破棄し、必要なら`late_event_discarded`のsafe counterだけを記録する。
- late final / metadata: raw payloadを残さず、content hash、provider event ID hash、range、safe reasonをRejected / Superseded Receipt候補として記録する。
- invalid credential/header/payloadをlogへ出さない。

### 7.2 Reconnect

- reconnectは常にnew capture generation、new lease、new Provider Session。
- old connection / old lease / old Provider Sessionを暗黙再利用しない。
- reconnect operation IDをidempotency keyにする。
- new generation issue前にold generationをsupersededとし、control eventを発行する。
- one active generation / one active lease / one current provider sessionをunique constraintとtransaction lockで保証する。
- double click / double reconnectでは同じoperation resultを返し、二重socket / 二重audio sendを作らない。

## 8. CE-C03｜Continuous Source Cursor

### 8.1 Source of truth

Company OSが受理したcanonical audio sample sequenceをsource rangeの正本とする。Provider timestampはTranscript内容をaudioへ対応させるための入力であり、cursor正本ではない。

Canonical frame formatはTechnical Pending `T-03`。候補はPCM signed 16-bit / 16 kHz / monoで、Browser互換性・日本語品質・帯域を実装前に確認する。

Cursor規則:

- `start_sample` inclusive、`end_sample` exclusive。
- `end_sample - start_sample = decoded canonical sample count`。
- capture streamの最初は0。同一capture streamではgenerationが変わってもcursorを単調増加させ、source identityを維持する。
- mic track / canonical timebaseを作り直す場合はnew capture streamを発行し、その新streamだけを0から開始する。
- next accepted frameは原則`start_sample = previous.end_sample`。
- millisecondsは表示用derived valueであり正本にしない。
- Browser提案cursorをRelayがdecoded sample countと照合して初めてacceptedとする。

### 8.2 Frame identity and integrity

各frame/chunk receipt候補:

- source range public ID
- organization / conversation / session / stream / generation
- frame sequence / client event UUID
- absolute start / end sample
- sample rate / bit depth / channels / format
- content SHA-256
- accepted authorization lease / Consent fingerprint
- received / accepted / sent timestamps
- state: accepted / duplicate / rejected / gap / superseded
- safe reason code

### 8.3 Gap / overlap / resend

| Condition | Contract |
|---|---|
| Exact next frame | acceptし、一度だけProviderへ送る |
| Same client event + same range + same hash | idempotent duplicateとしてACKし、再送しない |
| Same identityでhash/range相違 | integrity conflict。送信せずconnectionをfail closed |
| Overlap | exact duplicate以外はreject。文字数比や切り詰めで修正しない |
| Out-of-order | bounded in-memory reorder buffer。Technical Pending `T-04`でwindowを決定 |
| Unresolved gap | gap receiptを残し、架空silenceを補わずcurrent Provider Sessionをclose。再開はnew generation |
| Resend | original identity/hash完全一致時のみduplicate ACK。Providerへ二重送信しない |

### 8.4 Provider send ledger

accepted source rangeとProvider Sessionへの送信offsetを一対一に記録する。Provider eventのtimingはこのledgerへ照合し、Company OS sample rangeへ決定的にmappingする。

- provider session audio offset start/end
- source range start/end
- send ordinal
- sent once identity
- sent_at / acknowledged_at（Providerが提供する場合）

Deepgram timing precisionでexact mappingが成立するかはTechnical Verification `T-05`。`verified`にならないrangeをTranscriptへcommitしない。`bounded`や`ambiguous`はEvidence状態であり、canonical rangeとして扱わない。

## 9. CE-C03｜Provider-neutral Streaming Event Receipt

### 9.1 Two-level receipt

`ProviderStreamingEventEnvelope`をDomain DTOとし、Adapter固有payloadを外へ出さない。

1. **Ephemeral envelope**: partialを含む全eventをRelay memory内で扱う。partial textはDBへ保存しない。
2. **Durable safe receipt**: accepted/rejected final、metadata、error、close等、監査とcommitに必要なnormalized metadataだけをappendする。raw Provider payloadは保存しない。

late partialは本文・hash・wordを保存せず、必要な運用counterだけを`late_event_discarded`として集計する。

### 9.2 Normalized fields

- adapter name / adapter version / capability profile version
- provider session reference（random internal mapping）
- provider event identity（raw identityを必要に応じhash化）
- normalized event type: partial / final / metadata / error / close
- provider sequence / Company OS receive order
- duplicate identity / supersedes identity
- provider start / duration / word timing
- anonymous speaker hint（person identityではない）
- verified source rangeまたはinvalid mapping state
- received / finalized / rejected / superseded timestamps
- status / safe reason code
- normalized content SHA-256（durable finalのみ）
- usage quantity / unit / price version / estimated cost（利用可能な範囲）

Partial envelopeはrewritable UI projectionであり、canonicalではない。Final envelopeもC03-D03完了まではcandidateである。

### 9.3 Event ordering

- Company OS `receive_order`をprovider session内でmonotonic採番する。
- provider event IDが同一なら最初だけを処理する。
- final後の同一range partialはdiscardする。
- newer partialがolder partialをsupersedeできるが、DBへは保存しない。
- out-of-order finalはsource range ledgerとprior final coverageを確認し、overlap/duplicateならcommitしない。

## 10. CE-C03｜Durable Final Commit Receipt

### 10.1 Sole commit boundary

新しい`RealtimeDurableFinalCommitter`候補を、Streaming Finalから既存Transcript Writerへの唯一のbridgeとする。Controller / Relay / Deepgram AdapterはModelを直接保存できない。

Commit前検証:

1. provider event receiptがnormalized finalで未commit。
2. provider session / lease / stream generationがevent受領時点でcurrent。
3. close cutoff内で、Hard revoke対象ではない。
4. authorization / Consent / membership / credential / audienceをcurrent stateで再検証。
5. source rangeがsend ledgerへ`verified` mapping済み。
6. event order、duplicate、overlap、supersedeが正常。
7. writer operation IDが一意。

### 10.2 Atomic result

同一transaction boundaryまたは同等のoutbox/sagaで次を一体として扱う。

- existing Transcript Segment creation / resolution
- immutable Transcript Revision creation
- current revision pointer update
- human correction lineage compatibility
- one Durable Final Commit Receipt
- provider final receipt status `committed`

Provider final 1件にcommit receipt 1件をuniqueにする。diarization等で複数Segment / Revisionになる場合はcommit item joinで一対多を明示する。retryは同じwriter operation IDで同じ結果を返す。

Writer failure / DB rollback時はcanonical projectionを行わず、event receiptをretryableまたはrejectedのsafe stateへ置く。部分commitは禁止する。

## 11. MIG-D01｜Additive Migration candidate

Migrationの作成・実行は本工程の承認外。以下はschema candidateである。

### 11.1 Candidate tables

#### `ai_common_shared_relay_leases`

- PK、`public_id` UUID unique
- organization / conversation / shared_session / capture_stream FK
- generation、lease_version、state
- audience / Consent / membership / credential fingerprints
- purpose_revision_id
- issued_at / expires_at / refreshed_at / revoked_at / closed_at
- safe_reason_code、timestamps
- unique: `(capture_stream_id, generation)`
- index: `(shared_session_id, state)`, `(expires_at, state)`

#### `ai_common_shared_relay_control_events`

- PK、`operation_id` UUID unique、relay_lease FK
- event_type、target_generation、cutoff_sample、safe_reason_code
- occurred_at / delivered_at / acknowledged_at
- immutable event content。delivery markerだけmonotonic update
- index: `(relay_lease_id, occurred_at)`, `(delivered_at)`

#### `ai_common_shared_source_ranges`

- PK、`public_id` UUID unique、session / stream / lease FK
- generation / frame_sequence / client_event_id UUID
- start_sample / end_sample unsigned big integer
- sample_rate / bit_depth / channels / format
- content_sha256、authorization / Consent fingerprints
- state / safe_reason_code / received_at / accepted_at
- unique: `(capture_stream_id, generation, frame_sequence)`
- unique: `(capture_stream_id, generation, client_event_id)`
- check candidate: `end_sample > start_sample`
- indexes: range lookup、state、retention timestamp

#### `ai_common_shared_provider_sessions`

- PK、`public_id` UUID unique、lease / stream FK
- generation、adapter、capability_profile_version
- provider_session_reference_hash
- state / opened_at / closing_at / closed_at / safe_reason_code
- unique current session candidate: `(capture_stream_id, generation)`

#### `ai_common_shared_provider_send_ranges`

- provider_session / source_range FK
- send_ordinal、provider_offset_start_sample / end_sample
- sent_at、state
- unique: `(provider_session_id, send_ordinal)`
- unique: `(provider_session_id, source_range_id)`

#### `ai_common_shared_provider_event_receipts`

- PK、`public_id` UUID unique、provider_session FK
- provider_event_identity_hash、provider_sequence、receive_order
- normalized_event_type（durable persistenceではpartial text禁止）
- duplicate_of / supersedes self-FK
- provider_start / duration、verified source start / end sample
- range_verification_state
- final_content_sha256、encrypted normalized final timing / anonymous speaker data候補
- status / safe_reason_code
- received_at / finalized_at / rejected_at / superseded_at
- usage quantity / unit / price version / cost microunits候補
- unique: `(provider_session_id, receive_order)`
- unique candidate: `(provider_session_id, provider_event_identity_hash)` where not null

#### `ai_common_shared_durable_final_commits`

- PK、`public_id` UUID unique、provider_event_receipt FK unique
- writer_operation_id UUID unique
- validated source start / end sample
- state / safe_error_code / committed_at / timestamps

#### `ai_common_shared_durable_final_commit_items`

- durable_final_commit / transcript_segment / transcript_revision FK
- ordinal
- unique: `(durable_final_commit_id, ordinal)`
- unique: `(durable_final_commit_id, transcript_revision_id)`

### 11.2 Compatibility and rollout

1. Expand-only tablesをnullable/default最小で追加する。
2. 既存bounded recordは新tableを参照しなくても従来どおり読める。
3. 過去Transcriptへのsource cursor backfillは行わない。`legacy_bounded`を既存由来として明示するだけで、架空Evidenceを作らない。
4. 新codeはfeature flag OFFでdeploy可能にする候補。正式deployは別承認。
5. schema → writer compatibility → read compatibility → relay activationの順にする。
6. rollbackはfeature flag OFFと旧application互換維持を第一とし、同releaseでtable dropしない。
7. destructive cleanupはapproved retention detailとP-06のRealtime Evidence成立後に、別の明示Cleanup / Migration承認で行う。

P-06により、旧55秒pathはRealtime実装・Regression・Provider Evidence・Human UX Verification中だけ隔離保持する。通常UXで選択させず、Realtime failure時のsilent fallbackにも使わない。Evidence成立後は撤去方向とするが、実削除は別の明示Cleanup / Migration承認を必要とする。

### 11.3 Immutability and deletion relation

- relay leaseはlifecycle stateだけをversion付きCASで単調更新し、binding fingerprintを書き換えない。
- control event、accepted source range、provider send range、provider event receipt、durable commit/itemのEvidence contentはappend-onlyとする。
- delivery / accepted / committed / rejected等のstatus timestampは許可された一方向遷移だけを行い、本文やrangeを上書き訂正しない。
- Transcript訂正は既存immutable Revision / human correction lineageで新revisionを追加する。
- session/conversation削除時のcascadeはP-05で承認したretentionに従う。commit itemから現存Transcriptへの参照は不用意なorphanを作らないようRESTRICTまたは同等のguardを候補とする。
- retention cleanupでreceipt metadataをpruneしても、Transcript本文やlineageを暗黙削除・改変しない。

## 12. DH-D01｜Data handling candidate

| Data | Runtime | Durable storage candidate | Protection / deletion |
|---|---|---|---|
| Raw realtime audio frame | Relay memoryのみ | 原則なし | bounded buffer、close時zero/release |
| Existing bounded raw audio | 既存contract | existing encrypted temporary storage | 既存cleanupを維持 |
| Raw Provider payload | Adapter memoryのみ | なし | log禁止、処理後破棄 |
| Partial text | Browser/Relay memory | なし | supersede/final/closeで破棄 |
| Source range receipt | Relay/DB | append-only metadata | session relation、期間/cascadeはdeferred operational detail |
| Normalized final event | Relay/DB | hash、timing、range、safe metadata | encryption/redaction、期間はdeferred operational detail |
| Transcript | Existing Writer | existing Segment/Revision | existing lineage/deletion contract |
| Anonymous speaker hint | final normalized dataのみ | encrypted candidate | person identityと分離、期間はdeferred operational detail |
| Usage / cost | safe normalized metrics | candidate | credential/request bodyなし |
| Error | safe code only | candidate | payload/header/secretなし |

P-05のProduct原則として、data class別の必要最小保持、raw realtime audio / raw Provider payload / partial非永続、session deletion relation、Transcript/Revision/lineage非連動削除を採用する。具体期間、暗号化方式、legal/audit preservation、cascade detailはPrivacy / Release Reviewで確定するdeferred operational detailであり、Product Pendingではない。削除境界はtenant / conversation / sessionを越えない。

## 13. CE-C08｜Single Continuous Capture

### 13.1 Capture pipeline

```text
one getUserMedia
  → one MediaStream
      ├─ browser-local Web Audio energy branch
      └─ continuous frame producer
           → authorized WSS relay
           → provider-neutral port
```

- ASR response待ちでcaptureを止めない。
- second `getUserMedia()`は禁止。
- AudioWorklet等でcanonical framesを作る候補とし、MediaRecorder 55秒stop/upload loopを通常Realtime経路に使わない。
- unsupported browserでは旧batchへsilent fallbackせず、Realtime unavailableをtruthfulに表示する。

### 13.2 Lifecycle semantics candidate

| Event | Capture | Relay / Provider | Transcript |
|---|---|---|---|
| Pause | 新規sample生成停止 | audio egress即時停止。current auth/Consent下で既送信audioだけbounded final-only drain | committed durableのみ維持 |
| Resume | 同一MediaStream再開可能性を確認 | new generation / new lease / new Provider Session | 同一capture streamならcursor継続。track/timebase再作成時だけnew streamを0開始 |
| End | capture終了・track cleanup | egress即時停止、既送信audioのbounded final-only drain完了/timeout後close | drainでcommitできたdurable finalまで確定後session end |
| Cancel | 即時cleanup | hard close、uncommitted event reject | partial破棄 |
| Consent revoke / removal | 即時停止 | hard revoke / forced close | late event reject |
| Device / permission loss | failedへ遷移・cleanup | close | partial破棄、truthful error |
| Background / foreground | backgroundでPause / Close、foregroundで自動Resumeなし | 明示Resume時にcurrent auth/Consent再確認後new generation / lease / Provider Session | false active表示禁止 |

## 14. CE-C08｜Waveform

### 14.1 Signal contract

- Captureと同じMediaStreamを`AudioContext` / `AnalyserNode`または同等のlocal processorへbranchする。
- RMS / peak等のlocal energyを短いrolling windowで算出し、Provider eventに依存しない。
- waveform stateをCapture、Relay、ASR、Transcript、CO reasoning stateから分離する。
- silence → speech → silenceでvisible responseを返す。
- energy valueをProviderへ送らず、audio upload成否の代用にしない。

### 14.2 Cleanup

Pause / End / Cancel / permission revoke / device loss / page closeで次をidempotentに行う。

- animation frame cancel
- analyser/source node disconnect
- AudioContext suspend/close（event semanticsに応じる）
- MediaStream track stopまたは安全な停止（Pause中もsample生成・egressは必ず停止。track保持/再取得のtechnical detailはT-07で検証）
- energy buffer zero/release
- visible stateを停止/利用不可へ更新

### 14.3 Accessibility

- reduced motion時は激しいanimationを抑え、低頻度level meterまたは静的段階表示にする。
- 色だけに依存しない。
- mic stateのtext labelを併設する。
- partialの高頻度更新をscreen readerへ毎回announceしない。durable finalまたは重要state変化だけを適切なlive regionで通知する。

## 15. CE-C08｜Partial / Final presentation

| Presentation | Contract |
|---|---|
| Partial | ephemeral、書換え可能、会話中に継続表示、明確に未確定、DBへ保存しない |
| Final candidate | Provider final受信済みだがvalidation / Writer待ち。canonical表示にしない |
| Durable Final | source/auth/generation/order検証とWriter commit成功後に既存Transcriptとして表示 |
| Rejected/Superseded | partialを消し、内部event名ではなく「確定できませんでした」等のtruthful UX候補 |

partial → durable finalは同じutterance projectionを視覚的に置換し、二重表示しない。内部の`provider_final`、`lease_expired`等をそのままUserへ見せない。発話開始→partial、partial cadence、speech end→final、final→durableのSLAは固定せず、HUX-D01で実測する。

## 16. State model candidate

内部stateを相互に独立させる。

| Domain | Candidate states |
|---|---|
| Capture | idle / requesting_permission / active / paused / stopping / stopped / failed |
| Relay | disconnected / authorizing / connecting / active / revoking / closing / closed / failed |
| Provider | disconnected / connecting / streaming / finalizing / closed / failed |
| Transcript projection | unavailable / listening / partial_visible / validating_final / durable_available / failed |
| CO Request | idle / snapshotting / waiting_latest_final / ready / processing / answer_ready / unavailable |

Product UIは内部state名を露出せず、複合stateからtruthfulな短い表示へmappingする。Capture activeでもRelay failedなら「マイク入力あり／リアルタイム文字起こし停止」を区別する。Waveformが動くことをTranscription成功表示に使わない。

## 17. CO-D01｜Bounded Finalization Grace

### 17.1 Flow candidate

1. 本人が`[COに相談]`を明示押下する。
2. current durable checkpointとcurrent transcript revision IDsを`base snapshot`として固定する。
3. latest partialが存在する場合だけ、そのprovider session / event / source range identityをwait targetにしてbounded graceを開始する。
4. 対象Provider Finalを受領する。別generationや別rangeのfinalは待機解除条件にしない。
5. current authorization / Consent / generation / source range / order / duplicateをvalidateする。
6. C03-D03でexisting Writerへcommitする。
7. commit成功時だけ、`base snapshot + 待機対象のDurable Final Commit`を再認可して`final context snapshot`を固定する。grace中に別参加者・別rangeで追加されたrevisionを暗黙包含しない。
8. final snapshot IDをAiCommonSharedAiRequestへbindしてCO Requestを開始する。

graceなし、timeout、Provider error、invalid range、revokeの場合はpartialをContextへ昇格しない。CO Request開始後のlate finalを進行中requestへ追加しない。

### 17.2 Approved Product decisions and deferred parameters

- `P-04 RESOLVED`: bounded graceを採用する。具体秒数はHuman UX Verificationで決定するdeferred UX parameter。
- `P-07 RESOLVED`: timeout / Provider error / invalid range / authorization・Consent・Writer failure時はpartialを昇格せず、最新発話を確定できなかったことをtruthfulに扱い、押下時base durable snapshotでCO Requestを続行する。

## 18. DG-D01｜Provider-neutral Port and Deepgram Adapter

### 18.1 Provider-neutral port

Domainが扱うoperation候補:

- open normalized streaming session
- send accepted canonical source range
- receive normalized event envelope
- request provider finalize（P-01で承認されたPause / Normal Endのbounded final-only drainだけ）
- close / abort
- usage / safe error projection

Domain portはDeepgram endpoint、query parameter、native event名を知らない。

### 18.2 Deepgram Adapter only

Adapterだけが次を知る。

- Nova-3 Streaming endpoint / protocol
- `ja-JP`相当のlocale/model設定
- diarization / intermediate result / timing parameter
- Provider Session identity
- Deepgram event shape / Finalize semantics / error code
- provider timingからnormalized envelopeへの変換

### 18.3 MIP Fail Closed

1. Request builderで`mip_opt_out=true`をexact booleanとして設定する。
2. network transport直前のindependent guardでactual request projectionを再検証する。
3. 欠落、false、型不正、検証不能ならsocketを開かない。
4. sanitized Evidenceには`mip_opt_out_enforced=true`とguard resultだけを残し、request header / keyは残さない。
5. Evaluation Credentialをproduction configurationへ移さない。

### 18.4 Runtime / SDK technical candidate（未承認）

- T-01でisolated Node relay workerが承認された場合、server-sideの公式`@deepgram/sdk` v5系を第一候補とする。
- exact package versionは未選定とし、H-TECH-03承認後にpinしてlockfile・integrity・licenseを確認する。現時点では導入しない。
- SDK境界でactual requestの`mip_opt_out=true`二重guard、Provider event identity、source cursor mapping、automatic retry/reconnect禁止を保証できない場合は、direct WSSを技術代替候補として比較する。
- BrowserからDeepgramへ直接接続せず、Provider secretとnetwork boundaryはCompany OS Relayに閉じる。
- 根拠資料: Deepgram公式JavaScript SDK <https://github.com/deepgram/deepgram-js-sdk>、公式Streaming STT overview <https://developers.deepgram.com/docs/getting-started-with-live-streaming-audio>
- 本節はT-01、H-TECH-03、新Package導入、Provider通信の承認を意味しない。

## 19. No Automatic Fallback and failure UX

Deepgram / Relay failure時:

- Azure、旧55秒batch、他Providerへ自動送信しない。
- Provider audio egressを停止する。
- Partialを破棄し、未commit finalをcanonical表示しない。
- Text Conversationと既存durable transcript閲覧は継続可能。
- Userへ「リアルタイム文字起こしは停止しました」とtruthfulに表示する。
- retryはUserの明示操作でnew generation / new lease / new Provider Sessionとする。
- P-02によりfailure後はCaptureも停止する。Text Conversationは継続し、自動retryせず、Userの明示操作でnew generation / lease / Provider Sessionを作成する。

## 20. FAIL-D01｜Failure matrix

| Failure | Capture | Relay | Provider | Partial UI | Durable Final | CO Request |
|---|---|---|---|---|---|---|
| mic failure | failed/停止 | connectしない | 未接続 | なし | 既存のみ | durable snapshotのみ可 |
| permission denied | idle/denied | connectしない | 未接続 | なし | 既存のみ | textで可 |
| permission revoked | 即時cleanup | forced close | abort | 破棄 | cutoff後reject | partial除外、base durable snapshotで続行 |
| device disconnected | failed/cleanup | close | close | 破棄 | verified済みのみ | partial除外、base durable snapshotで続行 |
| network interruption | sample生成停止。unsent bufferはnew generationへ暗黙再送しない | failed/expiry | close | stale表示せず破棄 | uncommitted reject | base durableのみ |
| Relay unavailable | start不可 | failed | 未接続 | なし | 既存のみ | textで可 |
| lease expired | egress停止 | forced close | close | 破棄 | expiry後reject | base durableのみ |
| Consent revoked | 即時停止 | hard revoke | abort | 破棄 | late reject | AI Referenceも再認可 |
| participant removed | 即時停止 | hard revoke | abort | 破棄 | late reject | request不可 |
| Deepgram connection failure | Capture停止 | close/no fallback | failed | 破棄 | 既存のみ | base durable snapshotで続行可 |
| Deepgram late event | 影響なし | cutoff検証 | event受領後discard | 反映しない | commitしない | 進行中へ追加しない |
| duplicate event | 影響なし | idempotent | 1件扱い | 二重表示なし | unique receiptで1回 | 影響なし |
| out-of-order event | 影響なし | receive order検証 | buffer/validate | older partial破棄 | range/order不成立ならreject | 影響なし |
| invalid source range | current generation停止 | fail closed | close | 破棄 | commit禁止 | partial除外、base durable snapshotで続行 |
| Writer failure | captureは独立 | event保持/close条件 | 追加送信判断はstate依存 | canonical化しない | transaction rollback | snapshot作成しない |
| DB transaction failure | safety停止 | lease/commit fail closed | close | 破棄 | partial commit禁止 | 開始しない |
| grace timeout | captureは通常state | 影響なし | auto fallbackなし | latest partial除外 | 既存durableのみ | base durable snapshotで続行 |
| page background | Pause / Close、false active表示禁止 | close | close | 破棄 | verified済みのみ | dispatch済みrequestはserver stateで管理。foreground自動Resumeなし |
| page close | track cleanup | disconnect/close | close | 破棄 | cutoff policy適用 | new request開始なし |

どのfailureでも、実際にCapture / Relay / Providerが停止しているのに「録音中」「文字起こし中」と表示しない。

## 21. REG-D01｜Regression matrix

| Existing contract | Required regression evidence |
|---|---|
| organization / conversation authorization | cross-tenant、inactive org、archived conversation拒否 |
| participant authorization | non-participant、removed/deactivated participant拒否 |
| membership epoch / credential generation | stale lease/frame/final/reconnect拒否 |
| Consent | issue/refresh/send/commit/CO各boundaryでrevoke拒否 |
| session state | prepare/active/paused/ended/cancelled transition維持 |
| generation / sequence / idempotency | stale generation、duplicate frame/event/control/commit拒否 |
| temporary audio / encrypted storage / cleanup | existing bounded contractを壊さず、realtime raw audio非永続 |
| Transcript Writer | Adapter/Controller直接保存禁止、existing validation経由 |
| immutable Revision / human correction / lineage | provider finalとhuman revisionの親子・current pointer整合 |
| Long Context | durable current revisionのみ、partial混入なし |
| explicit CO Request | auto invocationなし、authorized snapshot固定 |
| visibility boundary | active audience外へのpartial/final/context非表示 |

## 22. HUX-D01｜Human UX Verification plan

実装後、Automated PASSとは別に人が確認する。

- Desktop Chrome / Edge
- 390px viewport
- iPhone Safari実機
- installed PWA
- mic permission allow / deny / revoke
- device disconnect
- one `getUserMedia` Evidence
- uninterrupted continuous capture Evidence
- silence → speech → silence waveform
- reduced motion / keyboard / readable state
- partialが自然に継続更新される体感
- partial書換えとdurable finalへの一回だけのtransition
- speaker indication（anonymous hintとperson identityの混同なし）
- Pause / Resume / End / Cancel
- Consent revoke / participant removalで実audio egress停止
- background / foreground / page close
- Deepgram failure時のno fallback / truthful state
- `[COに相談]`押下、grace、timeout、late final exclusion

計測候補:

- speech start → first partial
- partial update cadence
- speech end → Provider final
- Provider final → durable projection
- revoke event → last Provider audio send / socket close

現段階ではProduct SLAを固定しない。数値と「会話中に文字が自然にさらさら現れる」体感を併記する。

## 23. DONE-D01｜Four-layer Done Contract candidate

| Area | Product Design | Repository Implementation | Automated Verification | Human UX / Operational Evidence |
|---|---|---|---|---|
| CE-C01 | lease/revoke/forced close/no stale reuse | lease + outbox + registry + close ACK | revoke/reconnect/race/idempotency/fault tests | revoke後に実送信停止を観測 |
| Source Cursor | sample正本/gap/overlap/no guess | cursor + send ledger + constraints | range/property/duplicate/order tests | continuous sessionでgapなしEvidence |
| Event Receipt | provider-neutral/partial ephemeral | DTO + safe durable receipt | raw payload/partial非保存、ordering tests | internal stateをUserへ露出しない |
| Durable Final | Writer commit後canonical | sole committer + commit receipt | transaction rollback/one-time commit | partial→finalが二重表示なし |
| Waveform | same stream/local energy/truthful | Web Audio + visible accessible UI | single stream/cleanup/lifecycle tests | 実micでsilence→speech→silence視認 |
| Continuous Capture | ASR待ちで停止しない | continuous producer + WSS relay | long-run/frame continuity tests | Desktop/iPhone/PWAで体感確認 |
| CO Grace | explicit only/durable snapshot | target final wait + fixed checkpoint | timeout/late/revoke/context tests | 最新発話の包含/除外が理解可能 |
| Deepgram | provider-neutral/MIP/no fallback | port + adapter + double guard | synthetic event/MIP fail-closed tests | approved limited real connection Evidence |

いずれも4層の必要Evidenceが揃うまでDone / PASSにしない。Provider実接続が必要な項目は、別途明示承認されたlimited verificationを行う。

## 24. Gate candidate

| Area / Blocker | Candidate status | Remaining requirement |
|---|---|---|
| CE-C01 / CE-B-C01-01 | **Resolved by Approved Design candidate** | Corrective Design最終承認、T-01/T-02/T-08、実装・forced-close/Provider verification |
| CE-C03 / CE-B-C03-01 | **Resolved by Approved Design candidate** | T-03/T-04/T-05、Migration作成承認、exact range verification |
| CE-C03 / CE-B-C03-02 | **Resolved by Approved Design candidate** | T-05/T-09、normalized receipt implementation/Provider Evidence |
| CE-C03 / CE-B-C03-03 | **Resolved by Approved Design candidate** | T-06、atomic Writer integration verification |
| CE-C08 / CE-B-C08-01 | **Resolved by Approved Design candidate** | T-03/T-07、runtime/browser/Provider/Human UX verification |
| CE-C08 / CE-B-C08-02 | **Resolved by Approved Design candidate** | repository implementation + Automated + Human UX Evidence |
| CE-C08 / CE-B-C08-03 | **Resolved by Approved Design candidate** | partial/final implementation + Provider + Human UX Evidence |
| CO / CE-B-CO-01 | **Resolved by Approved Design candidate** | P-04 deferred値、T-06、grace/context/Human UX verification |
| CE-G01 | **Still Closed / Human Gate Required** | 本書だけではOPENしない |

`Resolved by Approved Design candidate`は、P-01〜P-07反映後の設計で各Compatibility gapを解消できる候補であることを示す。Corrective Design全体が人＋ChatGPTに最終承認されるまではBlockerをOPEN維持し、Resolvedと確定しない。承認後もImplementation / Automated / Provider / Human UX Evidenceは別のOPEN verification itemとして残す。

## 25. CE-G01 OPEN review checklist

| Review item | Candidate |
|---|---|
| Product Decision Pending | 0 / Ready |
| CE-C01 corrective design | Design Ready |
| CE-C03 source cursor | Design Ready / Technical Verification Required |
| Provider-neutral receipt | Design Ready / Technical Verification Required |
| Durable Final Commit Receipt | Design Ready / Technical Verification Required |
| additive Migration design | Design Ready / Migration作成のHuman Approval Required |
| CE-C08 continuous capture | Design Ready / Technical Verification Required |
| Waveform | Design Ready / Human UX Verification Required |
| Partial / Final UX | Design Ready / Human UX Verification Required |
| CO Finalization Grace | Design Ready / Numeric UX Parameter Deferred |
| Deepgram Adapter boundary | Design Ready / Provider Verification Required |
| MIP Fail Closed | Design Ready / Automated + Provider Evidence Required |
| Failure Matrix | Design Ready |
| Regression Matrix | Design Ready |
| Human UX Evidence Plan | Design Ready |
| T-01 relay runtime topology | Human Approval Required before CE-P1 |
| T-08 control delivery topology | Human Approval Required before CE-P1 |
| SDK/direct protocol + package/version | Human Approval Required before Adapter phase |
| CE-P1 Scope / Branch / Done Contract | Human Final Gate Approval Required |

CE-G01をOPENできるかは人＋ChatGPTが判断する。本書は自動OPENしない。

## 26. Stop state

- CE-G01: CLOSED
- CE-C01 / CE-C03 / CE-C08 / CO Blockers: OPEN
- CE-P1: NOT STARTED
- Migration: candidate only / not created / not run
- Deepgram Adapter: not implemented
- Provider communication / audio send: 0 / 0
- Production / Deploy: not performed
- Next action: Human + ChatGPT Realtime Corrective Design Review
