# Company OS Scope 8 Implementation Report

- Date: 2026-09-25 JST
- Status: **Formal Closed**
- Baseline HEAD: `797b87e12d0f632dd2df27c0a1c93aa57e3a274b`
- Implementation HEAD: `a0312e98ba0162c5baf91862b5b5136b619b4b72`
- Formal Close approval: human + ChatGPT review completed on 2026-09-25 JST.
- Stop point: Scope 8 Formal Close completed. Normal-local Migration, Production, deploy, HOW, and Scope 9 remain outside this close.

## Implemented outcome

- Reused Project / Roadmap / Improvement / Task IDs and added an explicit `scope8.v1` execution contract.
- Added atomic Project + Owner creation, Purpose / Expected Outcome, four-state execution, optional dates, and optimistic version checks.
- Added individual multi-role membership, owner transfer, Project reviewer, leave history, Company / Group / Confidential read boundaries, and Group candidates that save only individual membership.
- Added light Roadmap / Action Theme, direct and Theme Action, mandatory single assignee and Done Condition, optional reviewer, review pending / confirm / reject / reopen, move/reorder, parent close confirmation, archive/reopen, and immutable actor history.
- Added View / Manage presentation with Desktop and 390px responsive layouts. Company Home now links to the Project / Action entrance.
- Added Snapshot format v2 with direct Actions and Scope 8 Action responsibility fields. Legacy restore fails closed when it could delete new data.
- Connected a separate `project-action.v1` adapter to the existing Approval → Apply → Result → Undo safety engine. Scope 1 `project-plan.v1` stays unchanged.
- Added safe AI Context projection for Purpose, Expected Outcome, direct Actions, Done Condition, assignee, reviewer, due date, and status.
- Legacy mutation routes reject Scope 8 rows, preventing parallel canonical writers.

## Verified delta and boundaries

- Product / Permission / Tenant / Security contracts follow the v002 Masters; no new Product decision was introduced.
- Read projection excludes internal notes, AI history, and management payload for non-members.
- Company / Group readers do not become Project participants and receive no write permission.
- Confidential projects are available only to active explicit participants; non-authorized direct/manage routes fail closed.
- AI assignee/reviewer validation requires active explicit execution members both before approval and in the human writer path.
- Normal-local SQLite was not migrated. SHA-256 before/after: `1658C8B87ECFD4258EE225C8891655387539FBCC5ADFDD60E6B1B3EBBBD2496B`.
- Production was not connected or changed. No deploy, HOW, or Scope 9 work was performed.

## Migration and data evidence

The normal-local SQLite file was copied to an isolated Clone. The additive migration was applied and rolled back only on that Clone.

| Item | Before | After migration | After rollback |
|---|---:|---:|---:|
| users | 4 | 4 | 4 |
| organizations | 4 | 4 | 4 |
| organization_users | 5 | 5 | 5 |
| workspaces | 5 | 5 | 5 |
| workspace_members | 6 | 6 | 6 |
| projects | 17 | 17 | 17 |
| project_members | 17 | 17 | 17 |
| roadmaps | 36 | 36 | 36 |
| improvements | 51 | 51 | 51 |
| tasks | 51 | 51 | 51 |
| Scope 8 new tables | 0 | 3 | 0 |

- Relation hash remained `29b2df3ec5a6f38ad47d2cdf06f424533f4131233d572fb0323e74f0ef085059`.
- Actor hash remained `a8cc50945c1c5bd4bfb006e51908a67e134850e92838fc7309eb11b1baa1ca35`.
- Initial SQLite rollback exposed index/drop ordering; the migration was corrected and apply → rollback was rerun successfully.

## MariaDB 10.11 evidence

- Official MariaDB 10.11.19 Windows archive SHA-256 matched RG02: `398EA30E5036010BBEBE01D2B1804280424DCC2626E36D8E95155C04D25A0490`.
- Isolated loopback `127.0.0.1:13328`, dedicated datadir/schema; XAMPP 3306 and Production were untouched.
- Profile: `10.11.19-MariaDB`, InnoDB, utf8mb4 / utf8mb4_unicode_ci, REPEATABLE-READ, Event Scheduler OFF.
- All repository migrations, including the Scope 8 DDL/FK/indexes, applied successfully.
- Scope 8 focused lifecycle/Permission/AI suite on real MariaDB: **11 tests / 46 assertions / failures 0**.
- RG02 lock/deadlock/timeout/retry evidence is reused; Scope 8 writers use Project-row `lockForUpdate`, optimistic version checks, and bounded transaction retry. Owner stale-write and AI stale/idempotency paths were reverified.
- Dedicated schema/process/datadir/archive were cleaned up; no listener remains (TIME_WAIT only immediately after shutdown).

