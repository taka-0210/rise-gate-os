# Company OS 35A Upcoming Annual Policy Experience Code Complete v001

Date: 2026-10-05 JST

Status: **35A UPCOMING EXPERIENCE / CODE COMPLETE / HUMAN REVIEW WAITING**

## 1. Identity / Boundary

| Item | Evidence |
|---|---|
| Branch | `ce-p1-realtime-corrective` |
| Implementation commit | `1874ce2ec98efdb27848aee2015e4af0a6c2870d` |
| Implementation tree | `24223ce048dea427df6250ebee65add8b285270b` |
| Timezone | `Asia/Tokyo` |
| Schema / Migration change | 0 |
| Permission Contract change | 0 |
| Production / Deploy / Scope 35 or 34 | 0 |

The named source `CompanyOS_35A_Upcoming_Annual_Policy_Experience_Product_Design_v001.md` was not present in the repository or the available Codex attachments at implementation time. The explicit Human Product Decisions supplied on 2026-10-05 are therefore the binding implementation input. The source document remains required for final document-to-code traceability, but is not a code correctness dependency.

## 2. Product Decision Binding

- Approved / Upcoming is presented in the Reader as `計画中`.
- `計画中・開始前` and `承認済み` are always shown together for the Upcoming item.
- Planning is a Reader experience only. Approval remains Approved and lifecycle remains Upcoming.
- Current is the default. Upcoming requires explicit Human selection. Ended is grouped as `過年度`.
- Draft content is excluded from the Reader. Only the immutable approved Revision is rendered.
- Existing Approved View authorization is reused. Draft, Edit, Approve, Owner, Manage, Position, and Group do not grant Reader access.
- Unauthorized staff receive no selector, count, label, metadata, or content disclosure. Direct selection returns 404.
- Reaching the start date changes lifecycle evaluation only. It does not change the sharing scope or publish to all staff.
- Sharing guidance states that the selected audience applies after approval and before the start date, and that planning participants should use explicit sharing.

## 3. Implementation Delta

- Added a Reader-only Current / Planning / Past presentation map to `AnnualPolicyReaderResolver`.
- Grouped the selector into `現在有効`, `計画中`, and `過年度` without changing the underlying lifecycle.
- Added the Chapter 04 Upcoming explanation and start-date language.
- Added publication guidance to the Annual Management Policy sharing screen.
- Added focused permission, non-disclosure, Draft exclusion, JST boundary, Desktop, and 390px regressions.

## 4. Verification

| Verification | Result |
|---|---|
| PHP / JavaScript syntax | PASS |
| `git diff --check` | PASS |
| Focused Reader + Annual Policy tests | **31 tests / 196 assertions PASS** |
| Browser Desktop | **1440 x 1000 PASS** |
| Browser Mobile | **390 x 844 PASS** |
| Connected existing narrow / resize checks | **320 x 800 / 200% PASS** |
| Current / Planning / Past | PASS |
| Approved View-only access | PASS |
| Unauthorized non-disclosure | PASS |
| Draft non-mixing | PASS |
| Start-date publication non-escalation | PASS |
| Desktop Side TOC / Mobile TOC B | PASS |
| Horizontal overflow | 0 |
| HTTP 5xx | 0 |
| External requests | 0 |

Full repository suite observation:

- 755 PASS
- 17 SKIP
- 8 FAIL
- 6,451 assertions

The eight failures are outside this Corrective: one pre-existing Company Navigation stale-intended-URL case and seven IR1 G2 execution-evidence directory precondition cases. The Reader / Annual Policy focused and browser boundaries have zero failures.

## 5. Preserved Contracts

- No new DB status, lifecycle, schema, or migration.
- No Owner or Manage bypass.
- No automatic audience grant.
- No start-date-triggered publication.
- No mutation of approved Revision or Snapshot.
- Reader Visual, Motion, Brand Visual, Desktop TOC, Mobile TOC B, Canvas 1440, and Reading 720 remain unchanged.
- No Production connection or deployment.

# **35A UPCOMING EXPERIENCE / CODE COMPLETE / HUMAN REVIEW WAITING**
