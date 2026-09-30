# Company OS | CE-P1 P1-J Backpressure / Throughput Stress Verification v001

Recorded: 2026-09-30 11:14 JST

Base HEAD: `7b83489`

Scope: Provider-free Stress Verification before the conditionally approved P1-J Human Start

## 1 | Decision

**PASS / READY FOR THE APPROVED SINGLE HUMAN START**

- Provider communication / audio send: `0 / 0`
- Credential load: 0
- queue limit: unchanged at 30 frames
- retry / reconnect / resend: `0 / 0 / 0`
- Human Start used during Stress: 0

P1-I remains `INCONCLUSIVE / Technical Verification Required`. P1-J remains `AUTHORIZED WITH KNOWN P1-I LIMITATION`; Stress PASS does not itself establish First E2E Human PASS.

## 2 | Control Failure and Corrective

The uncoalesced control used 100 ms audio input, 510 ms serial bridge service, 250 ms Provider Partial input, and no Partial forwarding interval. It failed the throughput criterion:

- capture: 10.015 seconds
- cadence mean: 108.957 ms before drift correction
- realtime frames: 91
- post-capture drain: 15.337 seconds
- raw / forwarded Partial: 32 / 32
- result: `FAIL / stress_throughput_evidence_incomplete`

Root Cause: Partial is ephemeral but every Provider Partial still consumed a separate serial Laravel request. This could exhaust local development-server capacity even after frame/send piggybacking.

Provider-free Corrective:

- the first non-empty Partial is forwarded immediately;
- subsequent non-empty Partials are latest-value coalesced to a two-second maximum forwarding interval;
- coalesced Partial data remains memory-only;
- Provider Final, Metadata, Close, rejected events, and Durable lineage are never coalesced;
- a pending Partial is discarded when a Provider Final supersedes it;
- a pending Partial may be force-flushed at Normal End;
- received / forwarded / coalesced counts are sanitized Evidence;
- the frame queue limit remains 30.

The Stress scheduler was also changed from drifting `setInterval` timing to absolute 100 ms deadlines. The initial 120-second run with 108.674 ms mean cadence was not accepted as final Evidence.

## 3 | Final 120-second Realtime Stress

Conditions:

- PCM frames: 100 ms / 1,600 samples / 16 kHz / 16-bit / mono
- actual capture duration: 120.012 seconds
- mean cadence: 100.004 ms
- serial bridge service: 510 ms per request
- raw Provider Partial cadence: 250 ms
- Partial forward interval: 2,000 ms
- bounded burst after realtime capture: 20 frames

Results:

| Item | Result |
|---|---|
| realtime frames admitted | 1,200 |
| total frames including burst | 1,220 |
| batches | 122 |
| maximum queue depth | 20 / 30 |
| post-capture drain and burst | 3.135 seconds |
| Source Ranges | 1,220 |
| Provider Send Ranges | 1,220 |
| send ordinal continuity | PASS |
| Source Range continuity | PASS |
| SHA-256 checks | 1,220 / 1,220 |
| raw Partial events | 453 |
| forwarded Partial events | 58 |
| coalesced Partial offers | 396 |
| Partial watermark progression | PASS |
| bounded 20-frame burst | PASS |
| backlog fail-closed threshold | exactly frame 31 |

No queue-limit relaxation, dropped Source Range, invented send receipt, ordinal gap, SHA-256 bypass, or unverified Partial watermark was observed.

## 4 | Regression

- Node syntax: PASS
- Relay Node tests: `22 PASS`
- CE-P1 focused Laravel: `11 PASS / 1 gated live SKIP / 155 assertions`
- P1-J loopback proxy: `1 PASS / 3 assertions`
- prior full Laravel at base HEAD: `671 PASS / 17 SKIP / 1 known out-of-scope FAIL / 5,488 assertions`
- new regression: 0

The full Laravel suite was not repeated because this delta changes only the isolated Node event scheduling boundary; the Laravel controller/database delta and full-suite Evidence at `7b83489` are unchanged. Focused Current Relay and loopback tests were repeated.

## 5 | Human Start Contract

The conditionally approved single P1-J Human Start may proceed without another Human Gate.

- Start button: maximum once
- first confirmation: Waveform and visible Partial
- then, in the same Session / Stream: Final -> Durable Final -> Normal End
- retry / automatic reconnect / manual reconnect / resend: `0 / 0 / 0 / 0`
- stop immediately on FAIL or INCONCLUSIVE; do not Start again
- First E2E Human PASS, if established, is not Stable PASS
- after First PASS, prepare a minimal Repeatability Verification Plan; do not Formal Close

Public Push, Production DB, Production Credential, Deploy, Azure, and other Providers remain untouched and unauthorized.