## Test, build, and Browser evidence

- Scope 8 focused final: **12 tests / 53 assertions / failures 0**.
- AI v1 + new adapter + member boundary related regression: **77 tests / 482 assertions / failures 0**.
- Project legacy + Product Organization + Membership lifecycle regression: **55 tests / 469 assertions / failures 0**.
- Normal SQLite full suite excluding the separately guarded RG02 MariaDB-only class: **490 tests / 4,045 assertions / failures 0**.
- A raw all-class run additionally showed 489 passes / 4,072 assertions and 16 expected RG02 guard failures because `RG02_ALLOW` and its removed dedicated server were absent; these are environment guards, not regressions.
- Frontend build: Vite 7.3.6, 58 modules, success.
- Blade compile, route inventory, PHP syntax, and `git diff --check`: success.
- Browser: isolated SQLite fixture, loopback server, real Chrome. Desktop read/manage and 390px read were checked; direct Action, Theme, participant/visibility/completion management, non-member manage 403, and no mobile horizontal overflow passed.
- Desktop PNG SHA-256: `76EC957C325CFB415FAF88C145502C5A94147846F6D4B9941E9D7A8DC2060968`.
- 390px PNG SHA-256: `F08E26787F25244A111B83EEFAFCF0C85DAE4E4C017E9234B83C7B7175BE5A0A`.

## S8-DC-01〜24 Done review

| DC | Result | Evidence |
|---|---|---|
| 01 | Done | P0 audit, source hashes, baseline/diff mapping |
| 02 | Done | atomic minimum Project + Owner test and rollback-negative path |
| 03 | Done | model/writer/UI, required Purpose/Outcome and four states |
| 04 | Done | Roadmap/Theme writer and hierarchy tests; legacy rows unchanged |
| 05 | Done | direct/Theme Action; missing fields and invalid tenant/member rejection |
| 06 | Done | assignee completion, pending, confirm, reject, invalidation, reopen tests |
| 07 | Done | optional Project reviewer request/confirm path |
| 08 | Done | Group candidates remain individuals; no automatic participant/write sync; leave history |
| 09 | Done | multi-role assignments, one owner transfer, stale second transfer rejection |
| 10 | Done | Company/Group/Confidential positive/negative read tests and 404/403 routes |
| 11 | Done | explicit read projection and Browser non-member evidence |
| 12 | Done | Product Organization / Membership lifecycle 55-test related regression |
| 13 | Done | move preserves public ID/due date and records history; cross-project query guard |
| 14 | Done | confirmed version required; parent close leaves child Action state unchanged |
| 15 | Done | archive/reopen and member leave preserve ID/actor/history |
| 16 | Done | Snapshot v2 direct/nested Action fields; legacy restore fail-closed |
| 17 | Done | separate four-entity adapter; original v1 allowlist assertion |
| 18 | Done | existing atomic/idempotent engine regression plus Scope 8 Apply/Item Result integration |
| 19 | Done | dynamic Snapshot/Undo adapter; existing update-only/create-mixed/after-edit regression |
| 20 | Done | existing category/Tenant/Key/limit guard plus explicit Action member validation |
| 21 | Done | canonical writer inventory, optimistic versions, ProjectExecutionEvent with public ID and actor |
| 22 | Done | isolated Clone counts/hash/rollback evidence |
| 23 | Done | SQLite apply/rollback and real MariaDB 10.11 DDL + focused writer tests; RG02 lock/retry reuse |
| 24 | Done | Desktop/390 Browser evidence, permission denial, build, final report |

Result: **Done 24 / Conditional 0 / Not Done 0**.

## Formal Close

- Decision: **Scope 8 Formal Closed**.
- Code Complete and S8-DC-01 through S8-DC-24 were approved by human + ChatGPT review.
- S8-C01 / S8-C02: not detected.
- Scope 1 through 7, PUX, and Account separation Closed Contracts remain unchanged.
- Scope 1 AI Proposal Contract remains unchanged.
- Business Data, IDs, relations, and actor references are preserved.
- SQLite, MariaDB 10.11.x, Desktop, 390px, and Permission-negative evidence was accepted.
- Master Update: not required. Master v141 / v037 remain authoritative.
- This close does not authorize normal-local Migration, Production connection or Migration, deploy, HOW, or Scope 9.

