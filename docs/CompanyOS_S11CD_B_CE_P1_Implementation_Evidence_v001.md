# Company OS｜CE-P1 Realtime Corrective Implementation Evidence v001

- Evidence date: 2026-09-30 JST
- Branch: ce-p1-realtime-corrective
- Baseline / prior local commits: 9890dc0 / ae95731 / ce49baa / 84bf025
- Public push / deploy: 0 / 0
- CE-P1 Deepgram executions: 5 authorized single-attempt runs total; final run recorded Provider acceptance false and audio send 0
- Production Credential use: 0
- Production / normal local / shared DB migration: 0
- Realtime feature flags: default OFF / audio send OFF

This update records the approved implementation through P1-H and every authorized P1-I Limited Verification through the final attempt. P1-I remains inconclusive because the final run stopped before Provider acceptance and the SDK ErrorEvent reason was flattened before sanitized persistence. A Provider-free Evidence-boundary Corrective is complete. Human UX is not started.

## 1｜Phase status

| Phase | Status | Evidence disposition |
|---|---|---|
| P1-A Contract / Test Skeleton | DONE | Provider-neutral DTO/Port, canonical frame, safe reasons, fail-closed configuration and pre-network MIP/audio-send guards. |
| P1-B Additive Realtime Schema | DONE | Approved eight-table additive migration and isolated migration evidence. |
| P1-C Authorization Lease / Control | DONE | Tenant/session/stream/generation/audience/Consent-bound short lease, refresh, control outbox and lifecycle fencing. |
| P1-D Continuous Source / Send Ledger | DONE | PCM16 16 kHz mono 100 ms frames, absolute contiguous sample cursor and Provider send mapping. |
| P1-E Receipt / Deepgram Adapter | DONE — synthetic | Ephemeral partial, normalized encrypted final evidence, timing, speaker hint and late/out-of-order fencing. |
| P1-F Compatibility / Durable Final | IMPLEMENTED / AUTOMATED PASS | Bounded/realtime discriminator and mandatory Commit→Receipt→verified Source Range→Segment/Revision lineage. |
| P1-G Capture / Waveform | IMPLEMENTED CANDIDATE / AUTOMATED PASS | One microphone stream/Web Audio graph, 100 ms frames, waveform, same-origin WSS lease boundary and lifecycle cleanup. Actual relay runtime and Human UX remain unverified. |
| P1-H Partial / Final / CO Grace | IMPLEMENTED CANDIDATE / SYNTHETIC PASS | Ephemeral partial, validating final, Durable Final-only canonical UI, exact-target grace and immutable click-time request snapshot. |
| P1-I Limited Provider Verification | INCONCLUSIVE / TECHNICAL VERIFICATION REQUIRED / STOPPED | Final authorized Request stopped before Provider acceptance/audio send. Exact transport reason remains Unknown because the SDK ErrorEvent was flattened before persistence. Provider Failure is not established. No retry or resend followed. See Section 17. |
| P1-J Human UX / device matrix | AUTHORIZED WITH KNOWN P1-I LIMITATION / READY FOR HUMAN MICROPHONE GATE | Local-only Product Path, isolated DB, WSS Relay and Human package are prepared. Provider communication/audio remain 0/0 until the human starts the microphone. P1-I is not promoted. |

## 2｜P1-F Compatibility and bounded preservation

- Transcript Segment source_kind distinguishes bounded_audio and realtime_source.
- Existing bounded creation explicitly writes bounded_audio.
- Bounded rows still require a real audio_window_id and prohibit a realtime commit reference.
- Realtime rows require audio_window_id = null and an exact realtime_durable_final_commit_id.
- SQLite and MySQL/MariaDB triggers prevent discriminator spoofing.
- The model plus RealtimeTranscriptSourceGuard prevents callers from choosing realtime merely to bypass AudioWindow.
- No synthetic AudioWindow, raw realtime audio, fabricated backfill or destructive migration was created.
- The existing 55-second bounded server contract remains available; the realtime UI does not silently select it.

Required realtime lineage:

Provider Final → Provider Event Receipt → verified Source/Send Range → Durable Final Commit → Transcript Segment → immutable Provider Revision

RealtimeDurableFinalCommitter rechecks authorization, required Consents, generation, active Lease, verified contiguous ranges, fingerprints, final hash and idempotency under transaction locks. Missing/stale lineage fails before canonical Transcript creation.

## 3｜Writer, transaction and race evidence

Automated evidence confirms:

- bounded null AudioWindow: DB FAIL;
- realtime without exact commit: DB FAIL;
- direct realtime discriminator spoof: application FAIL;
- same Receipt + operation: idempotent;
- same Receipt + different operation: FAIL;
- duplicate Provider final receipt: idempotent;
- final after Lease revocation: rejected and cannot create a Segment;
- injected Writer exception rolls back Commit, Commit Item, Segment and Revision;
- human correction advances Segment current Revision without rewriting the Durable Final item’s original Provider Revision;
- unrelated realtime null AudioWindow IDs are not treated as one bounded window;
- Long Context receives Durable Final revisions only.

## 4｜P1-G capture and waveform

Repository implementation provides:

