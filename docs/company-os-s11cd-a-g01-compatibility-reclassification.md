# Company OS | S11 Companion Delta A
# A-G01 Compatibility Reclassification

**Decision date: 2026-09-27 JST**

## 1. Decision boundary

The A-P0 readiness report and the CD-AB-C02 Corrective evidence were re-read under this Gate rule: A-G01 requires zero unresolved compatibility defects in the existing Formal Closed implementation that could break a Closed Contract when Delta A starts. Evidence that can only exist after Attachment / Voice / Transcription implementation is not a P0 PASS prerequisite.

- Scope 11 remains **Formal Closed** and was not reopened.
- CD-AB-C02 remains **Resolved candidate** based on the completed F01-F05 evidence.
- Delta A P1-P5, Attachment/Voice implementation, new Migration, Product Contract, Master, IR-1, Production, Deploy, and normal local DB were not changed or started.
- S11-CD-A-G01 remains **CLOSED** pending the explicit Human + ChatGPT A-G01 Final Decision.

## 2. C01-C08 reclassification

| Compatibility | Existing implementation assessment | Remaining evidence destination | Pre-Gate result |
| --- | --- | --- | --- |
| CD-AB-C01 File / Storage | The legacy Project attachment path has non-atomic binary/DB operations, but Delta A does not adopt that controller or delete path as its Writer. The approved additive Attachment Writer, quarantine, CAS, operation journal, and origin adapter isolate the new path without changing the existing Closed Contract. | Delta A P1-P5: storage fault injection, orphan recovery, revoke/extract races, Upload and Existing Reference authorization. Production storage/backup operation remains Release Verification. | **No blocker. Not-yet-implemented Delta A evidence.** |
| CD-AB-C02 Source lineage | F01-F05 are PASS on SQLite and MariaDB after the limited additive Corrective. Immutable revisions, transitive lineage, per-attempt authorization, response-boundary authorization, and Proposal provenance preserve existing Proposal/Writer meaning. | Resolved-candidate review only. No Delta A implementation is required to establish this compatibility result. | **Resolved candidate; no blocker.** |
| CD-AB-C03 Proposal Snapshot | The existing Proposal Engine, canonical hash, approval/apply/undo flow, current Target authorization, and Unit Writers remain in use. The C02 relation is additive and does not change Proposal snapshots or whitelists. Delta A introduces no new result-visibility path before implementation. | Delta A P1-P5: Attachment/Transcript-derived Proposal lineage and negative authorization regression. Shared principal expansion remains Delta B. | **No blocker. Future connection evidence.** |
| CD-AB-C04 Notification authorization | Delta A creates no Notification type/source and does not change the Scope 10 authorization fallback. Therefore there is no new A path that can weaken Center, badge, open, cancellation, or retry authorization. | Delta B P0/P1-P5 when Shared invite/mention/proposal notification sources are introduced. Not a Delta A or Release prerequisite. | **No blocker; A boundary confirmed unchanged.** |
| CD-AB-C05 Schema / Storage / Environment | The planned schema is additive, preserves existing IDs/history, and has a SQLite/MariaDB transaction/lock strategy. The C02 additive migration already demonstrates that the current repository can preserve Scope 11 history on both engines; Attachment schema and private storage do not yet exist and therefore cannot be runtime-PASS at P0. | Delta A P1-P5: new DDL, rollback/reapply, SQLite/MariaDB races, private disk, scanner/decoder failure behavior. Release Verification: deployed-host tool/key/backup/restore and capacity evidence. | **No blocker. Implementation and environment evidence remain.** |
| CD-AB-C06 Human Message / AI Request separation | The current legacy route intentionally saves a human message then invokes the Gateway. The additive design keeps that route compatible and adds a Human Message Writer plus explicit AI command; no existing route must be weakened or reinterpreted before P1. | Delta A P1-P5: Provider 0/1 call, response-loss idempotency, Archive, AI OFF, tenant/actor negative cases. | **No blocker. Not-yet-implemented Delta A behavior.** |
| CD-AB-C07 Browser mic / codec / playback | No microphone, audio codec, or playback path exists in the Formal Closed implementation. Its absence is the declared Delta A feature gap, not an incompatibility in an existing Closed Contract. Secure-context and runtime-probe strategy is fixed without assuming a codec from the browser name. | Delta A P1-P5 / A-DC24: Desktop Edge, iPhone Safari and iPhone PWA recording, permission, interruption, playback, 390px and no offline audio cache. | **No blocker. Device evidence belongs to implementation Close.** |
| CD-AB-C08 Transcription / Temporary / Usage | No ASR or temporary-audio path exists today. The provider-neutral DTO, separate purpose, one-hour temporary boundary, fail-closed cleanup, unknown usage/cost, and no blind retry design fit the existing Gateway/Ledger without changing the text Provider contract. | Delta A P1-P5: fake Provider, TTL/cleanup, cancellation, late response, Usage Ledger and Transcript revisions. Release Verification: real provider/model/price/secret/retention and real ASR smoke. | **No blocker. Implementation and Release evidence remain.** |

## 3. Counted disposition

- **Pre-Gate Compatibility Blocker: 0**.
- **Moved to Delta A P1-P5 Verification: 6 compatibility records** — C01, C03, C05, C06, C07, C08.
- **Moved to Release Verification: 2 compatibility records have Release-only sub-evidence** — C05 deployed storage/tool/key/backup operation and C08 real transcription Provider/model/price/secret/retention/smoke. These two are also counted in P1-P5 because their application behavior must first be implemented and verified with isolated/fake infrastructure.
- **Resolved candidate before Gate: 1** — C02.
- **Not applicable to Delta A and carried to Delta B: 1** — C04.

The P1-P5 and Release counts are intentionally non-exclusive. They count the Compatibility records that contain work for each phase; they do not change an unimplemented feature to PASS.

## 4. A-G01 candidate decision

- Product blocker: **0**.
- Major compatibility issue in the existing Formal Closed implementation: **0**.
- Scope 11: **Formal Closed maintained**.
- **S11-CD-A-G01: OPEN candidate**.
- S11-CD-A-G01 remains **CLOSED** until the explicit Human + ChatGPT A-G01 Final Decision.

## 5. Evidence basis

- A-P0 Compatibility / Implementation Readiness Report: static compatibility inventory and phase plan.
- CD-AB-C02 Limited Corrective Delta Report: F01-F05 final PASS, isolated SQLite/MariaDB migration and regression evidence.
- Current corrective branch HEAD: Proposal Engine, canonical hash, Unit Writers, current Target authorization, and Scope 10 Notification authorization remain unweakened; C02 lineage relations are additive.
- No new runtime test was required for this evidence-only reclassification. Existing tests were not weakened, skipped, or recounted.
