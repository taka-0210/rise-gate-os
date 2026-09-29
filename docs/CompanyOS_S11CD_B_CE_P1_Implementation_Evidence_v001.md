# Company OS｜CE-P1 Realtime Corrective Implementation Evidence v001

- Evidence date: 2026-09-29 JST
- Branch: `ce-p1-realtime-corrective`
- Baseline local commit: `9890dc0`
- P1-B〜P1-E local commit: `ae95731`
- Public push: **0**
- Deploy: **0**
- Provider communication / audio send in CE-P1: **0 / 0**
- Production / normal local DB migration: **0**
- Feature flags: default **OFF / audio send OFF**

## 1. Phase status

| Phase | Status | Evidence |
|---|---|---|
| P1-A Contract / Test Skeleton | DONE | Provider-neutral DTO/Port, canonical frame, safe reasons, fail-closed config, MIP/audio-send pre-network guard. Local commit `9890dc0`. |
| P1-B Additive Schema | DONE | Approved eight-table migration created. Isolated temporary SQLite full up and latest migration down PASS. FK/unique/index/existing bounded schema checks PASS. |
| P1-C Authorization Lease / Control Plane | DONE | Short-lived lease bound to tenant/conversation/session/stream/generation/audience/consent/membership/credential. Transactional outbox integrated with pause/end/cancel/interrupt. Resume requires a new generation. |
| P1-D Continuous Source Cursor / Send Ledger | DONE | PCM16/16 kHz/mono/100 ms exact frame contract, contiguous absolute sample cursor, exact duplicate ACK-only, identity/hash conflict and gap/overlap fail closed, provider-send mapping ledger. |
| P1-E Provider-neutral Receipt / Deepgram Adapter | DONE — Synthetic only | Deepgram event normalization, ephemeral partial, encrypted safe final metadata, metadata/error/close receipts, provider timing, word timing, anonymous speaker hint, duplicate/out-of-order/late-final fencing, raw payload/credential exclusion. |
| P1-F Durable Final Commit | HUMAN GATE REQUIRED | Existing Segment schema cannot represent realtime source without a non-approved compatibility extension; see §8. |
| P1-G Continuous Capture / Waveform | NOT STARTED | P1-F gate reached first in the approved sequence. |
| P1-H Partial / Final UI / CO Grace | NOT STARTED | Depends on canonical Durable Final integration. |
| P1-I Real Provider Verification | NOT STARTED | No Provider call was attempted. |

## 2. Runtime and SDK decision

- Runtime: isolated portable Node.js `v24.21.0` Windows x64.
- SDK: exact `@deepgram/sdk@5.10.0`, server-side relay only.
- Lockfile: `realtime-relay/package-lock.json`.
- Registry integrity: `sha512-GNse88Irf4ow4UlL+WbJjDOnJbpnX6B6e21PLxzfG0ioJlx5rVGbEX0OF06QgTiVyJ+VCtG5gdNum5DV2pwI1A==`.
- SDK license: MIT.
- Transitive runtime dependency locked by the package lock: `ws@8.22.0`; optional native accelerators are not installed.
- Global install / PATH change: none.
- SDK or Provider credential in Browser: none.
- Automatic retry / reconnect: disabled.
- `mip_opt_out=true`: enforced in both Laravel request guard and isolated Node port before network creation.
- Alternative considered: direct provider WSS. Rejected because the official SDK satisfies the approved isolated server-side boundary without exposing credentials.

## 3. Migration evidence

Created exactly the approved eight tables:

1. `ai_common_shared_relay_leases`
2. `ai_common_shared_relay_control_events`
3. `ai_common_shared_source_ranges`
4. `ai_common_shared_provider_sessions`
5. `ai_common_shared_provider_send_ranges`
6. `ai_common_shared_provider_event_receipts`
7. `ai_common_shared_durable_final_commits`
8. `ai_common_shared_durable_final_commit_items`

Verification:

- Full migration up on a newly generated temporary SQLite DB: PASS.
- Latest CE-P1 migration down on the same isolated DB: PASS.
- After down, CE-P1 migration status: Pending; older migrations remained applied.
- Required FK/unique/index names: automated PASS.
- Existing bounded transcript tables and fixture creation/read path: automated PASS.
- Past-record synthetic backfill: none.
- Production DB, shared DB, and normal local DB: untouched.
- Down refuses destructive table removal when realtime Evidence rows exist.

## 4. Lease and control evidence

