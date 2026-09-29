# Company OS｜S11CD-B Realtime Corrective Codex Instructions v001

- Document status: **FUTURE IMPLEMENTATION HANDOFF CANDIDATE — NOT AN APPROVAL**
- Date: 2026-09-29 JST
- CE-G01: **CLOSED**
- CE-P1: **DO NOT START**
- Public GitHub push: **NOT AUTHORIZED**
- Provider communication: **NOT AUTHORIZED BY THIS DOCUMENT**
- Audio send: **NOT AUTHORIZED BY THIS DOCUMENT**

## 1. Purpose

本書は、人＋ChatGPTがRealtime Corrective Designを承認し、CE-G01 OPENとCE-P1開始を明示した場合に使うCodex implementation handoff候補である。本書が存在するだけでは、Product Code、Migration、DB、Provider通信、branch作成、push、deployの権限は生じない。

Authoritative companion documents:

1. `CompanyOS_S11CD_B_CE_Realtime_Corrective_Design_v001.md`
2. `CompanyOS_S11CD_B_CE_Realtime_Corrective_Decisions_Pending_v001.md`
3. `CompanyOS_S11CD_B_CE_Realtime_Compatibility_Focused_Verification_v001.md`
4. PPT v155 / Excel v050

## 2. Mandatory preconditions before any CE-P1 work

次がすべて明示されるまで実装を開始しない。

- Human + ChatGPT Corrective Design Review: Approved
- applicable Blocker: `Resolved by Approved Design`
- Product Pending P-01〜P-07: resolvedまたは後工程境界を明示
- CE-G01: Human + ChatGPTが明示的にOPEN
- CE-P1 scope / branch / Done Contract: approved
- additive Migrationの作成可否: approved
- persistent relay process / WSS / control bus等のinfra scope: approved
- new package / SDKが必要な場合: package名・version・隔離方法を承認
- Provider実接続が必要な場合: data / request count / cost / privacy / stop conditionを承認

一つでも不足していれば、Design Review待ちとして停止する。

## 3. Absolute boundaries

- CE-G01 Closed Contract、IR-1、Delta B Formal Closed Contractを勝手に変更しない。
- PPT v155 / Excel v050を勝手に更新しない。
- Provider decisionを再選定しない。
- BrowserへDeepgram key / Authorization Headerを渡さない。
- Evaluation Credentialを正式実装へ流用しない。
- Azure、旧55秒batch、他Providerへ自動fallbackしない。
- Provider native payloadをDomain ModelまたはTranscript Modelへ直接保存しない。
- PartialをDB / Source / Citation / Revision / Long Context / CO / Decision / Action / Memoryへ保存しない。
- Provider timestampをCompany OS source cursorの正本にしない。
- past transcriptへ架空rangeをbackfillしない。
- Provider FinalをWriter commit前にcanonical表示しない。
- public GitHub remoteへCorrective Design / security findingsをpushしない。
- production / demo deployを自動実行しない。

## 4. SAFE implementation sequence candidate

各Phaseは独立したVerified Deltaとし、前PhaseのEvidenceなしに次へ進まない。

### Phase P1-A｜Contract and test skeleton

Scope:

- provider-neutral streaming DTO / port
- lease/control/source/event/commit state enumまたはvalue object
- safe reason codes
- interfaces only、networkなし
- approved config keys with fail-closed defaults

Tests first:

- Provider-specific payloadがDomain境界を越えない。
- partialがpersistence DTOへ変換できない。
- MIP guard欠落/false/unknownでnetwork transportが呼ばれない。
- stale generation / invalid stateが拒否される。

Stop:

- Product Contract変更が必要
- interfaceがexisting Writer/Revisionを再利用できない

### Phase P1-B｜Additive schema

Prerequisite: Migration作成の明示承認。

Implement candidate:

- `ai_common_shared_relay_leases`
- `ai_common_shared_relay_control_events`
- `ai_common_shared_source_ranges`
- `ai_common_shared_provider_sessions`
- `ai_common_shared_provider_send_ranges`
- `ai_common_shared_provider_event_receipts`
- `ai_common_shared_durable_final_commits`
- `ai_common_shared_durable_final_commit_items`

Requirements:

- additive only
- old code / old records remain readable
- nullable/default strategy approved
- FK / unique / indexes match MIG-D01
- no historical source-range backfill
- rollback disables feature and reverses additive schema only when safe
- JST timestamps / application timezone verified