- exactly one getUserMedia and one continuous MediaStream;
- one AudioWorklet and analyser using the same stream;
- PCM signed 16-bit little-endian, 16 kHz, mono, 1,600 samples / 100 ms;
- ordered metadata then binary PCM with absolute sample cursor and SHA-256;
- accessible waveform and independent Capture / Relay / Provider / Transcript labels;
- idempotent cleanup for Stop, Cancel, page hide, background, device end, permission revoke and unexpected WSS close;
- reduced-motion handling, no false active label and 390 px responsive contract;
- no automatic reconnect or retry.

The Laravel lease boundary remains fail closed unless realtime and audio-send are enabled. It permits only wss:// with the exact application host. HTTP tests create no Provider Session or Receipt.

P1-I added an isolated Node relay verification runtime and direct `ws@8.22.0` dependency. The listener is hard-bound to loopback with one accepted WSS connection, an exact origin/path check, and no public port, Tunnel, Firewall or Production reverse-proxy change. The process is session-bounded and is not a Production deployment.

## 5｜P1-H partial, final and CO grace

- Partial text is rewritable and memory-only; persistence is prohibited.
- Final candidate displays validating and is not canonical.
- Only server-confirmed durable_final enters the durable list.
- The explicit CO form captures only the latest complete Provider Session + expected receive-order target.
- CO captures base Durable Revision IDs before waiting.
- Grace defaults to 1,500 ms and is fail closed outside 0..2,000 ms.
- Grace releases only for the exact Session, Provider Session, receive order, accepted final Receipt and committed Durable Final.
- Unrelated final does not release grace.
- Timeout continues from the base Durable snapshot and excludes partial.
- The request snapshot is immutable before the CO Provider request; unrelated/late finals cannot enter it.
- Stale Revision identity fails closed; authorization is rechecked again before publication.

## 6｜Runtime / SDK / privacy

- Isolated runtime: portable Node.js v24.21.0 Windows x64.
- SDK: @deepgram/sdk@5.10.0, server-side boundary only.
- SDK integrity: sha512-GNse88Irf4ow4UlL+WbJjDOnJbpnX6B6e21PLxzfG0ioJlx5rVGbEX0OF06QgTiVyJ+VCtG5gdNum5DV2pwI1A==.
- SDK license: MIT.
- Direct WSS server dependency: ws@8.22.0 exact-pinned, MIT, Node-compatible; offline audit reported 0 vulnerabilities.
- Global install / PATH mutation: none.
- Browser/Repository/report/log Provider Credential: none.
- mip_opt_out=true is fixed in the request builder and checked again immediately before network creation.
- automatic_retry=false; SDK v5 uses reconnectAttempts=0 with AbortSignal. Manual reconnect is 0.
- Raw Provider payload is never durable; partial is memory-only; normalized final metadata is encrypted at rest.
- Feature flags remain OFF after tests.

## 7｜Migration evidence

MIG-D01 retains the approved eight additive realtime tables. The approved compatibility migration adds only source_kind, nullable realtime commit FK, the physically nullable AudioWindow column constrained by fail-closed triggers, and a session/source/range index.

Final isolated SQLite verification:

1. new temporary DB, full repository migration up: PASS;
2. compatibility migration down: PASS;
3. compatibility migration reapply: PASS;
4. temporary DB removed;
5. Production/shared/normal local DB untouched.

Down refuses an unsafe bounded-only restoration while realtime rows exist. Migration repository ledger is now 108; Release Hardening was updated and passes.

## 8｜Automated verification

| Verification | Result |
|---|---|
| P1 focused communication-free | 11 PASS / 111 assertions / gated P1-I 1 SKIP |
| P1 + bounded P3/P4/P5 + Release Hardening | 37 PASS / 339 assertions / gated P1-I 1 SKIP |
| Release Hardening | 4 PASS / 29 assertions |
| Isolated migration up / compatibility down / reapply | PASS |
| Relay synthetic Node / P1-I fences | 5 PASS |
| Relay and browser JS syntax | PASS |
| Full Laravel suite after P1-I stop | 648 PASS / 1 existing FAIL / 17 SKIP / 4,995 assertions |

No communication-free CE-P1 automated test failed. The single full-suite failure is the pre-existing CompanyNavigation stale intended-URL expectation and is outside the changed CE-P1 paths. ScopeNine date-sensitive cases passed on this run. The separately gated real Provider test returned non-success as recorded in §10; it was not part of the closed-gate full-suite run.

## 9｜Evidence disposition

| Layer | Disposition |
|---|---|
| Product Design | Approved P1-F extension implemented |
| Repository | P1-A〜P1-H candidate implemented |
| Automated | Focused, connected regression, race/rollback and migration PASS |
| Provider | INCONCLUSIVE_EVIDENCE_FAILURE; initial execution plus one explicitly authorized corrective execution; no retry/resend after the corrective execution |
| Human UX | AUTHORIZED WITH KNOWN P1-I LIMITATION / human operation not yet started |
| CE-P1 | IN PROGRESS / stopped after inconclusive P1-I |
| CE-G01 | CLOSED |

P1-G/H are not promoted to Product/Human PASS from automated evidence alone.

## 10｜P1-I initial authorized execution

Execution date: 2026-09-30 JST.

Approved boundaries were implemented before execution:

- Deepgram Nova-3 Streaming / Japanese / diarization / interim results;
- the approved 18.9016875-second synthetic WAV and SHA-256 `396F978F02BB43D22BA69BACB01F13B59F36BA11FF729AFA144549D60CC5141A`;
- one attempt maximum, retry 0, automatic/manual reconnect 0;
- conservative full-audio estimate USD `0.0030557728125`, hard ceiling USD `0.01`;
- `mip_opt_out=true` at the actual SDK request projection;
- server-side CurrentUser DPAPI Credential load only;
- direct WSS bound to `127.0.0.1` with an ephemeral local certificate;
- Azure and every other Provider disabled;
- isolated in-memory test DB only; Repository `.env` and normal local DB hashes checked before/after.

