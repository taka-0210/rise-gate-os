# Company OS｜CE-P1 P1-I Evidence Harness Hardening｜Recommended Decision Package v001

- Date: 2026-09-30 JST
- Branch: `ce-p1-realtime-corrective`
- Baseline commit: `16c4ebd`
- Provider communication / audio send during Hardening: `0 / 0`
- Deepgram additional request authorization: `NOT AUTHORIZED`
- P1-I / P1-J: `INCONCLUSIVE_EVIDENCE_FAILURE / NOT STARTED`

## 1｜Conclusion

Evidence Harness Hardening is complete and the Provider-free Synthetic Failure Matrix passes.

**Recommendation: authorize exactly one additional Limited Deepgram Request in a separate Human Gate.**

This is a recommendation, not authorization. No Provider request was made during this Hardening.

Both prior executions became inconclusive before Deepgram could be evaluated. The revised Harness now produces parent-owned sanitized process Evidence even when the child emits no usable frame, and extracts a run-specific child frame when one exists. One further limited request is proportionate to obtain the missing Provider Evidence; broader evaluation or retry is not recommended.

## 2｜Root Cause

The prior boundary had three coupled weaknesses:

1. PHP assumed the complete child stdout was exactly one JSON document.
2. PHP decoded that output before persisting a supervisor-owned failure envelope.
3. Node deferred errors could reject before the awaiting boundary serialized the child result.

Therefore unrelated output, empty/truncated output, or early child termination could cause the Harness itself to throw before it recorded request, Provider, audio, close, and parse state. This is an Evidence Harness failure, not a Deepgram Provider failure.

## 3｜Corrective and IPC decision

Selected IPC: **run-specific sentinel framed JSON (`sentinel-json-v1`)**.

Each parent execution generates an unpredictable frame ID. The Node child emits one JSON payload between matching BEGIN/END sentinels. The parent extracts only that matching frame and separately records stdout/stderr byte counts and hashes. Raw stdout, stderr, Provider payload, and Credential are not persisted.

Alternatives considered:

- whole-stdout JSON: rejected because unrelated output and partial termination invalidate the document;
- JSON Lines: rejected because unrelated lines and a truncated final record remain ambiguous;
- dedicated Evidence file: rejected because it adds file permissions, residual-data cleanup, naming, and atomic-write boundaries.

Sentinel framing is the smallest deterministic option that tolerates prefix/suffix noise and multiline JSON while detecting missing, malformed, and truncated frames. If no valid frame exists, the parent still persists process and capture Evidence with Provider fields set to `unknown`.

The parent now records before assertions:

- attempt / child start;
- exit state and safe exit code;
- stdout/stderr capture state, byte count, and SHA-256;
- JSON parse state;
- Provider connection and acceptance state;
- audio state, samples, bytes, and duration;
- event count and close state;
- Evidence completeness and final classification.

Unknown is never promoted to PASS or Provider FAIL.

## 4｜Synthetic Failure Matrix

| Case | Expected final classification | Result |
|---|---|---|
| valid JSON / exit 0 | PASS | PASS |
| valid JSON / non-zero exit | RUNTIME_FAILURE | PASS |
| explicit Provider failure | PROVIDER_FAILURE | PASS |
| stdout prefix/suffix noise | PASS with noise separated | PASS |
| multiline stdout JSON | PASS | PASS |
| stderr present | PASS with hashed stderr state | PASS |
| empty stdout | EVIDENCE_CAPTURE_FAILURE | PASS |
| malformed JSON | EVIDENCE_CAPTURE_FAILURE | PASS |
| truncated / partial JSON | EVIDENCE_CAPTURE_FAILURE | PASS |
| child timeout | CHILD_TIMEOUT | PASS |
| child kill | CHILD_KILLED | PASS |
| abnormal exit | RUNTIME_FAILURE | PASS |

All cases returned a sanitized Harness Evidence contract; none crashed without Evidence. Raw output persistence flags remained false.

The actual Node entrypoint was also executed with the network/audio fences closed. It returned a complete sentinel frame with `provider_requests=0`, `audio_samples=0`, and a `preflight_boundary` classification.

## 5｜Verification and Regression

- Node tests: `6 PASS`.
- Synthetic Failure Matrix: `12 PASS / 265 assertions`.
- P1 focused, including the matrix: `23 PASS / 376 assertions / real Provider gate 1 SKIP`.
- CE-P1 connected Regression: `33 PASS / 326 assertions / real Provider gate 1 SKIP`.
- Full Laravel suite: `660 PASS / 1 pre-existing CE-P1-out-of-scope FAIL / 17 SKIP / 5,260 assertions`.
- PHP syntax and Laravel Pint: PASS.
- Provider communication / audio send: `0 / 0`.

No Product Contract, Provider profile, Ground Truth, source audio, migration, Production DB, port, Tunnel, Firewall, Credential, or deployment boundary changed.

The sole full-suite failure remains `CompanyNavigationTest::regular login ignores a stale forbidden intended url` (expected `/company`, actual `/system-admin/members`). It is outside CE-P1 and was present before this Corrective; all CE-P1-connected Regression passed.

## 6｜Evidence expected from the next real request

Whether the child succeeds, reports Provider failure, reports Runtime failure, times out, is killed, exits abnormally, or emits malformed output, the parent can now persist:

- supervisor process state;
- stdout/stderr capture and parse state;
- actual safe request projection when emitted;
- Provider attempt/acceptance state when known;
- audio samples/bytes/duration when known;
- partial/final/metadata counts when known;
- close state;
- completeness and classification.

On a valid PASS frame, the existing integration gate additionally verifies Provider Event normalization, verified Source Range mapping, Durable Final Commit, and secret/raw-data absence.

## 7｜Recommended next request conditions

If Human approval is granted, recommend exactly:

- Provider: Deepgram Nova-3 Streaming;
- audio: the approved synthetic Japanese WAV only;
- duration: `18.9016875` seconds;
- request: maximum `1`;
- retry / automatic reconnect / manual reconnect / resend: `0 / 0 / 0 / 0`;
- `mip_opt_out=true` required;
- existing CurrentUser DPAPI Evaluation Credential only;
- hard cost ceiling: USD `0.01`;
- conservative full-audio estimate: USD `0.0030557728125`;
- Azure and all other Providers disabled;
- loopback-only runtime; no new public port, Tunnel, Firewall, deploy, Production DB, or Production Credential.

## 8｜Privacy and stop condition

Do not persist raw Credential, Authorization header, raw Provider payload, raw realtime audio, or duplicate transcript body in Evidence. Persist only the sanitized frame, supervisor hashes/counts, normalized identifiers, source ranges, and Durable Final hashes.

After the one request, stop regardless of PASS, FAIL, or INCONCLUSIVE. Do not retry or resend. If Evidence is incomplete, preserve `unknown`, classify the failing layer, and return to Human + ChatGPT Review. P1-J may be prepared only after P1-I PASS and still requires a separate Human operation gate for real device/microphone use.

## 9｜Current stop

**Harness Hardening: PASS**

**Additional Deepgram request: RECOMMENDED BUT NOT AUTHORIZED**

**P1-I: INCONCLUSIVE_EVIDENCE_FAILURE**

**P1-J: NOT STARTED**

**Review state: Human + ChatGPT Review**