Evidence:

- migration up/down on isolated DB
- existing records unchanged
- old bounded transcript remains readable
- constraint violation tests

### Phase P1-C｜Authorization lease and control plane

Implement:

- C01-D01 lease issue / refresh / expiry
- transactional control outbox
- connection registry
- forced close acknowledgement
- hard revoke and approved lifecycle close semantics
- new generation reconnect

Tests:

- Consent revoke / participant removal / credential change / archive
- pause / end / cancel / expiry
- duplicate control / double reconnect / late close
- control bus loss / refresh failure / lease expiry
- no audio frame accepted after cutoff

Evidence must distinguish:

1. DB stream interrupted
2. Relay stopped accepting frames
3. Provider audio send stopped
4. Provider socket closed

### Phase P1-D｜Continuous source cursor

Implement:

- approved canonical frame format
- absolute sample cursor, inclusive/exclusive rule
- client event identity / SHA-256
- accepted source range receipt
- provider send ledger
- gap / overlap / duplicate / resend handling

Tests:

- property tests for contiguous ranges
- exact duplicate is ACK-only
- conflicting duplicate fails closed
- unresolved gap produces no fabricated silence
- out-of-order bounded behavior
- reconnectは同一capture streamのcursorを維持し、new track/timebaseでnew capture streamを作る場合だけ0から開始する
- Providerへsame source rangeを二重送信しない

### Phase P1-E｜Provider-neutral receipt and Deepgram adapter

Prerequisite:

- approved SDK/package/runtime scope
- official credential configured outside Repository
- Provider communication remains disabled for synthetic stage

Implement synthetic-first:

- ProviderStreamingEventEnvelope
- ephemeral partial path
- durable safe final/metadata/error/close receipt
- Deepgram event normalization
- Provider Session mapping
- MIP double guard
- no automatic fallback

Synthetic tests:

- partial / final / metadata / error / close
- duplicate / out-of-order / late / superseded
- word timing / anonymous speaker hint
- raw payload / secret / header redaction
- partial content not persisted
- MIP guard before any network call

Do not perform real Provider communication until separately approved.

### Phase P1-F｜Durable Final Commit

Implement:

- sole `RealtimeDurableFinalCommitter`
- current authorization / Consent / generation / range/order validation
- existing Transcript Writer / Revision / lineage integration
- one final receipt → one commit receipt
- commit items for multiple Segment/Revision
- current Long Context dirty/checkpoint behavior

Tests:

- idempotent retry
- duplicate final
- DB rollback / Writer exception
- revoke between final receive and commit
- human correction lineage remains valid
- canonical projection only after commit

### Phase P1-G｜Single continuous capture and waveform

Implement:

- one `getUserMedia`
- one MediaStream
- approved continuous frame producer
- authorized WSS relay
- same-stream browser-local energy
- visible accessible waveform
- cleanup for pause/end/cancel/revoke/device/permission/page lifecycle
- independent Capture / Relay / Provider / Transcript states

Automated evidence:

- second `getUserMedia` does not occur
- ASR await does not stop capture
- analyser/audio graph cleanup is idempotent
- reduced-motion behavior
- no false active label after failure
- 390px rendering contract

Do not claim Human UX PASS from automated tests.

### Phase P1-H｜Partial / Final UI and CO grace

Implement:

- ephemeral rewritable partial
- validating final state without canonical exposure
- one transition to durable final after Writer commit
- explicit `[COに相談]`
- base durable snapshot
- latest-partial-only bounded grace
- target final validation/commit
- final context snapshot fixed before CO request
- timeout/error/revoke behavior per approved P-04/P-07
- no late final injection into active request

Tests:

- no partial in DB/Long Context/CO
- unrelated final does not release grace
- grace中のunrelated durable finalをclick時snapshotへ暗黙包含しない
- timeout uses approved outcome
- late final after dispatch does not alter request context
- current authorization rechecked at snapshot and publish

### Phase P1-I｜Limited Provider verification

Prerequisite: explicit approval specifying data, requests, cost, privacy and stop conditions.

Verify:

- actual Deepgram request has `mip_opt_out=true`
- server-held credential only
- connection / partial / final / word timing / speaker hint / usage
- source mapping with existing approved audio
- revoke / close egress behavior within approved test scope
- sanitized Evidence completeness

One incomplete Evidence result must not be guessed into PASS. Retry only within explicit attempt approval.

