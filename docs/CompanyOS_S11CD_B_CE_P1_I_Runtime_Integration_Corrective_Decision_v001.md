# Company OS｜CE-P1 P1-I Runtime Integration Corrective｜Evidence and Recommended Decision v001

- Date: 2026-09-30 JST
- Branch: `ce-p1-realtime-corrective`
- Corrective mode: Provider-free
- Provider communication / audio send during this Corrective: `0 / 0`
- P1-I: `INCONCLUSIVE_RUNTIME_INTEGRATION_FAILURE / STOPPED`
- P1-J: `NOT STARTED`
- Additional Provider request: `NOT AUTHORIZED`

## 1｜Conclusion

The approved Provider-free Runtime Integration Corrective is implemented and verified.

- The redundant explicit `provider.connect()` call was removed.
- The socket returned by `client.listen.v1.connect()` is treated as already starting its initial connection.
- Parent Evidence now persists sanitized child `safe_reason` and `error_layer_classification`.
- A non-PASS result cannot be marked `evidence_completeness=complete` when either diagnostic field is Unknown.
- Provider-free lifecycle, failure-matrix, P1 focused, and bounded regression checks passed.
- No Provider request, audio send, Credential load, Production DB migration, deploy, or public push occurred.

**Recommended decision: approve exactly one additional Limited Deepgram Verification Request under the unchanged prior limits.**

This is a recommendation only. It does not authorize a request. P1-I remains stopped until a new explicit Human approval is received.

## 2｜Root Cause and Corrective

The pinned Deepgram SDK v5 starts its underlying `ReconnectingWebSocket` during construction. Company OS then called `provider.connect()`, whose SDK implementation invokes reconnect behavior. That redundant lifecycle step conflicted with the zero-reconnect contract and was the strongest runtime-integration cause identified after the hardened P1-I run.

Corrective:

1. `createDeepgramSession()` creates exactly one SDK socket through `client.listen.v1.connect()`.
2. `limited-verification.js` waits for that socket to open without calling `provider.connect()`.
3. A factory seam permits an entirely synthetic lifecycle test without network or Credential use.
4. The parent process envelope extracts and sanitizes child `safe_reason` and the first error-layer classification.
5. Unknown diagnostics remain Unknown; they are never inferred as PASS or Provider failure.
6. Parent-side sanitization removes Authorization, API key, token, Credential, and Bearer-shaped values before persistence.

## 3｜Synthetic Lifecycle Evidence

| Lifecycle measure | Observed |
|---|---:|
| SDK Socket generated | 1 |
| Initial connection started | 1 |
| Explicit `provider.connect()` | 0 |
| Reconnect | 0 |
| Retry | 0 |
| Duplicate socket | 0 |

Node verification: `7 PASS / 0 FAIL`.

## 4｜Synthetic Failure Matrix

Provider communication and audio send remained disabled. The automated matrix completed with `17 PASS / 418 assertions`.

Required cases are covered:

| Case | Evidence behavior |
|---|---|
| SDK open failure | sanitized reason + `session` layer retained; non-success classification |
| Provider rejection | `PROVIDER_FAILURE` only when explicit Provider rejection Evidence exists |
| Connection abort | close `1006`, sanitized reason, Unknown not inferred |
| Lifecycle mismatch | explicit runtime reason/layer retained |
| Child runtime failure | safe exit code, reason, and runtime layer retained |
| Malformed/incomplete Evidence | missing/partial Evidence; never promoted to complete |

The matrix also covers valid success, valid non-zero exit without diagnostics, stdout noise, multiline JSON, stderr hashing, empty stdout, truncated framing, timeout, explicit kill, abnormal exit, and parent-boundary secret redaction.

## 5｜Evidence Completeness Contract

For non-PASS classifications, `evidence_completeness=complete` now requires:

- valid sentinel-framed JSON;
- known connection, acceptance, audio, sample, event, and close states;
- known sanitized `safe_reason`;
- known `error_layer_classification`.

Missing diagnostics produce `partial` or `missing`. Unknown remains Unknown. Raw stdout, raw stderr, raw Provider payload, raw Credential, and Authorization Header are not persisted.

## 6｜Verification Results

| Verification | Result |
|---|---|
| Node relay tests | `7 PASS / 0 FAIL` |
| Synthetic Evidence Failure Matrix | `17 PASS / 418 assertions` |
| P1 focused | `28 PASS / 1 gated SKIP / 529 assertions` |
| CE-P1 + bounded P3/P4/P5 + Release Hardening regression | `54 PASS / 1 gated SKIP / 757 assertions` |
| PHP syntax / Node syntax | PASS |
| Laravel Pint for changed PHP | PASS |
| Full Laravel suite | completed; the known out-of-scope `CompanyNavigation` stale intended-URL mismatch remains the only observed failure |
| Known failure isolated reproduction | expected `/company`, actual `/system-admin/members`; unchanged CE-P1-out-of-scope failure |
| Secret scan | PASS after review of two explicit synthetic literals and one fail-closed error identifier; no real Credential, Authorization value, or private key |

The gated SKIP is the closed real-Provider test. It was not opened by this Corrective.

## 7｜Repository, Environment, and Runtime Boundaries

- Repository `.env` SHA-256 remained `DC2A0E2A2402229EFA555609FFCEB6A9721F9A2CC1A452D5B7DEEEF3BC6CB0ED`.
- Normal local DB SHA-256 remained `19278A4B11E3DCBF1B717969C554D7E45919B1C0B362F54EA1BFCA4D9BFC072C`.
- Remaining Node processes after verification: `0`.
- Provider communication / audio send: `0 / 0`.
- Deepgram / Azure / other Provider request: `0 / 0 / 0`.
- Production Credential / Production DB migration / deploy / public push: `0 / 0 / 0 / 0`.

## 8｜Recommended Limited Request Conditions

If Human approval is granted, the recommendation is one and only one Limited Deepgram Verification under the unchanged contract:

- Deepgram Nova-3 Streaming;
- approved synthetic Japanese WAV, `18.9016875` seconds;
- WAV SHA-256 `396F978F02BB43D22BA69BACB01F13B59F36BA11FF729AFA144549D60CC5141A`;
- maximum Request `1`;
- retry / automatic reconnect / manual reconnect / resend: `0 / 0 / 0 / 0`;
- `mip_opt_out=true` required;
- existing DPAPI Evaluation Credential only;
- hard cost ceiling USD `0.01`;
- conservative full-audio estimate USD `0.0030557728125`;
- Azure and every other Provider disabled;
- no raw Credential, Authorization Header, raw Provider payload, or raw realtime audio persistence.

Stop after the single result regardless of PASS, FAIL, or INCONCLUSIVE. Missing Evidence remains Unknown and no resend occurs.

## 9｜Current Stop

**Corrective verification = PASS**

**Recommendation = AUTHORIZE_ONE_ADDITIONAL_LIMITED_REQUEST_AT_A_NEW_EXPLICIT_HUMAN_GATE**

**Current authorization = NOT AUTHORIZED**

**P1-I = INCONCLUSIVE_RUNTIME_INTEGRATION_FAILURE / STOPPED**

**P1-J = NOT STARTED**

**Next state = Human + ChatGPT Review**
