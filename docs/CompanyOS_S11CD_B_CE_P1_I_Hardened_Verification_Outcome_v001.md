# Company OS｜CE-P1 P1-I Hardened Limited Provider Verification｜Outcome and Recommended Next Action v001

- Date: 2026-09-30 JST
- Branch: `ce-p1-realtime-corrective`
- Execution authorization: APPROVED / CONSUMED
- Additional execution count: `1`
- Retry / reconnect / resend: `0 / 0 / 0`
- P1-I: `INCONCLUSIVE_RUNTIME_INTEGRATION_FAILURE`
- P1-J: `NOT STARTED`
- Provider Failure: `NOT ESTABLISHED`

## 1｜Conclusion

The approved Hardened Limited Deepgram Verification was invoked exactly once. The run produced a valid run-specific sentinel frame and a parent-owned sanitized Evidence envelope, so the prior stdout framing failure did not recur.

The child exited non-zero before Provider acceptance and before any audio was sent. The result is **not** a Deepgram Provider failure and is not PASS. P1-I remains inconclusive and P1-J is not started.

## 2｜Preflight

- Node.js: `v24.21.0`
- `@deepgram/sdk`: `5.10.0`
- `ws`: `8.22.0`
- approved WAV: 604,900 bytes / SHA-256 `396F978F02BB43D22BA69BACB01F13B59F36BA11FF729AFA144549D60CC5141A`
- DPAPI Credential: loadable / secret not displayed
- estimated full-audio cost: USD `0.0030557728125`
- hard cost ceiling: USD `0.01`
- Node tests: `6 PASS`
- Synthetic Failure Matrix: `12 PASS / 265 assertions`
- Provider communication / audio send before the authorized run: `0 / 0`

The first launch attempt stopped before the test because ephemeral TLS generation lacked the XAMPP OpenSSL config. It performed no Provider communication and consumed no Provider Request. TLS generation was then verified offline using the explicit XAMPP config before the single authorized run.

## 3｜Actual sanitized Evidence

- Harness IPC: `sentinel-json-v1`
- child process: started / exited / code `1`
- stdout frame: captured / valid JSON / no prefix noise
- Provider connection attempt: `true`
- Provider acceptance: `false`
- actual request projection: captured
- `mip_opt_out`: `true`
- reconnect attempts: `0`
- audio send: not started / not completed
- audio samples / bytes / duration: `0 / 0 / 0`
- Provider events: `0`
- close state observed by the child: `1000`
- stderr: empty
- raw stdout / stderr / Provider payload persisted: `false / false / false`

The Provider request projection was limited to Nova-3, Japanese, linear16, 16 kHz, mono, diarization, interim results, `mip_opt_out=true`, and reconnectAttempts `0`. No Credential or Authorization header is present in Evidence.

## 4｜Root Cause analysis

### 4.1 Runtime integration candidate

Static inspection of the pinned SDK establishes a lifecycle mismatch:

1. `client.listen.v1.connect(request)` constructs `ReconnectingWebSocket`.
2. SDK v5 `ReconnectingWebSocket` calls `_connect()` from its constructor when `startClosed` is false.
3. Company OS then calls `provider.connect()`.
4. SDK `V1Socket.connect()` calls `socket.reconnect()`, which disconnects an existing connecting socket and starts another connection.

This double-connect lifecycle is inconsistent with retry/reconnect `0` and is the strongest technical candidate for the early Runtime failure. The corrective should remove the redundant explicit `provider.connect()` and treat the socket returned by `client.listen.v1.connect()` as already connecting.

### 4.2 Remaining Evidence completeness defect

The child framed payload contained a sanitized `safe_reason` and error classification, but the PHP failure writer persisted only the parent `harness` projection. `ProviderEvidenceProcessRunner` also marked the record `complete` without requiring a safe reason or error-layer classification.

Therefore the exact runtime reason cannot be recovered from the persisted Evidence. The lifecycle finding is strongly supported by source inspection, but it is not promoted to a proven execution-specific cause. Unknown remains Unknown.

## 5｜Cost, privacy, and post-stop state

- actual billed cost: `Unknown`
- audio sent: `0` seconds
- conservative approved upper planning value: USD `0.0030557728125`
- raw Credential / Authorization header / raw Provider payload / raw realtime audio persisted: none
- ephemeral TLS: removed
- remaining Node process: none
- Repository `.env`: unchanged
- normal local DB: unchanged
- Azure / other Provider communication: `0 / 0`
- Production Credential / Production DB / deploy / public push: `0 / 0 / 0 / 0`

## 6｜Recommended Next Action

**Recommendation: do not authorize another Provider Request yet.**

First perform a Provider-free corrective limited to:

1. remove the redundant SDK `provider.connect()` call;
2. add a synthetic SDK lifecycle test proving one constructor connection and no explicit reconnect;
3. persist sanitized child `safe_reason` and error-layer classification in the parent Evidence envelope;
4. require those fields for `evidence_completeness=complete` on non-PASS outcomes;
5. expand the Failure Matrix for SDK-open failure, Provider rejection, and connection abort;
6. rerun Node, P1 focused, CE-P1 Regression, and secret scans with Provider communication `0`.

After those checks PASS, present a new explicit Human Gate for at most one request under the same cost, privacy, retry, reconnect, and resend limits. Do not infer that this approval remains open; the approved additional Request has been consumed.

## 7｜Stop

**P1-I = INCONCLUSIVE_RUNTIME_INTEGRATION_FAILURE / STOPPED**

**P1-J = NOT STARTED**

**Additional Provider request = NOT AUTHORIZED**

**Next state = Human + ChatGPT Review**
