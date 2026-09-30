# Company OS | CE-P1 P1-J Relay Backpressure Corrective v001

Recorded: 2026-09-30 10:50 JST

Base HEAD: `9d4f1c7`

Scope: approved P1-J First E2E Human Start, failure analysis, and Provider-free Corrective

## 1 | Disposition

- P1-I: **INCONCLUSIVE / Technical Verification Required**
- P1-J: **AUTHORIZED WITH KNOWN P1-I LIMITATION / FIRST E2E HUMAN PASS NOT ESTABLISHED**
- Minimum path: Mic -> Waveform -> same-origin WSS -> Relay -> Provider acceptance -> Audio Send -> Partial
- Technically established: same-origin WSS, Provider acceptance, Audio Send, Provider Partial normalization
- Not established: Human-visible Partial, Final, Durable Final, Normal End, First E2E Human PASS
- Deepgram Provider Failure: **NOT ESTABLISHED**
- Human Start consumed: `1 / 1`
- Provider request: `1`
- retry / reconnect / resend: `0 / 0 / 0`

No second Start, retry, reconnect, or resend followed the failure.

## 2 | Sanitized Human and Runtime Evidence

The Human reported that the session stopped again. The Microsoft Edge screen showed the truthful safe-stop state:

- WSS close: `1011`
- Capture: stopped
- Relay: disconnected
- Provider: unavailable
- Transcript: `rejected: relay_frame_backlog_failed_closed`

The current attempt does not include a separate Human confirmation that Partial text was visibly readable before the safe stop. The runtime did normalize a real Partial and sent the browser message before recording the event, but this is technical integration Evidence, not Human-visible Partial Evidence.

| Item | Result |
|---|---|
| Provider sessions / requests | 1 / 1 |
| Provider accepted | true |
| `mip_opt_out=true` | true |
| retry / reconnect / resend | 0 / 0 / 0 |
| samples / audio sent | 48,000 / 3.0 seconds |
| 100 ms Source / Provider Send Ranges | 30 / 30 |
| Provider events | Metadata receive order 1; Partial receive order 2 |
| Provider Event Receipts | 1 Metadata receipt; Partial remains ephemeral |
| Durable Final Commits / realtime Segments | 0 / 0 |
| Provider session state | failed |
| sanitized safe reason | `relay_frame_backlog_failed_closed` |
| actual billed cost | Unknown |
| conservative audio estimate | USD 0.000485 |

Raw Credential, Authorization Header, raw Provider payload, raw realtime audio, and Transcript content were not persisted in this Evidence.

The first Provider event was a contentless final and was correctly normalized to Metadata. This confirms the preceding Empty Final Corrective. The next event was normalized as Partial. No Final, Durable Final, or Normal End Evidence exists.

## 3 | Root Cause

The local Laravel development server serializes the Relay's internal bridge requests. Sanitized timing showed approximately:

- `open`: 515 ms
- `opened`: 513 ms
- `frames`: 0-510 ms
- `sent-batch`: 506-510 ms
- Provider `event`: up to approximately 1 second

The prior 10-frame batching Corrective reduced frame acceptance to one request per second, but each batch still required a second serial `sent-batch` request. Provider Event persistence shared the same single-server queue. The Relay therefore had no latency headroom: Browser capture continued at realtime speed while internal persistence consumed roughly the same or greater wall time. The bounded queue exceeded 30 unsent frames and correctly failed closed.

This is a Current Relay local backpressure defect. It is not a Deepgram rejection or a Provider capability failure.

The original failure envelope reported `failure_stage=bridge_sent_batch` because the global mutable stage reflected concurrent bridge work when the queue guard fired. Source inspection and the exact safe reason establish the actual stage as `frame_queue_admission`.

## 4 | Provider-free Corrective

- The next `frames` request now carries the previous batch's Source Range IDs.
- Laravel first persists the previous batch's Provider Send Ranges, then validates and atomically accepts the current ten 100 ms frames.
- The response returns both previous send receipts and current accepted Source Ranges.
- Steady-state internal traffic is reduced from two Laravel requests per one-second batch to one.
- The final already-sent batch is settled through the existing dedicated `sent-batch` endpoint during Normal End or safe abort.
- Provider Event processing still waits for the exact sent-Evidence watermark captured at event arrival.
- PCM byte length, SHA-256, stream, generation, sequence, Source Range, and send ordinal checks remain fail closed.
- The 30-frame memory bound was not relaxed.
- Hard abort now waits for in-flight batch work before settling an already-sent final batch, avoiding duplicate concurrent send-receipt writes.
- Queue overflow is now tagged deterministically as `frame_queue_admission`.

The Provider profile, Japanese settings, diarization, audio format, Credential, retry/reconnect policy, raw-audio policy, and provider-neutral Contract were not changed.

Provider communication / audio send during this Corrective: `0 / 0`.

## 5 | Automated Verification

- PHP syntax: PASS
- Laravel Pint: PASS
- Relay Node tests: `20 PASS`
- CE-P1 focused: `11 PASS / 1 gated live SKIP / 155 assertions`
- piggyback previous-send Evidence with ten current frames: PASS
- each 100 ms Source Range and send ordinal preserved: PASS
- incomplete or non-sent receipt fails closed: PASS
- queue failure stage is `frame_queue_admission`: PASS
- full Laravel: `671 PASS / 17 SKIP / 1 known out-of-scope FAIL / 5,488 assertions`
- known out-of-scope failure: `CompanyNavigationTest::regular login ignores a stale forbidden intended url`
- repository-wide Node invocation: `30 PASS / 1 unrelated pre-existing Blade-template parsing FAIL`
- new CE-P1 regression: 0
- real Credential hits in changed files: 0
- repository `.env` SHA-256 unchanged: true
- normal local DB SHA-256 unchanged: true
- loopback listeners after work: 0

## 6 | State and Recommended Next Action

P1-I remains **INCONCLUSIVE / Technical Verification Required**. This attempt does not retroactively promote P1-I.

P1-J remains **AUTHORIZED WITH KNOWN P1-I LIMITATION / FIRST E2E HUMAN PASS NOT ESTABLISHED**. The runtime Partial proves progress through the minimum technical path, but no Final, Durable Final, or Normal End exists and Human-visible Partial was not separately confirmed.

Recommended decision:

**Authorize one new P1-J Human Start through an explicit Human + ChatGPT Gate, using the corrected Runtime, to obtain First E2E Human PASS.**

The next run should:

- use one Human Start maximum;
- keep retry / automatic reconnect / manual reconnect / resend at `0 / 0 / 0 / 0`;
- use the existing DPAPI Evaluation Credential and `mip_opt_out=true`;
- make no Synthetic Provider Request;
- confirm visible Waveform and visible Partial first;
- if the minimum path passes, continue the same Session / Stream through Final, Durable Final, and Normal End;
- stop without another Start on any FAIL or INCONCLUSIVE result.

This recommendation is not authorization. The loopback Runtime is stopped. Public Push, Production DB, Production Credential, Deploy, Azure, and other Providers remain untouched and unauthorized.
