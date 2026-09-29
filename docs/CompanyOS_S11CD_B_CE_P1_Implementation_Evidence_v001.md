# Company OS｜CE-P1 Realtime Corrective Implementation Evidence v001

- Evidence date: 2026-09-30 JST
- Branch: ce-p1-realtime-corrective
- Baseline / prior local commits: 9890dc0 / ae95731 / ce49baa / 84bf025
- Public push / deploy: 0 / 0
- CE-P1 Deepgram executions: initial 1 + corrective 1 + Hardened approved 1; latest run recorded Provider acceptance false and audio send 0
- Production Credential use: 0
- Production / normal local / shared DB migration: 0
- Realtime feature flags: default OFF / audio send OFF

This update records the approved implementation through P1-H, both earlier P1-I executions, Evidence Harness Hardening, and the single approved Hardened Verification. P1-I remains inconclusive because the latest run stopped before Provider acceptance and its exact sanitized failure reason was not persisted. Human UX is not started.

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
| P1-I Limited Provider Verification | INCONCLUSIVE_RUNTIME_INTEGRATION_FAILURE / STOPPED | Hardened IPC succeeded, but the latest single authorized run stopped before Provider acceptance/audio send. SDK lifecycle mismatch is the strongest candidate; exact safe reason remains Unknown. No retry or resend followed. See §14. |
| P1-J Human UX / device matrix | NOT STARTED | P1-I did not PASS; no human device or microphone operation started. |

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
| Human UX | NOT STARTED |
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

## 11｜Current stop and next gate

- P1-A through P1-H: repository/automated state retained.
- P1-I: stopped after the corrective execution also ended inconclusive.
- P1-J Human UX: NOT STARTED.
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

**P1-J = NOT STARTED**

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

No request is authorized by this recommendation. Until a new approval is supplied, P1-I remains `INCONCLUSIVE_EVIDENCE_FAILURE`, P1-J remains `NOT STARTED`, and Provider communication/audio send remain disabled.

## 14｜P1-I Hardened Limited Provider Verification outcome

The Recommended Decision was explicitly approved and the Hardened Limited Verification was invoked once. The prior stdout parsing defect did not recur: the child produced a valid `sentinel-json-v1` frame and the parent persisted sanitized process, request, Provider, audio, event, and close state.

The run stopped before Provider acceptance and before audio send. Evidence records one Provider connection attempt, `mip_opt_out=true`, reconnectAttempts `0`, audio `0 samples / 0 bytes / 0 seconds`, zero Provider events, child exit code `1`, and no raw output or Provider payload persistence. No retry, reconnect, or resend followed.

Static inspection found an SDK lifecycle mismatch: Deepgram SDK v5 begins connecting when its `ReconnectingWebSocket` is constructed, while the current Relay additionally calls `provider.connect()`, which the SDK implements as `reconnect()`. This is the strongest Runtime integration candidate. However, the parent failure writer omitted the child sanitized `safe_reason` and error-layer classification, so the execution-specific cause remains Unknown and Provider failure is not established.

Disposition:

**P1-I = INCONCLUSIVE_RUNTIME_INTEGRATION_FAILURE / STOPPED**

**P1-J = NOT STARTED**

**Additional Provider request = NOT AUTHORIZED**

Recommended Next Action is a Provider-free corrective for the SDK lifecycle and failure Evidence completeness, followed by synthetic/regression verification. Only after that PASS should a new maximum-one-request Human Gate be considered.

Detailed outcome:

- `docs/CompanyOS_S11CD_B_CE_P1_I_Hardened_Verification_Outcome_v001.md`
- `docs/evidence/CompanyOS_CE_P1_I_Deepgram_Hardened_v001.json`
- `docs/evidence/CompanyOS_CE_P1_I_Hardened_Verification_Outcome_v001.json`