Communication-free verification before the attempt:

- `@deepgram/sdk@5.10.0` and `ws@8.22.0` exact pins: PASS;
- direct dependency offline audit: 0 vulnerabilities;
- Node policy/canonical frame tests: 4 PASS;
- P1 plus bounded P3/P4/P5 and Release Hardening: 37 PASS / 339 assertions / gated P1-I test skipped;
- actual SDK option corrected from the obsolete `shouldReconnect` field to `reconnectAttempts=0`;
- sub-frame Provider timing mapping and same-request multi-Final identity Regression: PASS.

Execution result:

- the gated Integration test returned non-success after 3.13 seconds;
- the test stopped before application-side Provider Event normalization and Durable Final verification completed;
- `.env`: unchanged;
- normal local DB: unchanged;
- ephemeral TLS key/certificate: removed;
- persistent relay process after stop: none;
- retry / second request / reconnect: 0;
- Azure communication: 0;
- Production Credential / DB / deploy / public push: 0.

The original execution path asserted child-process success before writing a sanitized failure envelope. Consequently, the exact Provider handshake disposition, audio bytes/duration accepted by Deepgram, close/error detail, usage, cost and secret-absence scan were not persisted. The Harness was corrected after the attempt so a future explicitly authorized run would persist a hashed, secret-free failure envelope before asserting. That corrective was not used to resend.

Disposition:

**P1-I = INCONCLUSIVE_EVIDENCE_FAILURE**

This is not classified as a Deepgram Provider failure. It is not inferred as PASS. The maximum-one-attempt rule is treated as consumed conservatively because Provider acceptance cannot be proven either way. Actual cost is unknown; the approved full-audio conservative estimate is an upper planning value, not actual usage Evidence.

## 11｜Historical stop and next gate (superseded by Section 18)

- P1-A through P1-H: repository/automated state retained.
- P1-I: stopped after the corrective execution also ended inconclusive.
- P1-J Human UX: NOT STARTED at this historical checkpoint; superseded by Section 18.
- Human microphone/device actions: 0.
- Feature flags: default OFF; execution-scoped enablement ended with the child process.
- No third Deepgram request is authorized; the one corrective allowance is consumed conservatively.
- No P1-J preparation is promoted to PASS.
- CE-G01 remains CLOSED.

Next action is Human + ChatGPT Review of the initial and corrective P1-I disposition. Any further Provider request requires a new explicit Human authorization. Production DB migration, Production/Demo deploy, public GitHub push, Production Credential use, public port, Tunnel and Firewall changes remain prohibited.
## 12｜P1-I Corrective Limited Provider Re-Verification

Execution date: 2026-09-30 JST. Sanitized record: `docs/evidence/CompanyOS_CE_P1_I_Deepgram_Corrective_v001.json`.

The explicitly approved one additional execution was invoked once. No retry, reconnect, resend, Azure request, or other Provider request followed it.

Preflight before the execution:

- portable Node.js `v24.21.0`, `@deepgram/sdk@5.10.0`, and `ws@8.22.0`: PASS;
- isolated Node verification: 5 PASS;
- P1 focused communication-free verification: 7 PASS / 98 assertions / gated real test 1 SKIP;
- DPAPI Evaluation Credential load: PASS without displaying or persisting the secret;
- approved WAV SHA-256, 604,900-byte identity, 18.9016875-second Contract, cost limit USD `0.01`, attempt `1`, retry/reconnect `0`: PASS;
- Repository `.env` and normal local DB baseline hashes recorded before execution;
- loopback-only ephemeral TLS created under the OS temporary directory.

Corrective execution result:

- the gated test was invoked once and ended after 3.12 seconds with exit code `2`;
- PHP could not decode the child stdout as one JSON Evidence envelope (`child_stdout_json_parse_failed`);
- no sanitized child Evidence file was produced during that execution;
- the actual SDK request projection, Provider attempt/acceptance, audio samples/bytes/duration, partial/final events, timing, speaker hint, Provider identity, close state, usage, and actual cost are therefore **Unknown**;
- application-side normalization, Source Cursor mapping, and Durable Final integration were not reached;
- no field above is inferred as PASS and this result is not classified as a Deepgram Provider failure.

Post-stop evidence:

- additional execution allowance: conservatively consumed;
- retry / reconnect / resend after the execution: `0 / 0 / 0`;
- running Node processes: `0`;
- ephemeral loopback TLS key/certificate: removed;
- Repository `.env` SHA-256: unchanged;
- normal local DB SHA-256: unchanged;
- raw Credential, Authorization header, raw Provider payload, raw realtime audio, and transcript body persisted in Evidence: none;
- Production Credential / Production DB migration / deploy / public push: `0 / 0 / 0 / 0`.
- post-stop full Laravel regression: 648 PASS / 1 pre-existing CE-P1-out-of-scope FAIL / 17 SKIP / 4,995 assertions; no CE-P1 regression detected.

The Harness now records fail-closed state fields before assertion and treats deferred async rejection as handled until the awaiting boundary can serialize it. The PHP gate also persists output length/hash metadata if child stdout is not parseable. These changes were verified without Provider communication. They were **not** used for another Provider request.

