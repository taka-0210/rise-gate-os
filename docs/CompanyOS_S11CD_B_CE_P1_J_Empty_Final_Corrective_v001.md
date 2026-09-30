# Company OS | CE-P1 P1-J Empty Final Event Corrective v001

Recorded: 2026-09-30 09:51 JST

Base HEAD: `cde6d68`

Scope: P1-J newly authorized Human Start, failure analysis, and Provider-free Corrective

## 1 | Disposition

- P1-I: **INCONCLUSIVE / Technical Verification Required**
- P1-J: **AUTHORIZED WITH KNOWN P1-I LIMITATION / MINIMUM GATE NOT PASSED**
- Minimum path: Mic -> Waveform -> Provider acceptance -> Audio Send -> Partial
- Established: Mic, Waveform, same-origin WSS, Provider acceptance, Audio Send
- Not established: Partial, Final, Durable Final, Full Human UX
- Deepgram Provider Failure: **NOT ESTABLISHED**
- Current Relay live end-to-end PASS: **NOT ESTABLISHED**
- Human Start consumed: `1 / 1`
- retry / reconnect / resend: `0 / 0 / 0`

No second Start or resend followed the failure.

## 2 | Sanitized Human and Runtime Evidence

The Human spoke into Microsoft Edge and observed the same-stream Waveform moving. The screen then entered the truthful safe-stop state:

- WSS close: `1011`
- Capture: stopped
- Relay: disconnected
- Provider: unavailable
- Transcript: `rejected: bridge_event_rejected_500`

Sanitized runtime and isolated-database Evidence establish:

| Item | Result |
|---|---|
| Provider sessions | 1 |
| Provider accepted | true |
| `mip_opt_out=true` | true |
| reconnect attempts | 0 |
| samples / audio sent | 32,000 / 2.0 seconds |
| 100 ms Source / Provider Send Ranges | 20 / 20 |
| maximum sent sample | 32,000 |
| Provider Event Receipts | 0 |
| Durable Final Commits / realtime Segments | 0 / 0 |
| Provider session state | failed |
| sanitized safe reason | `bridge_event_rejected_500` |
| actual billed cost | Unknown |
| conservative audio estimate | USD 0.00032334 |

Raw Credential, Authorization Header, raw Provider payload, raw realtime audio, and Transcript content were not persisted in this Evidence.

## 3 | Root Cause

The failure occurred after Provider acceptance and audio send. Laravel received the first sanitized Deepgram `Results` event with `is_final=true`, a verified `0..11840` source range, and no durable Transcript content.

`DeepgramStreamingAdapter` classified every `is_final=true` Results event as a Durable Final. `ProviderStreamingEventEnvelope` correctly requires a Durable Final to have content and a content hash, so it rejected this empty final with `Invalid provider-neutral streaming envelope`. The uncaught local normalization error became HTTP 500, and the Relay failed closed with WSS 1011.

This is a Current Relay event-normalization defect, not evidence of a Deepgram Provider failure.

The Evidence envelope also reported `failure_stage=bridge_sent_batch`, although the failing request was the `event` bridge action. Parallel frame and event work shared one mutable stage value, making the reported stage structurally present but semantically inaccurate.

## 4 | Provider-free Corrective

- A Deepgram Results event is a Durable Final only when its trimmed Transcript content is non-empty.
- An `is_final=true` Results event without durable content is normalized as Metadata.
- Empty final Metadata carries no Transcript content or content hash and cannot create a Durable Final or canonical Transcript.
- The Metadata receipt advances Provider receive-order Evidence without inventing speech.
- A later non-empty final remains eligible for the existing verified Source Range -> Receipt -> Durable Final lineage.
- Every internal bridge error is tagged with its immutable action stage (`bridge_event`, `bridge_sent_batch`, and so on) before concurrent work can change the shared runtime stage.

The Provider profile, Japanese settings, diarization, audio format, 10-frame batch, sent-Evidence watermark, Credential, retry/reconnect policy, and Product Contract were not changed.

Provider communication / audio send during the Corrective: `0 / 0`.

## 5 | Automated Verification

- PHP syntax: PASS
- Laravel Pint: PASS
- Node Relay: `18 PASS`
- Realtime focused: `16 PASS / 1 gated live SKIP / 174 assertions`
- missing / null / whitespace final content -> Metadata: PASS
- empty final -> Provider Event Receipt, Durable Final 0: PASS
- following non-empty final -> Durable Final commit: PASS
- action-specific concurrent failure stage: PASS
- full Laravel: `671 PASS / 17 SKIP / 1 known out-of-scope FAIL / 5,479 assertions`
- known out-of-scope failure: `CompanyNavigationTest::regular login ignores a stale forbidden intended url`
- new regression: 0
- real Credential hits in changed files: 0
- repository `.env` changed: false
- normal local DB changed: false
- loopback listeners after work: 0

## 6 | State and Recommended Next Action

P1-I remains **INCONCLUSIVE / Technical Verification Required**. This attempt does not retroactively promote P1-I.

P1-J remains **AUTHORIZED WITH KNOWN P1-I LIMITATION / MINIMUM GATE NOT PASSED** because Partial was not observed.

Recommended decision:

**Authorize one new P1-J Minimum Human Start using the corrected Runtime, through a new explicit Human + ChatGPT Gate.**

The next run should keep the same minimum path and stop conditions:

- one Human Start maximum;
- retry / automatic reconnect / manual reconnect / resend: `0 / 0 / 0 / 0`;
- `mip_opt_out=true`;
- existing DPAPI Evaluation Credential only;
- no Synthetic Provider Request;
- stop without another Start if Partial is not shown;
- if Partial is shown, continue the same Session / Stream into the authorized Full P1-J UX checks.

This recommendation is not authorization. The loopback Runtime is stopped. Public Push, Production DB, Production Credential, Deploy, Azure, and other Providers remain untouched and unauthorized.
