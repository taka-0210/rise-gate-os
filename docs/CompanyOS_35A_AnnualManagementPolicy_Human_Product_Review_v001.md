# Company OS｜35A Annual Management Policy｜Human Product Review v001

Date: 2026-10-03 JST

Binding: implementation commit `bc5234949a435c516b0184e9889f3c606f9cf240`

Status: **READY FOR HUMAN REVIEW / H01–H12 NOT YET HUMAN-PASSED**

Automated tests establish technical behavior only. They do not grant Human PASS to any item below.

## Review fixture

- One test Organization with periods for past, current, gap, and future.
- One active Owner who has `manage` but initially no body permission.
- Explicit approved viewer, Draft viewer, editor, and approver actors.
- One suspended/left actor for denial checks.
- Active and archived Groups, readable/unreadable Projects, and readable/unreadable Actions.
- Drafts for empty optional collections, long Japanese text, multiple Themes/Priorities, and multiple Department Statements.
- Desktop viewport (recommended 1440 px) and mobile viewport (390 px).
- Environment and exact SHA must be recorded at review time. Screenshots and Human name/date remain pending until the actual review.

## Human review matrix

| ID | Human action and expected experience | Automated support | Human status |
|---|---|---|---|
| H01 | Register a natural period name/start/end and read it back. | JST boundaries, overlap and version correction PASS | NOT REVIEWED |
| H02 | Distinguish current/future/past, a gap with no current, and current without approved policy. | Resolver and index-state tests PASS | NOT REVIEWED |
| H03 | Create a Draft for a selected period and recognize that it is not approved. | Draft≠Approve tests PASS | NOT REVIEWED |
| H04 | Save/read Purpose, Background and Policy; confirm optional vs required meaning. | Snapshot/validation tests PASS | NOT REVIEWED |
| H05 | Use zero and multiple Themes without forced placeholders. | Empty/many structure tests PASS | NOT REVIEWED |
| H06 | Understand Priority nesting under Theme with zero/multiple items. | Stable nested identity tests PASS | NOT REVIEWED |
| H07 | Distinguish Group from Department policy and manage multiple Statements. | Annual×Group uniqueness and statement validation PASS | NOT REVIEWED |
| H08 | Follow Project/Action relations without re-entering execution truth. | Existing reader delegation and typed relation tests PASS | NOT REVIEWED |
| H09 | Save without formalization; during revision confirm the old Approved version remains visible. | Immutable current revision tests PASS | NOT REVIEWED |
| H10 | Review the entire snapshot, sharing mode and viewer/Draft/editor/approver counts before approval. | Access-state snapshot binding and stale-stop test PASS | NOT REVIEWED |
| H11 | Distinguish current Draft, immutable old revisions, approval-time relation set and later relation versions. | Revision/relation version tests PASS | NOT REVIEWED |
| H12 | Open Source Evidence and resolve the cited exact revision/range, including Japanese Unicode text. | Exact citation/currentness tests PASS | NOT REVIEWED |

## Permission scenarios to review

1. Owner sees management controls but cannot read/edit/approve body without explicit grants.
2. “在籍中のスタッフ全員へ共有” affects Approved and History only.
3. “選んだ人へ共有” uses explicit approved-view grants.
4. Draft view, edit and approve remain distinct; edit/approve require Draft view.
5. Group and Position membership does not grant access.
6. A suspended/left membership loses access immediately.

## Evidence capture template

For each H item, record:

- reviewer and JST timestamp;
- implementation SHA and environment;
- actor capability and Organization fixture;
- viewport (`desktop` or `390px`);
- exact route/action;
- screenshot reference before/after when applicable;
- observed result;
- `PASS`, `STOP`, or `CHANGE REQUEST` entered by Human.

No screenshot or Human outcome is fabricated in this package. Production, Deploy, Production Migration, Scope 35 retrieval, Scope 36 response generation, and Scope 37 voice are outside this review.