Disposition:

**P1-I = INCONCLUSIVE_EVIDENCE_FAILURE / STOPPED**

**P1-J = NOT STARTED AT THIS HISTORICAL CHECKPOINT (superseded by Section 18)**

**Provider Failure = NOT ESTABLISHED**

**Next action = Human + ChatGPT Review**

## 13｜P1-I Evidence Harness Hardening

Provider communication and audio send remained `0 / 0`. The Harness no longer assumes that the complete child stdout is one JSON document. It now uses run-specific sentinel-framed JSON plus a parent-owned sanitized supervisor envelope.

The 12-case Provider-free Synthetic Failure Matrix passed, including Provider success/failure, Runtime failure, unrelated stdout, multiline output, stderr, empty/malformed/truncated output, timeout, kill, and abnormal exit. Node tests were `6 PASS`; P1 focused was `23 PASS / 376 assertions / 1 gated SKIP`; CE-P1 connected Regression was `33 PASS / 326 assertions / 1 gated SKIP`.

The full Laravel suite completed with `660 PASS / 1 pre-existing CE-P1-out-of-scope FAIL / 17 SKIP / 5,260 assertions`. The sole failure remains the known `CompanyNavigationTest::regular login ignores a stale forbidden intended url` redirect mismatch and is not caused by this Corrective.

The detailed Root Cause, IPC decision, Evidence Contract, matrix, Regression, privacy, next-request conditions, cost, and stop condition are recorded in:

- `docs/CompanyOS_S11CD_B_CE_P1_I_Harness_Hardening_Decision_v001.md`
- `docs/evidence/CompanyOS_CE_P1_I_Evidence_Harness_Hardening_v001.json`

Decision recommendation:

**Authorize exactly one additional Limited Deepgram Request only through a new explicit Human Gate.**

No request is authorized by this recommendation. Until a new approval is supplied, P1-I remains `INCONCLUSIVE_EVIDENCE_FAILURE`, P1-J was `NOT STARTED` at that historical checkpoint (superseded by Section 18), and Provider communication/audio send remain disabled.

## 14｜P1-I Hardened Limited Provider Verification outcome

The Recommended Decision was explicitly approved and the Hardened Limited Verification was invoked once. The prior stdout parsing defect did not recur: the child produced a valid `sentinel-json-v1` frame and the parent persisted sanitized process, request, Provider, audio, event, and close state.

The run stopped before Provider acceptance and before audio send. Evidence records one Provider connection attempt, `mip_opt_out=true`, reconnectAttempts `0`, audio `0 samples / 0 bytes / 0 seconds`, zero Provider events, child exit code `1`, and no raw output or Provider payload persistence. No retry, reconnect, or resend followed.

Static inspection found an SDK lifecycle mismatch: Deepgram SDK v5 begins connecting when its `ReconnectingWebSocket` is constructed, while the current Relay additionally calls `provider.connect()`, which the SDK implements as `reconnect()`. This is the strongest Runtime integration candidate. However, the parent failure writer omitted the child sanitized `safe_reason` and error-layer classification, so the execution-specific cause remains Unknown and Provider failure is not established.

Disposition:

**P1-I = INCONCLUSIVE_RUNTIME_INTEGRATION_FAILURE / STOPPED**

**P1-J = NOT STARTED AT THIS HISTORICAL CHECKPOINT (superseded by Section 18)**

**Additional Provider request = NOT AUTHORIZED**

Recommended Next Action is a Provider-free corrective for the SDK lifecycle and failure Evidence completeness, followed by synthetic/regression verification. Only after that PASS should a new maximum-one-request Human Gate be considered.

Detailed outcome:

- `docs/CompanyOS_S11CD_B_CE_P1_I_Hardened_Verification_Outcome_v001.md`
- `docs/evidence/CompanyOS_CE_P1_I_Deepgram_Hardened_v001.json`
- `docs/evidence/CompanyOS_CE_P1_I_Hardened_Verification_Outcome_v001.json`

## 15｜P1-I Runtime Integration Corrective

Provider communication and audio send remained `0 / 0`. The SDK v5 lifecycle corrective removed the redundant explicit `provider.connect()` and now treats the socket returned by `client.listen.v1.connect()` as already starting its initial connection.

Synthetic lifecycle Evidence is `socket 1 / initial start 1 / explicit connect 0 / reconnect 0 / retry 0 / duplicate socket 0`. Parent Evidence now persists sanitized child `safe_reason` and `error_layer_classification`; a non-PASS result with either field Unknown cannot be marked complete.

Verification completed with Node `7 PASS`, Failure Matrix `17 PASS / 418 assertions`, P1 focused `28 PASS / 1 gated SKIP / 529 assertions`, and CE-P1 plus bounded regression `54 PASS / 1 gated SKIP / 757 assertions`. The full Laravel suite reproduced only the known out-of-scope CompanyNavigation stale intended-URL mismatch. Secret review found no real Credential, Authorization value, or private key. Repository `.env` and normal local DB hashes remained unchanged, and remaining Node processes were `0`.

Recommended Decision:

**Authorize exactly one additional Limited Deepgram Request only through a new explicit Human Gate under the unchanged one-request, USD 0.01, zero retry/reconnect/resend, DPAPI Credential, and `mip_opt_out=true` limits.**

