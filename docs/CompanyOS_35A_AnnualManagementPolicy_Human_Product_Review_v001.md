# Company OS｜35A Annual Management Policy｜Human Product Review v001

Initial review package: 2026-10-03 JST

Final Human Review: 2026-10-05 JST

Binding Product HEAD: `1a4bb264a2484d6b12de137abb76452db6b8cb93`

Status: **HUMAN FINAL PASS / FORMAL CLOSE AUTHORIZED**

Automated evidence establishes technical behavior. Human Authority completed the final Product Review and accepted the experience and supporting technical/browser evidence.

## Review fixture

- One test Organization with periods for past, current, gap, and future.
- One active Owner who has `manage` but initially no body permission.
- Explicit approved viewer, Draft viewer, editor, and approver actors.
- One suspended/left actor for denial checks.
- Active and archived Groups, readable/unreadable Projects, and readable/unreadable Actions.
- Drafts for empty optional collections, long Japanese text, multiple Themes/Priorities, and multiple Department Statements.
- Desktop viewport (recommended 1440 px) and mobile viewport (390 px).
- Environment, exact SHA and Browser results are recorded in the Formal Closure Evidence.

## Human review matrix

| ID | Human action and expected experience | Automated support | Human status |
|---|---|---|---|
| H01 | Register a natural period name/start/end and read it back. | JST boundaries, overlap and version correction PASS | PASS |
| H02 | Distinguish current/future/past, a gap with no current, and current without approved policy. | Resolver and index-state tests PASS | PASS |
| H03 | Create a Draft for a selected period and recognize that it is not approved. | Draft≠Approve tests PASS | PASS |
| H04 | Save/read Purpose, Background and Policy; confirm optional vs required meaning. | Snapshot/validation tests PASS | PASS |
| H05 | Use zero and multiple Themes without forced placeholders. | Empty/many structure tests PASS | PASS |
| H06 | Understand Priority nesting under Theme with zero/multiple items. | Stable nested identity tests PASS | PASS |
| H07 | Distinguish Group from Department policy and manage multiple Statements. | Annual×Group uniqueness and statement validation PASS | PASS |
| H08 | Follow Project/Action relations without re-entering execution truth. | Existing reader delegation and typed relation tests PASS | PASS |
| H09 | Save without formalization; during revision confirm the old Approved version remains visible. | Immutable current revision tests PASS | PASS |
| H10 | Review the entire snapshot, sharing mode and viewer/Draft/editor/approver counts before approval. | Access-state snapshot binding and stale-stop test PASS | PASS |
| H11 | Distinguish current Draft, immutable old revisions, approval-time relation set and later relation versions. | Revision/relation version tests PASS | PASS |
| H12 | Open Source Evidence and resolve the cited exact revision/range, including Japanese Unicode text. | Exact citation/currentness tests PASS | PASS |

## Permission scenarios to review

1. Owner sees management controls but cannot read/edit/approve body without explicit grants.
2. “在籍中のスタッフ全員へ共有” affects Approved and History only.
3. “選んだ人へ共有” uses explicit approved-view grants.
4. Draft view, edit and approve remain distinct; edit/approve require Draft view.
5. Group and Position membership does not grant access.
6. A suspended/left membership loses access immediately.

## Final Human Review acceptance

Human Authority accepted the following on 2026-10-05 JST:

1. Editor Japanese labels and input journey.
2. Official-version sharing and policy-team separation.
3. Fiscal term and year labels.
4. Current / Planning / Past experience.
5. Theme / Priority / Department Policy ordering.
6. Department empty state and Draft preservation, accepting Technical / Browser Evidence.
7. Desktop / 390px Application Shell and vertical spacing.

Company Context Reader is separately Human Product Review PASS and Formal Closed.

## Upcoming / Planning Product Decision

- Planning is not a new Lifecycle or DB status.
- Approved / Upcoming is presented as `計画中｜承認済み・開始前`.
- Planning participants receive explicit existing Approved View access.
- Draft, Edit, Approve, Owner, Manage, Position and Group do not automatically grant Approved View.
- Draft is not mixed into the normal Reader.
- Unauthorized staff receive no Upcoming Annual selector, count, metadata or content disclosure.

## Publication Decision

- Period start and publication to all staff are separate.
- Becoming Effective does not change the sharing scope.
- Human changes the official audience at the management-policy presentation timing.
- Human-controlled Publication is the formal decision.
- Automatic start-date publication, publication scheduling and a dedicated pre-start audience are OUT-LATER.

## Human Review Result

- Human Review Pending: **0**
- Product Pending: **0**
- Human result: **PASS**

# **SCOPE 35A / HUMAN FINAL PASS**
