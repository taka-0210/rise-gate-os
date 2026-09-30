# Company OS | CE-P1 P1-J Mixed Upstream Backpressure Corrective v001

Recorded: 2026-09-30 12:34 JST

Base HEAD: `2cc7340`

Scope: approved single P1-J Human Start outcome, Provider-free Corrective, mixed-load Stress, and Recommended Decision

## 1 | Disposition

- P1-I: **INCONCLUSIVE / Technical Verification Required**
- P1-J: **AUTHORIZED WITH KNOWN P1-I LIMITATION / FIRST E2E HUMAN PASS NOT ESTABLISHED**
- Human-visible Japanese transcription / Durable Final list: **ESTABLISHED**
- Human-visible ephemeral Partial: **not separately confirmed**
- Final / Durable Final integration: **technically and visually established in the live attempt**
- Normal End: **not established**
- Deepgram Provider Failure: **NOT ESTABLISHED**
- approved Human Start: consumed `1 / 1`
- retry / automatic reconnect / manual reconnect / resend: `0 / 0 / 0 / 0`

The attempt ended through truthful WSS 1011 fail-closed handling. It is not a First E2E Human PASS because Normal End was not reached. No second Provider request or audio resend followed the failure. A later Browser WSS attempt was rejected locally by `human_gate_halted_after_failure` before Provider connection or audio send.

## 2 | Human and Sanitized Runtime Evidence

The Human read:

> 本日はリアルタイム音声認識の確認を行います。波形と日本語の文字が表示されることを確認します。

The screen displayed numbered Japanese recognition results. Together with four sanitized `durable_final` event records, this establishes Human-visible Japanese Durable Final output. The image was captured after safe stop, so it does not separately prove that an ephemeral Partial was visible while speaking. Runtime Partial processing is technically established. The screen truthfully showed WSS 1011, Capture `stopped`, Relay `disconnected`, Provider `unavailable`, and Transcript `rejected: relay_frame_backlog_failed_closed`.

| Item | Result |
|---|---|
| Provider accepted / MIP | true / `mip_opt_out=true` |
| samples / audio sent | 368,000 / 23.0 seconds |
| Partial flow | received 9 / forwarded 5 / coalesced 6 |
| durable final / metadata events | 4 / 7 |
| failure | `frame_queue_admission` / `relay_frame_backlog_failed_closed` |
| Evidence completeness | complete |
| conservative audio estimate | USD 0.003718 |
| actual billed cost | Unknown |

Raw Credential, Authorization Header, raw Provider payload, raw realtime audio, and Transcript content were not persisted in repository Evidence.

## 3 | Root Cause

The preceding Stress modeled audio ledger and coalesced Partial requests but omitted the complete local workload: Final/Metadata, four-second lease refresh, five-second snapshot polling, and ordinary page requests shared the same single-process Laravel development server.

Access logs repeatedly showed about 510 ms queue residence and occasional one-to-two-second waits across otherwise light endpoints. The PHP socket queue became an accidental scheduler. Audio lineage lost deterministic priority, Browser capture continued admitting 100 ms frames, and the Product queue crossed the unchanged 30-frame boundary.

This is a Current Relay local runtime scheduling and batch-query problem, not a Deepgram rejection, authentication failure, or Provider capability failure.

## 4 | Provider-free Corrective

Deterministic upstream scheduling:

- all loopback Relay-to-Laravel and Browser-proxy requests use one single-flight scheduler;
- audio lineage, send settlement, Provider lifecycle, and lease refresh are `critical`;
- Provider Event and ordinary page work are `normal`; snapshot polling is `background`;
- queued critical work overtakes queued normal/background work;
- request bodies remain streamed with Node backpressure;
- scheduler counts and queue depth are added to sanitized Evidence.

Batch DB query reduction:

- the approved ten-frame atomic batch remains ten frames;
- Source identity, stream, lease, previous-end, send existence, next ordinal, and next offset are resolved per batch instead of per frame;
- ordered Source Range fetch replaces ten independent queries;
- idempotency, SHA-256, continuity, authorization/Consent fingerprints, ordinal, and rollback remain fail closed;
- a query-budget test prevents N+1 regression.

The queue limit remains 30 and Partial interval remains two seconds. Provider profile, Japanese, diarization, PCM, Credential, MIP, retry/reconnect, and Durable Final lineage were unchanged. Provider communication / audio send during Corrective: `0 / 0`.

## 5 | Mixed-load Stress Evidence

The improved Provider-free Stress mixes 100 ms PCM, Partial, Final/Metadata-equivalent work, four-second lease refresh, five-second snapshot polling, and a final 20-frame burst.

| Item | Result |
|---|---|
| status / capture / cadence | PASS / 120.008 s / 100.002 ms |
| realtime / total frames | 1,200 / 1,220 |
| maximum Product queue | 20 / 30 |
| drain and burst | 2.697 seconds |
| Source / Send / SHA checks | 1,220 / 1,220 / 1,220 |
| ordinal / Source / watermark | PASS / PASS / PASS |
| raw / forwarded / coalesced Partial | 454 / 58 / 397 |
| Provider-event-equivalent | 53 / 53 complete |
| lease refresh / snapshot | 29 / 29; 23 / 23 complete |
| scheduler critical / normal / background | 153 / 111 / 23, all complete |
| scheduler maximum / final depth | 3 / 0 |
| fail-closed threshold | exactly frame 31 |

No limit relaxation, dropped Source Range, invented receipt, ordinal gap, SHA bypass, or unverified watermark was used.

## 6 | Regression and Safety

- Node syntax: PASS; Relay Node: `24 PASS`;
- CE-P1 focused + proxy: `12 PASS / 1 gated live SKIP / 160 assertions`;
- ten-frame query budget: PASS (`<= 40`); Pint: PASS;
- full Laravel: `671 PASS / 17 SKIP / 1 known out-of-scope FAIL / 5,490 assertions`;
- known failure: `CompanyNavigationTest::regular login ignores a stale forbidden intended url`;
- new CE-P1 regression: 0; changed-file Secret scan: 0;
- `.env` SHA-256 unchanged: `DC2A0E2A2402229EFA555609FFCEB6A9721F9A2CC1A452D5B7DEEEF3BC6CB0ED`;
- normal DB SHA-256 unchanged: `CC0F4A7B176B3284D1768D73B689E9DE87CDB68CC4E89FB5F69471F27995AF4A`;
- loopback runtime stopped; Public Push / Production DB / Credential / Deploy: 0.

## 7 | Recommended Next Action

**Recommend approving one new P1-J Human Start using the corrected runtime. Do not add a Synthetic Provider Request.**

The single Session should confirm Waveform and visible Japanese Partial, continue through Final and Durable Final, then use `Stop normally` and confirm Normal End. Stop without another Start on FAIL/INCONCLUSIVE. Conditions remain retry / reconnect / resend `0 / 0 / 0`, existing DPAPI Evaluation Credential, `mip_opt_out=true`, loopback only, and no raw audio persistence.

This recommendation is not authorization. A new Human Start requires a new Human + ChatGPT Gate. First E2E Human PASS remains distinct from Stable PASS and requires the planned Repeatability Verification rather than Formal Close.