This recommendation does not authorize communication. P1-I remains `INCONCLUSIVE_RUNTIME_INTEGRATION_FAILURE / STOPPED`, P1-J was `NOT STARTED` at that historical checkpoint (superseded by Section 18), and the current additional-request authorization remains `NOT AUTHORIZED`.

Detailed package:

- `docs/CompanyOS_S11CD_B_CE_P1_I_Runtime_Integration_Corrective_Decision_v001.md`
- `docs/evidence/CompanyOS_CE_P1_I_Runtime_Integration_Corrective_v001.json`

## 16｜P1-I Runtime-Corrected Limited Verification outcome

The explicitly approved one-Request Limited Deepgram Verification was invoked once and stopped without retry or resend. Sanitized Evidence records `RUNTIME_FAILURE`, `provider_open_timeout`, Provider acceptance false, audio `0 samples / 0 bytes / 0 seconds`, Provider events `0`, and close state Unknown. Missing close Evidence remains Unknown. Provider Failure is not established.

Pinned SDK source inspection proved that `WrappedListenV1Client.connect()` constructs its transport with `startClosed=true`. The prior Corrective incorrectly treated socket construction as initial connection start, so the Harness waited on a CLOSED socket. A Provider-free Corrective now starts the returned session exactly once after registering handlers, rejects duplicate start, and retains SDK retry/reconnect `0`.

Provider-free verification is Node `8 PASS`, P1 focused `28 PASS / 1 gated SKIP / 529 assertions`, and CE-P1 plus bounded regression `54 PASS / 1 gated SKIP / 757 assertions`. The corrected real Provider gate remained closed. Node/P1-I PHP processes and TLS temporary material are `0`; Repository `.env` and normal local DB hashes remain unchanged.

Recommended Decision:

**Authorize one final Limited Deepgram Request only through a new explicit Human Gate under the unchanged one-request, USD 0.01, zero retry/reconnect/resend, DPAPI Credential, and `mip_opt_out=true` limits.**

This recommendation is not authorization. P1-I remains `INCONCLUSIVE_RUNTIME_LIFECYCLE_FAILURE / STOPPED`, P1-J was `NOT STARTED` at that historical checkpoint (superseded by Section 18), and further Provider communication remains `NOT AUTHORIZED`.

Detailed package:

- `docs/CompanyOS_S11CD_B_CE_P1_I_Runtime_Corrected_Verification_Outcome_v001.md`
- `docs/evidence/CompanyOS_CE_P1_I_Deepgram_Runtime_Corrected_v001.json`
- `docs/evidence/CompanyOS_CE_P1_I_Runtime_Corrected_Verification_Outcome_v001.json`


## 17 | P1-I Final Limited Verification outcome

The explicitly approved final one-Request Deepgram Limited Verification was executed once and stopped without retry, reconnect, or resend. The valid sentinel-framed Evidence records a connection attempt before Provider acceptance, audio `0 samples / 0 bytes / 0 seconds`, Provider events `0`, and actual cost Unknown.

The outcome is `INCONCLUSIVE_SESSION_TRANSPORT_ERROR_EVIDENCE_GAP`, not PASS and not a Deepgram Provider failure. The pinned SDK rejected `waitForOpen()` with a nested ErrorEvent, but the Harness flattened the reason to `[object Object]` before sanitized persistence. Authentication rejection, request rejection, transient transport failure, and local runtime failure therefore remain Unknown.

A Provider-free Corrective now normalizes SDK ErrorEvent and nested error messages, redacts secrets, retains diagnostic errors, keeps pre-acceptance failures at the session layer, and rejects unknown objects without stringifying them. Verification is Node `9 PASS`, Failure Matrix plus P1 focused `24 PASS / 1 gated SKIP / 516 assertions`, bounded regression `33 PASS / 1 gated SKIP / 326 assertions`, and full Laravel `665 PASS / 1 known out-of-scope FAIL / 17 SKIP / 5,413 assertions`. The known failure remains the existing CompanyNavigation stale intended-URL mismatch.

Recommended Decision:

**Do not authorize another Provider Request at this time. Keep P1-I INCONCLUSIVE / Technical Verification Required and P1-J NOT STARTED at that historical checkpoint, then submit the accumulated Repository, prior successful E1 Deepgram connectivity, and current final Evidence for Human + ChatGPT disposition.**

Any later requirement for current-relay live proof needs a new explicit Human Gate. No Request is authorized by this recommendation.

Detailed package:

- `docs/CompanyOS_S11CD_B_CE_P1_I_Final_Limited_Verification_Outcome_v001.md`
- `docs/evidence/CompanyOS_CE_P1_I_Deepgram_Final_Limited_v001.json`
- `docs/evidence/CompanyOS_CE_P1_I_Final_Limited_Verification_Outcome_v001.json`
## 18 | P1-I Disposition and P1-J Human UX preparation

Human + ChatGPT integrated Review issued the following authoritative disposition:

- P1-I remains **INCONCLUSIVE / Technical Verification Required**;
- Provider Failure remains **NOT ESTABLISHED**;
- prior CE-PD08B E1 live Evidence is **Provider Capability Evidence**, not Current Relay PASS Evidence;
- no additional Synthetic Limited Request is planned;
- P1-J is **AUTHORIZED WITH KNOWN P1-I LIMITATION**.

