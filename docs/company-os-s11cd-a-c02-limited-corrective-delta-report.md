# Company OS | S11-CD-A
# CD-AB-C02 Limited Corrective Delta Report

**Verification date: 2026-09-27 JST**

## 1. Decision boundary

- Scope 11 AI Common Entry remains **Formal Closed**. It was not reopened.
- This work is limited to **CD-AB-C02 Source lineage**.
- Delta A P1-P5, Scope 12, Production, Deploy, normal local DB, and IR-1 were not changed.
- A-G01 remains **CLOSED** pending Human + ChatGPT Corrective Final Review.
- Base focused-evidence HEAD: `e540517edc07125f5f97091c86f66b684215f7e9`
- Scope 11 Formal Close commit: `5d3ef34c15a92db55e4f8aef907b1f04ec136b2d`
- Corrective implementation commit: `2a270487edbc2a78030cc8d9a307d816cab2bd8f`

## 2. Corrective implementation

### Immutable Source Revision

- Kept `ai_common_sources` as the mutable current selection.
- Added immutable `ai_common_source_revisions` snapshots containing resource identity/version, selector, projection, Organization/Resource policy versions, membership epoch, credential generation, access fingerprint, opaque handle, reason, and selected time.
- Reselect creates a new revision and advances only `current_revision_id`; it does not rewrite a revision used by an earlier answer.
- Model update/delete operations on a Source Revision fail closed.
- Existing rows are not guessed or backfilled. A legacy selection must be explicitly reselected before new Provider use.

### Transitive Source Lineage

- Added `ai_common_message_source_revisions` and a message lineage version.
- A new assistant answer records the union of directly selected revisions and authorized revisions inherited from prior assistant context.
- Reader, Citation display, next-turn context, and source-derived Proposal use reauthorize the exact immutable revisions.
- Legacy sourced history with unknown transitive lineage is hidden from the first unknown sourced answer onward; no inferred lineage is created.

### Proposal boundary

- Added optional lineage metadata to the existing Common Handoff relation and `ai_common_proposal_source_revisions`.
- Source-derived Proposal display, approval, apply, and undo reauthorize the exact source message lineage.
- Existing Proposal Contract, canonical hash, approval levels, Atomic Apply, Idempotency, Undo, and Unit Writers are unchanged.
- No second Proposal Engine was introduced.

### Attempt and response authorization

- Every Provider attempt rebuilds messages and source payload immediately before send and rechecks active membership, credential generation, Organization AI Policy, Resource AI Policy, resource revision, projection, and access fingerprint.
- A retry cannot reuse the earlier attempt payload.
- Provider I/O holds no database transaction or row lock.
- Before publishing a returned answer, authorization and direct-selection-to-revision identity are checked again.
- Provider acceptance remains distinguishable from application publication; an answer obtained after access loss is not stored or shown.

## 3. F01-F05 final evidence

| Scenario | Reproduction condition | Required assertions | Result |
| --- | --- | --- | --- |
| F01 Transitive dependency | Source A -> R1, then R1 history -> R2 without direct reselection; revoke Project membership | R1/R2 share exact immutable revision; both hidden after loss; no assistant history reinjection; source-derived Proposal hidden and approval rejected | **PASS** |
| F02 Revision/reselect | R1 uses Project snapshot v1; Writer changes projection; same selection is reselected | One selection, two immutable revisions/handles/fingerprints; R1 remains linked to v1; v1 projection unchanged; R1 hidden as stale; revision mutation rejected | **PASS** |
| F03 Policy before retry | Attempt 1 disables current Resource AI Policy then returns `provider_unavailable` | Attempt 2 authorization fails before Provider; Provider call count 1; only attempt 1 failed ledger exists; no assistant answer | **PASS** |
| F04 Loss during Provider I/O | Membership becomes left and epoch changes while Provider processes | Provider response is accepted by Provider but not published; no assistant answer; response boundary remains fail closed | **PASS** |
| F05 Reselect during Provider I/O | Same selection is reselected after send and before response publication | Current revision changes; in-flight answer is not rebound to the new revision and is not published | **PASS** |

Final focused SQLite result: **5 PASS / 0 FAIL / 39 assertions**.

## 4. Migration evidence

Additive migration:

- `ai_common_source_revisions`
- `ai_common_message_source_revisions`
- `ai_common_proposal_source_revisions`
- `ai_common_sources.current_revision_id`
- `ai_common_messages.source_lineage_version`
- `ai_common_handoff_relations.source_message_id / source_lineage_version`

No existing Source, Message, Proposal, or Handoff ID or meaning was changed. Rollback refuses destructive removal once lineage history exists.

### Isolated SQLite

- Empty isolated file only; normal local DB was not used.
- All **98 migrations** applied.
- Corrective migration rollback: PASS.
- Corrective migration reapply: PASS.
- `PRAGMA integrity_check`: `ok`.
- Temporary DB removed.

### Isolated MariaDB 10.11

- MariaDB `10.11.19-MariaDB`, InnoDB, `utf8mb4_unicode_ci`.
- Dedicated loopback process `127.0.0.1:13349`, dedicated copied datadir, synthetic database `co_s11c02_lineage`.
- All **98 migrations** applied; corrective migration rollback/reapply: PASS.
- FK/index/JSON DDL confirmed. Explicit short FK names avoid MariaDB's identifier-length limit without changing the contract.
- F01-F05: **5 PASS / 0 FAIL / 36 assertions**.
- Synthetic database, process, listener, and dedicated datadir removed; listener remaining: none.

## 5. Regression evidence

- Scope 11 complete suite after final formatting: **23 PASS / 0 FAIL / 119 assertions**.
- AI/Proposal/S8/S9/S10/Capture/Business Domain impact suite: **191 PASS / 3 FAIL / 1,342 assertions**.
  - The three failures are the previously recorded Scope 9 current-date fixture debt.
  - Scope 9 exact AI payload cases passed.
- Normal isolated SQLite full suite: **560 PASS / 5 FAIL / 16 SKIP / 4,399 assertions**.
  - Scope 9 current-date fixtures: 3 known baseline failures.
  - CompanyNavigation stale intended URL: 1 known baseline failure.
  - ReleaseHardening R0 repository migration count: 1 expected fixed-RC delta (`95` fixed vs current repository `98`).
  - The 16 MariaDB RG02 cases remain separated reasoned skips and are not counted as PASS.
- Corrective-derived unexplained failures: **0**.
- Pint on all changed PHP files: PASS.

No expectations were weakened, no test was deleted, and no skip was added.

## 6. Contract and gate assessment

- CD-AB-C02: **Resolved candidate**.
- Additional Product Decision: **not required**.
- Existing Scope 11 Product/Permission/Tenant/Privacy Contract: unchanged.
- C01 and C03-C08: no new occurrence or required scope expansion detected.
- Scope 11: **Formal Closed maintained**.
- A-G01: **OPEN candidate on the C02 condition**, but remains **CLOSED** until Human + ChatGPT review.
- Delta A P1-P5: not started.
- IR-1 RC, Artifact, Manifest, Test, Gate, Track A/B: unchanged.
- Production, Deploy, normal local DB: untouched.