### Phase P1-J｜Human UX and regression

Human UX:

- Desktop Chrome / Edge
- 390px
- iPhone Safari
- installed PWA
- permission allow / deny / revoke
- device disconnect
- silence → speech → silence waveform
- single getUserMedia
- continuous capture
- partial / final / speaker indication
- Pause / Resume / End / Cancel
- Consent revoke / participant removal
- background / foreground
- Provider failure / no fallback
- CO grace / timeout / late final

Regression:

- organization / participant authorization
- membership epoch / credential generation
- Consent / session state / generation / sequence / idempotency
- temporary audio / encrypted storage / cleanup
- Transcript Writer / immutable Revision / human correction / lineage
- Long Context / explicit CO Request / visibility boundary

## 5. Failure implementation contract

Use `FAIL-D01` as the minimum matrix. For every failure test record six independent outcomes:

1. Capture
2. Relay
3. Provider
4. Partial UI
5. Durable Final
6. CO Request

Never infer Provider stop from DB state alone. Never display an active state when the corresponding runtime is stopped or unknown.

## 6. Data handling implementation contract

- realtime raw audio: bounded memory only by default
- raw Provider payload: never durable, never log
- partial text: memory only
- final text: existing Transcript Writer only
- receipt: normalized minimal metadata, safe reason, hash/range
- speaker: anonymous hint; identity confirmation remains existing human lineage
- usage/error: sanitized, no key/header/body
- deletion: approved retention and tenant/session cascade
- credential: environment/secret store only, never Repository/report/browser

## 7. Done evidence checklist

Each feature must have all applicable layers.

| Layer | Minimum artifact |
|---|---|
| Product Design | approved Corrective Design and resolved/deferred Product Pending |
| Repository | focused diff, interfaces, migrations, UI, no unrelated files |
| Automated | focused tests + connected regression + failure/race tests |
| Provider | explicitly approved limited real Evidence where required |
| Human UX | real browser/device observation where required |

Waveform example:

- Product: same MediaStream / local energy / truthful independent state
- Repository: Web Audio processing + visible UI
- Automated: single stream / cleanup / lifecycle
- Human: actual mic silence → speech → silence

Automated PASSだけでHuman UX Doneにしない。

## 8. Git and public remote boundary

- preserve local commit `89f2dea`; amend / squash / rebaseしない。
- implementation branchは明示承認後だけ作る。
- stage only approved task files。
- current remoteがPublicである間、internal architecture/security/master hashを含む設計・Evidenceをpushしない。
- Secretがないことだけをpublic-safe判定に使わない。
- commitとpushを分離し、push authorizationを確認する。
- pushとdeploymentを分離する。
- deployment workflowはmanual `workflow_dispatch`のみ。
- production deploy / migrationは別の明示承認が必要。

## 9. Mandatory stop conditions

直ちに停止し、人＋ChatGPT Reviewへ戻す。

- Product Contract変更が必要
- Closed Contractとの矛盾
- provider-neutral / security boundaryを維持できない
- additiveでなくdestructive Migrationが必要
- existing Writer / Revision / lineageを再利用できない
- BrowserへProvider Secretが必要
- new public network / external port / tunnel / firewall変更が必要
- unapproved package / SDK / persistent processが必要
- new Provider Evaluationが必要
- migration target / rollback / backupが不明
- real Provider data/cost/privacy scopeが未承認
- Public remoteへのsensitive pushが必要

## 10. Required implementation reporting

将来の各Phase終了時に最低限報告する。

- approved scope / actual delta
- Product Pending / Technical Pendingの変化
- Blocker state（設計承認と実装PASSを分離）
- Migration / DB / Storage impact
- security / privacy / MIP / credential boundary
- failure / regression evidence
- Provider communication / audio duration / request / cost
- Human UX Evidence
- Git commit / push / deploymentを別々に記載
- CE-G01 / CE-P1 / next gate

## 11. Current stop state

本Handoff作成時点:

- Corrective Design: candidate only
- Product Decision Pending: 7
- Technical Pending: 10
- Compatibility Blocker: 8 OPEN
- CE-G01: CLOSED
- CE-P1: NOT STARTED
- Migration: not created / not run
- Product Code: unchanged
- Provider communication: Deepgram 0 / Azure 0
- Audio send: Deepgram 0 / Azure 0
- Deployment: none

Human + ChatGPT Realtime Corrective Design Reviewが次の停止地点である。