Provider-free P1-J preparation added a loopback-only HTTPS/WSS Product relay, token-guarded Laravel bridge, server-held DPAPI Credential boundary, source-frame integrity validation, current Source/Send ledger integration, normalized Provider Event handling, Durable Final commit integration, and a browser stop handshake that waits for bounded finalization before closing the socket. The runtime uses an isolated copy of the normal local SQLite DB. The normal DB and repository `.env` remain unchanged.

Preparation verification:

- Node: `12 PASS`;
- P1 focused Laravel: `13 PASS / 1 gated SKIP / 134 assertions`;
- isolated migrations `2026_09_29_000001` and `000002`: Ran;
- loopback gateway health: HTTP `302` to authentication;
- secret scan: PASS;
- full Laravel regression: 667 PASS / 1 known out-of-scope FAIL / 17 SKIP / 5,431 assertions; the sole failure is the unchanged CompanyNavigation stale intended-URL mismatch;
- runtime-ready Provider request / audio send: `0 / 0`.

Safety bounds are retry/reconnect `0 / 0`, maximum 3 Provider Sessions, maximum 300 seconds total audio, USD 0.05 hard limit, `mip_opt_out=true`, loopback listener only, no Browser Credential, no raw audio persistence, no Public Port/Tunnel/Firewall change, no Production DB/Credential/Deploy/Public Push.

Human instructions and PASS/FAIL criteria are in `docs/CompanyOS_S11CD_B_CE_P1_J_Human_UX_Verification_Package_v001.md`. The next action is the Human microphone gate; failure before Partial must stop without an additional request.

## 19 | P1-J Minimum Human UX throughput corrective

The explicitly approved Human Start was used once in Microsoft Edge. Human Evidence established microphone speech and same-stream Waveform movement. Runtime Evidence established same-origin WSS, Provider acceptance, `mip_opt_out=true`, reconnect `0`, and 144,000 samples / 9.0 seconds sent. Partial was not displayed, Durable Final was not reached, and the session later ended with `normal=false`; no retry, reconnect, resend, or second Start followed.

Provider Failure is not established. Sanitized local logs and source inspection identified a Current Relay throughput defect: every 100 ms frame performed two serial Laravel HTTP round trips, while Provider events waited for the Browser `messageChain` containing future queued frames. Only 9 seconds of audio progressed during approximately 85 seconds of wall time, and late event submissions occurred after hard abort.

The Provider-free Corrective keeps every 100 ms Source Range but transports ten ranges per atomic request. Laravel independently validates transient PCM byte length/SHA-256 and Provider stream/generation scope; no raw audio is persisted. Send Range persistence is batched atomically, the memory queue is bounded to 30 frames, hard abort discards unsent frames, and Provider events now wait only for the sent-Evidence watermark captured at event arrival.

Verification is Node `17 PASS`, CE-P1 focused `11 PASS / 1 gated live SKIP / 142 assertions`, and full Laravel `670 PASS / 17 SKIP / 1 known out-of-scope FAIL / 5,458 assertions`. The known CompanyNavigation intended-URL mismatch is unchanged. Repository `.env` and the normal local DB remain unchanged; the loopback runtime is stopped. No Provider communication or audio send occurred during the Corrective.

Disposition remains P1-I `INCONCLUSIVE / Technical Verification Required` and P1-J `AUTHORIZED WITH KNOWN P1-I LIMITATION / Minimum Gate INCONCLUSIVE`. Recommended next action is one newly authorized P1-J Human Start through an explicit Human Gate, using the corrected Actual Product Path and zero retry/reconnect/resend. This recommendation is not authorization.

Detailed package:

- `docs/CompanyOS_S11CD_B_CE_P1_J_Minimum_Human_UX_Throughput_Corrective_v001.md`
- `docs/evidence/CompanyOS_CE_P1_J_Minimum_Human_UX_Throughput_Corrective_v001.json`

## 20 | P1-J empty-final event corrective

The newly authorized P1-J Human Start was consumed once without retry, reconnect, resend, or a second Start. Human Evidence established microphone speech and same-stream Waveform movement. Runtime Evidence established same-origin WSS, Provider acceptance, `mip_opt_out=true`, and 32,000 samples / 2.0 seconds sent. Partial was not displayed, so the Minimum Gate did not pass.

The Relay truthfully stopped with WSS 1011 and `bridge_event_rejected_500`. Sanitized Laravel Evidence identified the exact local failure: the first Deepgram `Results` event was `is_final=true` with a verified `0..11840` Source Range but without durable Transcript content. The Adapter incorrectly classified every Provider final as a Durable Final, while the provider-neutral envelope correctly rejected a contentless Durable Final. This is a Current Relay normalization defect; Deepgram Provider Failure is not established.

The Provider-free Corrective requires non-empty trimmed content for a Durable Final. A contentless Provider final is now Metadata, cannot create a Transcript, and advances receipt ordering without inventing speech. A subsequent non-empty final continues through the existing verified lineage. Bridge errors also carry an immutable action-specific failure stage, fixing the concurrent stage race that reported `bridge_sent_batch` instead of `bridge_event`.

Verification is Node `18 PASS`, Realtime focused `16 PASS / 1 gated live SKIP / 174 assertions`, and full Laravel `671 PASS / 17 SKIP / 1 known out-of-scope FAIL / 5,479 assertions`. The sole failure is the unchanged CompanyNavigation stale intended-URL mismatch. Repository `.env` and the normal local DB remain unchanged, real Credential hits are 0, and loopback listeners are stopped. No Provider communication or audio send occurred during the Corrective.

