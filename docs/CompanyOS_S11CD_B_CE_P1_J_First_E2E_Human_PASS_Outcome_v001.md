# Company OS | CE-P1 P1-J First E2E Human PASS Outcome v001

Recorded: 2026-09-30 13:02 JST

Base HEAD: `a6b1073`

Scope: explicitly approved single P1-J Human Start after the Mixed Upstream Backpressure Corrective

## 1 | Disposition

- P1-I: **INCONCLUSIVE / Technical Verification Required**
- P1-J: **FIRST E2E HUMAN PASS ESTABLISHED / STABILITY VERIFICATION REQUIRED**
- P1-J Formal PASS / Formal Close: **NOT ESTABLISHED**
- Deepgram Provider Failure: **NOT ESTABLISHED**
- approved Human Start: consumed `1 / 1`
- retry / automatic reconnect / manual reconnect / resend: `0 / 0 / 0 / 0`
- additional Provider request after this result: `0`

The approved Human Start completed the same-stream path from microphone capture through visible Japanese Durable Final output and Normal End. This is the first end-to-end Human PASS for the Current Company OS Relay Path. It does not retroactively make P1-I PASS and does not establish repeatable or stable behavior by itself.

## 2 | Human UX Evidence

The Human screen established:

- the Waveform moved while the Human spoke;
- Japanese recognition appeared as six numbered committed items;
- the UI reported `Stopped normally. Final evidence is server-authoritative.`;
- Capture became `stopped` and Relay became `disconnected`;
- no `Safe stop`, WSS 1011, unavailable, rejected, or backlog failure was displayed.

The Human-visible result therefore establishes Waveform, Japanese transcription, Final/Durable Final presentation, and Normal End in one stream. Ephemeral Partial handling is additionally established by sanitized Runtime Evidence; the post-stop screenshot is not treated as a separate visual capture of an in-flight Partial.

## 3 | Sanitized Runtime Evidence

| Item | Result |
|---|---|
| Provider acceptance | PASS; one Deepgram Provider Session |
| MIP / reconnect | `mip_opt_out=true` / reconnect `0` |
| audio sent | 569,600 samples / 35.6 seconds |
| audio ledger batches | 36 |
| Provider Partial events observed | 23 |
| Partial flow | received 10 / forwarded 6 / coalesced 6 |
| Durable Final events | 6 |
| Metadata events | 13 |
| scheduler maximum / final queue | 6 / 0 |
| close | `normal=true` |
| conservative estimated cost | USD 0.005755 at USD 0.0097/minute |
| actual billed cost | Unknown |

The sanitized Evidence archive SHA-256 is `879AE8F7554CEEF48B5D646DF64B721F6B1B5309C42DDA2C5185F4AFE426338E`. It contains no detected Authorization, Bearer, or API-key pattern.

## 4 | Isolated DB Lineage Evidence

The isolated verification DB contains the following lineage for the one Provider Session:

| Evidence | Result |
|---|---|
| Provider Session | `closed` / `normal_stop` |
| Relay Lease | `closed` / `normal_stop` |
| Capture Stream | `stopped`; no interruption or safe error |
| Source Ranges | 356 accepted; sequence 1..356; samples 0..569600 |
| Provider Send Ranges | 356 sent; ordinal 1..356; samples 0..569600 |
| verified Final receipts | 6 accepted |
| Durable Final Commits | 6 / 6 committed |
| Durable Final Commit Items | 6 |
| realtime Transcript Segments | 6; each linked to one commit |
| fictitious AudioWindow | 0; all six realtime segments use nullable AudioWindow with required realtime lineage |

This establishes `Provider Final -> verified Provider Event Receipt -> Source Range -> Durable Final Commit -> Transcript Segment/Revision` without a fabricated AudioWindow.

## 5 | Safety and Non-change Evidence

- raw realtime audio persisted: **no**;
- raw Provider payload persisted: **no**;
- raw Credential / Authorization Header persisted or displayed: **no**;
- Transcript body duplicated into repository Evidence: **no**;
- Repository `.env` SHA-256 remained `DC2A0E2A2402229EFA555609FFCEB6A9721F9A2CC1A452D5B7DEEEF3BC6CB0ED`;
- normal local DB SHA-256 remained `CC0F4A7B176B3284D1768D73B689E9DE87CDB68CC4E89FB5F69471F27995AF4A`;
- isolated loopback runtime was stopped after Evidence capture;
- listeners remaining on ports 8443 / 8765: `0`;
- Public Push / Production DB / Production Credential / Deploy: `0 / 0 / 0 / 0`.

## 6 | First PASS Acceptance

| Required path | Result |
|---|---|
| Mic | PASS |
| same-stream Waveform | PASS |
| same-origin WSS / Relay | PASS |
| Provider acceptance | PASS |
| Audio Send | PASS |
| Partial | PASS by Runtime Evidence |
| Final | PASS |
| Durable Final | PASS by UI and DB lineage |
| Normal End | PASS by UI, Runtime Evidence, and DB state |

Decision: **First E2E Human PASS is established. First PASS is not Stable PASS.**

## 7 | Recommended Repeatability / Stability Verification Plan

No further Provider communication is authorized by this document. The minimum sufficient follow-up is:

### Gate A | Core repeatability

Run two additional independent Shared Sessions on the current Windows PC and Microsoft Edge, each with a fresh Capture Stream and exactly one `Start realtime voice`.

- speak for 20 to 45 seconds;
- confirm Waveform, visible Japanese Partial, Final, Durable Final, and Normal End;
- require continuous Source/Send ordinals, at least one verified Durable Final, scheduler depth below 30, and no safe error;
- stop the entire plan on the first FAIL/INCONCLUSIVE without retry or replacement run.

Candidate Stable Core requires `2 / 2` independent PASS results in addition to this First PASS. The two future runs are not approved here.

### Gate B | Lifecycle and Product interaction

Only after Gate A passes, use one deliberately scoped Shared Session to cover the remaining Human-only interaction boundaries:

1. Pause, verify truthful paused/closed state and no continued audio egress, then explicitly Resume with current authorization/Consent revalidation.
2. Move Edge to Background and return to Foreground; verify no false-active display and no automatic reconnect/resume.
3. During a new explicit stream, invoke `現在の確定TranscriptでCOに相談` once and verify bounded finalization grace includes Durable Final only, never an ephemeral Partial.
4. End the Shared Session and verify the terminal state.

Use no more than three Provider generations in this lifecycle Session, matching the existing runtime hard limit. Stop on the first failure without retry/reconnect/resend.

### Device scope

- current local stability candidate: Windows PC + Microsoft Edge;
- secondary desktop browser, iPhone Safari, and installed PWA remain separate Human UX coverage items and are not required to establish the local Relay Stable Core;
- mobile/PWA verification requires an independently approved reachable environment and must not introduce a Public Port, Tunnel, Firewall change, or Deploy through this plan.

### Cost and stop boundary

If a future Human Gate approves both Gate A and Gate B, cap each future Provider generation at 45 seconds, total additional audio at 180 seconds, retry/reconnect/resend at `0 / 0 / 0`, and conservative aggregate estimated cost at USD 0.0291. Any missing Evidence remains Unknown and blocks Stable PASS.

## 8 | Recommended Decision

**Accept this run as First E2E Human PASS and keep P1-J open for Stability Verification. Do not Formal Close.**

Recommend a later Human + ChatGPT Gate authorize Gate A as one package with a maximum of two independent Human Starts. Gate B should be authorized only after Gate A passes. P1-I remains INCONCLUSIVE; CE-G01 is not changed by this outcome.
