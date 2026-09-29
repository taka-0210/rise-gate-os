# Company OS｜CE-P1 P1-I Runtime-Corrected Limited Verification｜Outcome and Recommended Next Action v001

- Date: 2026-09-30 JST
- Branch: `ce-p1-realtime-corrective`
- Approved additional Request: `1 / consumed`
- Retry / automatic reconnect / manual reconnect / resend: `0 / 0 / 0 / 0`
- Result: `INCONCLUSIVE_RUNTIME_LIFECYCLE_FAILURE`
- Provider Failure: `NOT ESTABLISHED`
- P1-I: `STOPPED`
- P1-J: `NOT STARTED`
- Further Provider request: `NOT AUTHORIZED`

## 1｜Conclusion

The approved Limited Deepgram Verification used its single Request allowance and stopped without retry or resend.

The sanitized outcome is `RUNTIME_FAILURE / partial Evidence`. The Harness prepared one Provider session but did not establish Provider acceptance. It timed out at `provider_open_timeout` before audio send. Audio, Provider events, and durable integration remained zero/not reached.

This is **not** classified as a Deepgram Provider failure. Missing close Evidence remains Unknown and is not inferred as PASS or Provider failure.

## 2｜Preflight and Scope

Provider-free Preflight passed immediately before execution:

- Node.js `v24.21.0`;
- `@deepgram/sdk@5.10.0` and `ws@8.22.0`;
- approved WAV: 604,900 bytes, 302,427 sample frames, 16 kHz / 16-bit / mono;
- WAV duration `18.9016875` seconds;
- WAV SHA-256 `396F978F02BB43D22BA69BACB01F13B59F36BA11FF729AFA144549D60CC5141A`;
- DPAPI Evaluation Credential loadable without displaying the secret;
- `mip_opt_out=true`, Request max 1, retry/reconnect 0;
- estimated full-audio cost USD `0.0030557728125` below hard limit USD `0.01`;
- Node lifecycle/policy tests `7 PASS` before the Request;
- Repository `.env` and normal local DB baseline hashes matched.

An initial local TLS preparation invocation stopped before the attempt marker and before the test because OpenSSL progress on stderr was treated as a PowerShell error. Evidence, marker, Node process, TLS material, Credential load, Provider request, and audio send were all absent. TLS logging was then isolated without changing the runtime contract. This preparation stop did not consume the one Request allowance.

## 3｜Actual Sanitized Evidence

| Evidence | Result |
|---|---|
| Harness final classification | `RUNTIME_FAILURE` |
| Evidence completeness | `partial` |
| Safe reason | `provider_open_timeout` |
| Error layer | `session` |
| Request allowance | `1 / consumed` |
| Provider acceptance | `false` |
| Audio samples / bytes / seconds | `0 / 0 / 0` |
| Provider events | `0` |
| Close state | `Unknown` |
| Retry performed | `false` |
| Raw failure payload persisted | `false` |

The attempt marker was created atomically before opening the real test gate. Its SHA-256 is `75B0175D66FEDB05B2F9712DEBF9B78C59AD2C824B0F07F68ACD979B7B122EF4`. Any accidental rerun through the same procedure now fails before Provider access.

Actual billed cost is Unknown. No audio seconds were sent, and no usage event was received. The conservative full-audio estimate is not treated as actual billing Evidence.

## 4｜Root Cause

Pinned SDK source inspection establishes the lifecycle mismatch:

1. `client.listen.v1.connect()` enters `WrappedListenV1Client.connect()`.
2. The SDK creates its underlying `ReconnectingWebSocket` with `startClosed: true`.
3. The returned `WrappedListenV1Socket` is therefore CLOSED and has not started its transport.
4. The prior Corrective assumed that returning the socket also started the connection and removed the socket's initial `connect()` call.
5. `waitForOpen()` then waited on a startClosed socket until `provider_open_timeout`.

The previous Synthetic lifecycle test modeled socket construction as connection start, so it did not represent the pinned SDK's actual `startClosed` behavior. The real result exposed that test-model defect.