P1-I remains `INCONCLUSIVE / Technical Verification Required`. P1-J remains `AUTHORIZED WITH KNOWN P1-I LIMITATION / MINIMUM GATE NOT PASSED`. Recommended next action is one newly authorized P1-J Human Start through an explicit Human + ChatGPT Gate using the corrected Runtime and unchanged zero retry/reconnect/resend conditions. This recommendation is not authorization.

Detailed package:

- `docs/CompanyOS_S11CD_B_CE_P1_J_Empty_Final_Corrective_v001.md`
- `docs/evidence/CompanyOS_CE_P1_J_Empty_Final_Corrective_v001.json`

## 21 | P1-J relay backpressure corrective

The explicitly approved First E2E Human Start was consumed once without retry, reconnect, resend, or a second Start. The Product Path established Provider acceptance, `mip_opt_out=true`, 48,000 samples / 3.0 seconds sent, correct Metadata handling for the contentless final, and a real Partial at receive order 2. Human-visible Partial was not separately confirmed before the truthful WSS 1011 safe stop, and no Final, Durable Final, or Normal End was reached.

The failure reason was `relay_frame_backlog_failed_closed`. Thirty Source Ranges and thirty Provider Send Ranges were persisted; Durable Final Commits and realtime Transcript Segments remained zero. Provider Failure is not established.

Root Cause was Current Relay local bridge backpressure. The serial Laravel development server required one `frames` and one `sent-batch` request per one-second batch, while Provider Events used the same queue. This consumed the available realtime latency budget and exceeded the bounded 30-frame memory queue.

The Provider-free Corrective piggybacks the previous batch's send receipt persistence onto the next `frames` call, reducing steady-state internal requests from two per batch to one while preserving every 100 ms Source Range, PCM length/SHA-256 verification, atomic ten-frame acceptance, send ordinals, and event sent-Evidence watermarks. The final already-sent batch is settled at Normal End or safe abort. The queue limit was not relaxed, and overflow is now attributed to `frame_queue_admission`.

Verification is Relay Node `20 PASS`, CE-P1 focused `11 PASS / 1 gated live SKIP / 155 assertions`, Pint PASS, and full Laravel `671 PASS / 17 SKIP / 1 known out-of-scope FAIL / 5,488 assertions`. The known CompanyNavigation intended-URL mismatch is unchanged. A repository-wide Node invocation also reproduced one unrelated pre-existing Blade-template parsing failure; the Relay suite itself passed. Repository `.env` and the normal local DB hashes remain unchanged, and loopback listeners are stopped. No Provider communication or audio send occurred during the Corrective.

P1-I remains `INCONCLUSIVE / Technical Verification Required`. P1-J remains `AUTHORIZED WITH KNOWN P1-I LIMITATION / FIRST E2E HUMAN PASS NOT ESTABLISHED`.

Recommended next action is one newly authorized P1-J Human Start through an explicit Human + ChatGPT Gate using the corrected Runtime. No Synthetic Provider Request is recommended. The single Session should first confirm visible Waveform and visible Partial, then continue through Final, Durable Final, and Normal End if the minimum path passes. This recommendation is not authorization.

Detailed package:

- `docs/CompanyOS_S11CD_B_CE_P1_J_Backpressure_Corrective_v001.md`
- `docs/evidence/CompanyOS_CE_P1_J_Backpressure_Corrective_v001.json`

## 22 | P1-J backpressure / throughput stress verification

The conditionally required Provider-free Stress Verification initially rejected the uncoalesced event flow: a ten-second 100 ms input required 15.337 seconds of post-capture drain when all 250 ms Partial events used separate 510 ms serial Laravel requests.

The Provider-free Corrective forwards the first non-empty Partial immediately and latest-value coalesces later ephemeral Partials to a two-second interval. Provider Final, Metadata, Close, rejected events, and Durable lineage are never coalesced. Pending Partial data remains memory-only, and the queue limit remains 30.

The final drift-corrected realtime Stress ran for 120.012 seconds at a 100.004 ms mean cadence, admitted 1,200 realtime frames plus a bounded 20-frame burst, and completed drain/burst in 3.135 seconds. Maximum queue depth was 20/30. All 1,220 Source Ranges, Provider Send Ranges, send ordinals, and SHA-256 checks were continuous. Partial watermarks progressed monotonically, and the fail-closed boundary remained exactly frame 31. Provider communication/audio send and Credential load were `0 / 0 / 0`.

Regression is Relay Node `22 PASS`, CE-P1 focused `11 PASS / 1 gated live SKIP / 155 assertions`, and P1-J loopback proxy `1 PASS / 3 assertions`. The prior full Laravel Evidence at `7b83489` remains applicable because no Laravel code changed in this Stress delta.

Stress disposition is `PASS / READY FOR THE APPROVED SINGLE HUMAN START`. The next single Session must confirm Waveform -> visible Partial -> Final -> Durable Final -> Normal End. First E2E Human PASS, if established, is not Stable PASS and must be followed by a Repeatability Verification Plan rather than Formal Close.

Detailed package:

- `docs/CompanyOS_S11CD_B_CE_P1_J_Backpressure_Stress_Verification_v001.md`
- `docs/evidence/CompanyOS_CE_P1_J_Backpressure_Stress_Verification_v001.json`

## 23 | P1-J mixed upstream backpressure corrective

