# Company OS｜CE-P1 Realtime Corrective Implementation Evidence v001

- Evidence date: 2026-09-30 JST
- Branch: ce-p1-realtime-corrective
- Baseline / prior local commits: 9890dc0 / ae95731 / ce49baa
- Public push / deploy: 0 / 0
- CE-P1 Provider communication / audio send: 0 / 0
- Production Credential use: 0
- Production / normal local / shared DB migration: 0
- Realtime feature flags: default OFF / audio send OFF

This update records the approved P1-F compatibility extension and repository/synthetic implementation through P1-H. It does not declare Provider Evidence or Human UX PASS.

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
| P1-I Limited Provider Verification | HUMAN GATE / NOT STARTED | No Deepgram connection or audio transmission occurred. See §11. |
| P1-J Human UX / device matrix | NOT STARTED | Automated evidence is not Human UX PASS. |

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

Runtime limitation: no persistent relay process/listener, public port, reverse-proxy upgrade, Tunnel or Firewall change was created. The repository does not declare a standalone WSS server dependency. Actual WSS transport and end-to-end microphone behavior remain Provider/Human evidence.

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
- Locked transitive ws: 8.22.0; not newly declared as an application WSS server dependency.
- Global install / PATH mutation: none.
- Browser/Repository/report/log Provider Credential: none.
- mip_opt_out=true is fixed in the request builder and checked again immediately before network creation.
- automatic_retry=false and shouldReconnect returns false.
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
| P1 focused (after final WSS close guard) | 7 PASS / 96 assertions |
| P1 + bounded P3/P4/P5 | 29 PASS / 295 assertions |
| Release Hardening | 4 PASS / 29 assertions |
| Isolated migration up / compatibility down / reapply | PASS |
| Relay synthetic Node | 2 PASS |
| Relay and browser JS syntax | PASS |
| Full Laravel suite | 645 PASS / 4 FAIL / 16 SKIP / 4,986 assertions before the final WSS close-only tightening; focused P1 rerun PASS afterward |

No CE-P1 test failed. The four remaining full-suite failures are outside changed CE-P1 paths: one existing CompanyNavigation stale intended-URL expectation and three date/schedule-sensitive ScopeNineActionExecution cases. The prior Migration-count failure is resolved.

## 9｜Evidence disposition

| Layer | Disposition |
|---|---|
| Product Design | Approved P1-F extension implemented |
| Repository | P1-A〜P1-H candidate implemented |
| Automated | Focused, connected regression, race/rollback and migration PASS |
| Provider | NOT STARTED in CE-P1 |
| Human UX | NOT STARTED |
| CE-P1 | IN PROGRESS / stopped at P1-I Human Gate |
| CE-G01 | CLOSED |

P1-G/H are not promoted to Product/Human PASS from automated evidence alone.

## 10｜P1-I first communication: exact proposed scope

No item below has executed.

Credential:

- Candidate is the existing Evaluation-only Deepgram Credential stored by Windows CurrentUser DPAPI outside the Repository.
- Secret plaintext remains absent from Evidence/Browser/Git/log.
- Production Credential use remains prohibited.
- DPAPI-to-relay load and zeroization must be verified before transport.

Existing approved non-customer audio:

- Microsoft Haruka Desktop synthetic Japanese speech;
- PCM WAV / 16 kHz / 16-bit / mono;
- duration 18.9016875 seconds;
- 100 ms chunks at realtime 1.0x;
- source SHA-256 396F978F02BB43D22BA69BACB01F13B59F36BA11FF729AFA144549D60CC5141A;
- Ground Truth SHA-256 395041A321E8A77317FC95DB606B6F7BDCF81877E72E4FF821A1A4FC083F3E04;
- no customer, business, confidential or third-party human data.

Request:

- Deepgram Nova-3 Streaming, language ja, diarization and interim enabled;
- mip_opt_out=true exactly;
- maximum 1 request / 18.9016875 seconds;
- automatic retry/reconnect 0/0; manual retry/reconnect 0/0;
- estimated cost USD 0.0030557728125;
- proposed hard ceiling USD 0.01;
- unknown cost before send: fail closed.

Only linear PCM plus technical request fields and random Provider correlation may leave the relay. Tenant names, membership data, sources/citations and unrelated conversation context are excluded.

Required Evidence: server-held credential, actual MIP projection, WSS/authentication, partial/final, result identity, Provider and word timing, anonymous speaker hint, source mapping, Durable Final lineage, close/error/egress stop, sanitized usage/cost, and absence of raw payload/audio/secret/header from durable Evidence.

Stop with no retry on credential/runtime/fence mismatch, authorization/Consent/generation/sequence/range/hash failure, unexpected close/error, incomplete Evidence, unknown/excess cost, or any new unapproved package/process/network change.

## 11｜Additional approval item and current stop

The browser and Laravel WSS contracts are implemented, but an actual same-origin WSS listener needs an isolated persistent runtime. Starting it, declaring a direct WSS server dependency, or changing reverse proxy/port remains unapproved. This must be reviewed together with the P1-I first-communication scope; no process or listener was started speculatively.

Current state:

- CE-P1 Provider request / audio / cost: 0 / 0 seconds / USD 0.
- Prior CE-PD08B traffic is not counted as CE-P1.
- Production DB migration / Credential: 0 / 0.
- Public push / deploy: 0 / 0.
- Next action: Human + ChatGPT review of §10–11 only. Do not enter P1-I/P1-J, Provider adoption, Production migration, push or deploy without approval.