This is a Company OS SDK-integration failure, not a Deepgram response or rejection.

## 5｜Provider-Free Corrective

The technical Corrective was implemented without any additional Provider communication:

- added a one-shot `startDeepgramSession()` boundary;
- event handlers are registered before the initial start;
- the SDK's initial start method is invoked exactly once;
- a second start on the same session fails closed;
- `reconnectAttempts=0` remains fixed in the actual request projection;
- no automatic/manual reconnect or retry path was added;
- added a test against the pinned SDK object proving it is CLOSED before initial start;
- replaced the earlier inaccurate Synthetic lifecycle model with `startClosed → initial start once → duplicate start rejected`.

Corrected lifecycle Evidence:

| Lifecycle measure | Observed |
|---|---:|
| SDK Socket created | 1 |
| State before initial start | CLOSED / `3` |
| Initial start method calls | 1 |
| Initial connection start | 1 |
| Post-start reconnect | 0 |
| SDK retry | 0 |
| Duplicate start accepted | 0 |

## 6｜Provider-Free Verification

| Verification | Result |
|---|---|
| Node relay / pinned SDK lifecycle | `8 PASS / 0 FAIL` |
| P1 focused | `28 PASS / 1 gated SKIP / 529 assertions` |
| CE-P1 + bounded P3/P4/P5 + Release Hardening regression | `54 PASS / 1 gated SKIP / 757 assertions` |
| Syntax / diff check | PASS |
| Real Provider gate after the consumed attempt | CLOSED / SKIPPED |

The gated SKIP is intentional. The Corrective was not used for another Provider Request.

## 7｜Security, Privacy, and Post-Stop State

- raw Credential / Authorization Header persisted: none;
- raw Provider payload persisted: none;
- raw realtime audio persisted: none;
- transcript body persisted in Evidence: none;
- audio sent: `0` seconds;
- Provider events persisted: `0`;
- retry / reconnect / resend: `0 / 0 / 0`;
- Azure / other Provider communication: `0 / 0`;
- remaining Node/P1-I PHP process: `0 / 0`;
- ephemeral TLS directory: removed;
- Repository `.env` SHA-256 remained `DC2A0E2A2402229EFA555609FFCEB6A9721F9A2CC1A452D5B7DEEEF3BC6CB0ED`;
- normal local DB SHA-256 remained `19278A4B11E3DCBF1B717969C554D7E45919B1C0B362F54EA1BFCA4D9BFC072C`;
- Production Credential / Production DB / deploy / public push: `0 / 0 / 0 / 0`.

## 8｜Recommended Next Action

**Recommendation: authorize one final Limited Deepgram Verification through a new explicit Human Gate, using the corrected startClosed lifecycle and the unchanged limits.**

Rationale:

- the observed failure has a deterministic, source-confirmed local root cause;
- no audio was sent and Provider rejection was not established;
- the Corrective is narrow, Provider-free, automated, and regression-tested;
- duplicate start, retry, reconnect, and resend remain fail closed.

Recommended conditions remain:

- Deepgram Nova-3 Streaming;
- the same approved 18.9016875-second Synthetic Japanese WAV and SHA-256;
- maximum Request `1`;
- retry / automatic reconnect / manual reconnect / resend `0 / 0 / 0 / 0`;
- `mip_opt_out=true` required;
- existing DPAPI Evaluation Credential only;
- hard cost ceiling USD `0.01`;
- Azure and every other Provider disabled;
- stop after the single PASS, FAIL, or INCONCLUSIVE result;
- no inference from missing Evidence and no resend.

This report is a recommendation, not authorization. No additional Request may occur until a new explicit approval is received.

## 9｜Current Stop

**P1-I = INCONCLUSIVE_RUNTIME_LIFECYCLE_FAILURE / STOPPED**

**P1-J = NOT STARTED**

**Provider Failure = NOT ESTABLISHED**

**Further Provider request = NOT AUTHORIZED**

**Next state = Human + ChatGPT Review**