The conditionally approved Human Start was consumed once. The Human read the approved Japanese sentence and the screen displayed numbered Japanese recognition results before truthfully stopping with WSS 1011 and `relay_frame_backlog_failed_closed`. Together with four sanitized `durable_final` records, this establishes Human-visible Japanese Durable Final output. The post-stop image does not separately prove that an ephemeral Partial was visible while speaking; Runtime Partial processing is technically established. Runtime Evidence also establishes Provider acceptance, `mip_opt_out=true`, 368,000 samples / 23.0 seconds sent, five forwarded non-empty Partials, seven Metadata events, and complete `frame_queue_admission` failure Evidence. Normal End was not reached, so First E2E Human PASS is not established. Provider Failure is not established.

The prior Stress omitted Final/Metadata, lease-refresh, and snapshot traffic sharing the single-process Laravel development server. Live access logs showed concurrent requests turning the PHP socket queue into an accidental scheduler, starving audio lineage long enough to cross the unchanged 30-frame bound.

The Provider-free Corrective serializes loopback upstream access through a priority lane: audio lineage/lifecycle/lease control are critical, Provider Events and normal page work are normal, and snapshot polling is background. Request bodies remain streamed. The ten-frame atomic DB path removes per-frame lock/max N+1 work while retaining frame identity, SHA-256, continuity, authorization/Consent, ordinal, idempotency, and rollback checks. A query-budget regression prevents N+1 recurrence.

The mixed-load Stress ran 120.008 seconds at 100.002 ms mean cadence, admitted 1,200 realtime frames plus a 20-frame burst, and held the queue at 20/30. All 1,220 Source/Send Ranges and hashes were continuous. It completed 53 Provider-event-equivalent operations, 29 lease refreshes, and 23 snapshots; all 287 scheduler requests completed, final scheduler depth was zero, drain/burst was 2.697 seconds, and fail-closed remained frame 31. Provider communication/audio send/Credential load during Corrective was `0 / 0 / 0`.

Regression is Relay Node `24 PASS`, CE-P1 focused plus proxy `12 PASS / 1 gated live SKIP / 160 assertions`, Pint PASS, and full Laravel `671 PASS / 17 SKIP / 1 known out-of-scope FAIL / 5,490 assertions`. The known CompanyNavigation failure is unchanged; new CE-P1 regressions are zero. Repository `.env` and normal local DB hashes remain unchanged. Runtime is stopped.

P1-I remains `INCONCLUSIVE / Technical Verification Required`. P1-J remains `AUTHORIZED WITH KNOWN P1-I LIMITATION / FIRST E2E HUMAN PASS NOT ESTABLISHED`.

Recommended next action is one newly authorized P1-J Human Start using the corrected runtime, with no Synthetic Provider Request. The single stream should confirm Waveform and visible Partial, continue through Final and Durable Final, and use Normal Stop. This recommendation is not authorization; a new Human + ChatGPT Gate is required.

Detailed package:

- `docs/CompanyOS_S11CD_B_CE_P1_J_Mixed_Upstream_Backpressure_Corrective_v001.md`
- `docs/evidence/CompanyOS_CE_P1_J_Mixed_Upstream_Backpressure_Corrective_v001.json`

## 24 | P1-J First E2E Human PASS outcome

The explicitly approved single Human Start completed on the corrected Current Company OS Relay Path. Human Evidence established a moving same-stream Waveform, six visible Japanese committed items, and the truthful message `Stopped normally. Final evidence is server-authoritative.` No Safe Stop, WSS 1011, unavailable, rejected, or backlog failure was shown.

Sanitized Runtime Evidence establishes one Provider acceptance with `mip_opt_out=true`, 569,600 samples / 35.6 seconds sent, 23 Provider Partial events, six Durable Final events, thirteen Metadata events, scheduler maximum depth 6 with final depth 0, and `normal=true` close. Retry / reconnect / resend remained `0 / 0 / 0`.

The isolated DB contains 356 continuous accepted Source Ranges and 356 continuous sent Provider ranges over samples `0..569600`, six verified Final receipts, six committed Durable Final records, six commit items, and six realtime Transcript Segments. Provider Session and Relay Lease closed with `normal_stop`; Capture stopped without interruption or safe error. No fictitious AudioWindow was created.

Security and non-change checks found no raw audio, raw Provider payload, Credential, Authorization Header, or Transcript body in repository Evidence. Repository `.env` and the normal local DB hashes are unchanged. The loopback runtime is stopped and ports 8443 / 8765 have zero listeners. Public Push / Production DB / Production Credential / Deploy remain `0 / 0 / 0 / 0`.

Disposition:

**P1-J = FIRST E2E HUMAN PASS ESTABLISHED / STABILITY VERIFICATION REQUIRED**

**P1-J Formal PASS / Formal Close = NOT ESTABLISHED**

**P1-I = INCONCLUSIVE / Technical Verification Required**

Recommended Repeatability is two additional independent core Sessions with a `2 / 2` PASS requirement, followed only then by one scoped lifecycle Session covering Pause/Resume, Background/Foreground, explicit CO consultation, bounded finalization grace, and End. No additional Provider communication is authorized by this Evidence.

Detailed package:

- `docs/CompanyOS_S11CD_B_CE_P1_J_First_E2E_Human_PASS_Outcome_v001.md`
- `docs/evidence/CompanyOS_CE_P1_J_First_E2E_Human_PASS_Outcome_v001.json`