- Lease issue and idempotent re-issue for the same current generation: PASS.
- Current authorization and all required Consent revisions are checked before lease issue/refresh.
- Audience, Consent, membership epoch, and credential generation are fingerprint-bound.
- TTL/refresh defaults: 12 s / 4 s; unsafe values fail closed.
- Pause writes a `lifecycle_close` outbox event and moves the lease to `revoking`.
- Cancel/interruption writes a `hard_abort` event.
- Resume does not reuse the old lease or provider generation; the old stream is stopped and a new stream starts with generation +1.
- Node connection registry makes forced close idempotent and disables frame acceptance/provider egress before socket close.
- No Redis, external service, public port, tunnel, or firewall change was added.

## 5. Source cursor evidence

- Canonical frame: PCM signed 16-bit little endian, 16 kHz, mono, 1,600 samples / 100 ms.
- Inclusive start / exclusive end absolute sample range.
- Exact duplicate requires matching stream, generation, sequence, client event identity, range, and SHA-256.
- Conflicting identity or content fails closed.
- Gap and overlap fail closed; no silence is fabricated.
- Provider send ordinal and provider-offset mapping are append-only and source-verifiable.
- Provider mapping is `unverified` unless the entire requested range is contiguous.

## 6. Synthetic Provider evidence

Provider communication remained disabled. Synthetic tests prove:

- partial is memory-only and cannot produce a durable receipt;
- final is accepted only with verified source mapping;
- normalized final metadata is encrypted at rest;
- Provider raw payload, Authorization value, header, and secret are not persisted;
- Deepgram request identity is hashed;
- word timing and anonymous `speaker-N` hints are normalized;
- Provider start/duration are converted to source sample units;
- exact duplicate final is idempotent;
- out-of-order event fails closed;
- metadata, error, and close produce safe durable receipts only;
- final arriving after lease revocation is rejected and cannot become canonical;
- `mip_opt_out=false` and audio-send disabled both reject before transport;
- SDK session configuration supplies `AbortSignal` and `shouldReconnect: () => false`.

## 7. Automated verification

- Node relay tests: **2 PASS**.
- CE-P1 focused Laravel tests: **8 PASS / 62 assertions**.
- Existing bounded session focused regression (P3/P5): **14 PASS / 152 assertions**; flag OFF keeps the legacy resume behavior, and only a realtime-managed lease switches to a new generation.
- Isolated migration full up/down: **PASS**.
- PHP syntax for changed services/tests: **PASS**.
- Full Laravel regression: one reproducible unrelated failure in `CompanyNavigationTest::regular login ignores a stale forbidden intended url` (expected `/company`, actual `/system-admin/members`). CE-P1 focused tests and all observed CE-P1 tests pass.
- Full Node test discovery: CE-P1 relay tests PASS; one unrelated existing workspace-script syntax test fails because the raw Blade @include('project-apps.workspace-script') token is evaluated as JavaScript.
- JST: no new user-visible date/time behavior was introduced; Evidence date is JST.

## 8. Human Gate at P1-F

The approved realtime data contract requires:

- raw realtime audio stays in relay memory only;
- no fabricated AudioWindow or fabricated source Evidence;
- Durable Final reuses the existing Transcript Segment / immutable Revision / lineage path.

The existing `ai_common_shared_transcript_segments.ai_common_shared_audio_window_id` is a **non-null foreign key**. The only current provider Writer creates a Segment from a persisted, non-empty bounded AudioWindow. Therefore a realtime Durable Final cannot enter the existing Segment/Revision lineage without one of these unapproved changes:

1. create a synthetic/fake AudioWindow despite no durable realtime audio — rejected because it fabricates evidence and violates the approved data contract; or
2. extend the existing Segment source contract, for example by making `ai_common_shared_audio_window_id` nullable, adding an explicit bounded/realtime source discriminator, and defining realtime source identity through Durable Final Commit → Receipt → Source Range — this changes an existing schema contract outside the approved exact eight-table MIG-D01.

This is Final Gate Human Gate condition **#6** (existing Writer/Revision/lineage cannot be reused as currently modeled) and may also touch **#16** (approved migration scope extension). No speculative schema change was made.

Recommended approval candidate:

- additive/non-destructive compatibility extension only;
- existing rows remain `bounded_audio`;
- `audio_window_id` stays required for bounded rows and becomes null only for `realtime_source`;
- realtime source lineage is mandatory through the accepted Durable Final Commit/Receipt/Source Range chain;
- existing immutable Revision, human correction, Long Context, authorization, and deletion boundaries remain unchanged;
- no raw realtime audio persistence and no fake backfill.

## 9. Boundary state

- Product Contract deviation: **none**.
- Security / Privacy boundary deviation: **none**.
- Browser Provider secret: **none**.
- Provider communication / audio send: **0 / 0**.
- Production credential use: **0**.
- Public push: **0**.
- Deploy: **0**.
- Next action: Human + ChatGPT decision on the minimal P1-F Segment source compatibility extension. P1-G/H and real Provider verification remain stopped.
