# Company OS｜35A Annual Management Policy｜Implementation Close Candidate v001

Date: 2026-10-03 JST

Status: **35A IMPLEMENTATION CLOSE CANDIDATE / HUMAN PRODUCT REVIEW**

## Identity and scope

| Item | Evidence |
|---|---|
| Branch | `ce-p1-realtime-corrective` |
| Work-start HEAD | `47eafdb6a37c0eac4abc560bca641416115ee54b` |
| MDC integration | `e1e5622273108c74a9202031132b52bf312784e6` |
| Exact implementation commit | `bc5234949a435c516b0184e9889f3c606f9cf240` |
| Exact implementation tree | `01a97ee8a353c0d8fbd518a66867c154398e3c8c` |
| Product Decisions | 35A-DE01–10 FIXED; 35A-RDP01–04 FIXED / RESOLVED |
| Product Decision Pending | **0** |

The three unrelated untracked files present before implementation remain unmodified and untracked. Production, Deploy, Production Migration, DNS/SSL, Scope 35 retrieval registration, Scope 36 response generation, and Scope 37 voice implementation were not performed.

## Phase result

### 35A-P1 — PASS

- Organization Management Period with JST current resolution, allowed gaps, non-overlap and immutable version history.
- One Annual aggregate per Organization×Period with independent editable period declaration.
- Flexible Theme, Priority, Department and Department Statement structures with stable public identity.
- Owner manage only; approved-view, Draft-view, edit and approve grants remain separate.
- Draft Save does not approve. Human approval creates an immutable complete snapshot/revision.
- Idempotent request ledger, stale checks, transaction rollback and sanitized Organization Audit.
- View, Draft edit, approval preview, history and revision screens remain distinct.

### 35A-P2 — PASS

- Typed/versioned manual relations with approval-time snapshot and post-approval relation history separated.
- Project and Action endpoints delegate to their existing reader contracts; relations grant no target permission.
- Current, period, historical-approved and explicit-Draft source modes remain distinct.
- Exact revision citation handles, Unicode code-point ranges, hashes, current membership reauthorization and audience intersection.
- Source/provider preparation only; no Scope 35 provider registration or external request.

### 35A-P3 — PASS / HUMAN UX REVIEW PENDING

- Company Home and application-shell integration; 17 named routes.
- Desktop and `max-width: 640px` responsive presentation supporting 390 px review.
- Empty optional lists, long Japanese content and many nested items tested.
- SQLite focused tests, MariaDB 10.11.19 isolated up/workflow/down, dependency regressions and full suite executed.
- Human H01–H12 package prepared separately; no automated Human PASS assigned.

## Verification result

| Verification | Result |
|---|---|
| 35A focused SQLite | **20 tests / 96 assertions PASS** |
| Release Hardening + 35A after count corrective | **23 tests / 123 assertions PASS** |
| Connected focused regression | **110 PASS / 962 assertions; 1 known baseline FAIL** |
| MariaDB | **10.11.19 / utf8mb4 / utf8mb4_unicode_ci PASS** |
| MariaDB migration | Full repository `migrate:fresh`, 35A workflow, latest two migration rollback, existing Organization/MDC table preservation PASS |
| PHP syntax | All 35A PHP/controller/model/service/migration/test files PASS |
| Blade | `view:cache` PASS |
| Routes | 17 Annual Management Policy routes present |
| Full Laravel suite | **742 PASS / 17 SKIP / 9 FAIL / 6,340 assertions** before the migration-count corrective |

Full-suite failure classification:

1. `CompanyNavigationTest::test_regular_login_ignores_a_stale_forbidden_intended_url`: one pre-existing, repeatedly documented baseline failure; exact isolated reproduction unchanged.
2. Seven `Ir1G2PreflightV2ExecutionTest` failures: the tests intentionally require the already-used corrective-2 production Evidence directory to be absent. It exists from the separately completed IR-1 Human operation and was not deleted or modified.
3. `ReleaseHardeningTest` expected repository migration count 110; 35A adds exactly two migrations. The expectation was corrected to 112 and the suite then passed with 35A focused tests.
4. Seventeen skips are the existing approved MariaDB RG02 profile and closed real-provider gate conditions.

No failure enters the new 35A behavior boundary.

## TV01–TV14

| TV | Result | Evidence / remaining boundary |
|---|---|---|
| TV01 | PASS | Active repo/HEAD/status/symbol binding; MDC formally integrated; separate Evidence document |
| TV02 | PASS | JST start/end/gap/overlap/correction/current fixture |
| TV03 | PASS | 0/many, long Japanese, stable identity, duplicate/cross-tenant rejection |
| TV04 | PASS | stale approval, idempotency, snapshot hash, fault rollback, immutable revision |
| TV05 | PASS | Owner no bypass, independent grants, all-active/explicit, lifecycle and tenant denies |
| TV06 | PASS | typed allowlist, Project/Action reader delegation, relation versions, body revision unchanged |
| TV07 | PASS | exact approved revision, Unicode range/hash, old citation after later approval |
| TV08 | PASS | current/period/historical/Draft modes and distinct authorization |
| TV09 | PASS (preparation) | audience intersection, membership epoch, JST cutoff and `requires_ai_policy`; real Scope 35 provider remains out of scope |
| TV10 | PASS | request operations, hashes, result IDs and sanitized audit; body/secret copy 0 |
| TV11 | READY FOR HUMAN | responsive/accessibility structure automated; browser screenshots and Human H01–H12 remain unreviewed |
| TV12 | PASS | disposable SQLite + MariaDB 10.11.19, named FK/indexes, up/down and cleanup |
| TV13 | PASS with known baselines | focused dependencies, full suite classification, 40 Themes×5 Priorities under 5 seconds; no SLA invented |
| TV14 | PASS (handoff preparation) | source namespace/modes/typed units/citation/currentness contract prepared; registry and live AI integration not implemented |

## Compatibility C01–C12

| C | Resolution |
|---|---|
| C01 | PASS — latest active local repository bound; unrelated changes preserved |
| C02 | PASS — additive Organization Management Period, independent from financial periods |
| C03 | PASS — additive Annual Draft/Approve aggregate; MDC save semantics unchanged |
| C04 | PASS — explicit approved/Draft/edit/approve grants |
| C05 | PASS — Annual-scoped typed adapter and existing Project/Action readers |
| C06 | PASS — exact current/period/historical/Draft selector and revision citation |
| C07 | PASS — all-active intersection and explicit viewers; Group/Position grant 0 |
| C08 | PASS — JST evaluated date and period version included in currentness binding |
| C09 | PASS — stable nodes, full snapshot and versioned relations; audit body copy 0 |
| C10 | PREPARED — third source contract is ready; Scope 35 registry addition remains later scope |
| C11 | PASS / HUMAN REVIEW — existing shell/MDC naming separation implemented; final experience review pending |
| C12 | PASS — additive schema, SQLite/MariaDB, connected/full regressions and Release boundary preserved |

## Done Condition DC01–DC20

| DC | Result |
|---|---|
| DC01 | PASS — free-name, start/end, non-overlap, short-period and version correction |
| DC02 | PASS — current/future/historical, gap and no fallback |
| DC03 | PASS — one logical Annual per Org×Period and flexible stable structure |
| DC04 | PASS — Department stable identity, Group reference and multiple Statements |
| DC05 | PASS — tenant/current membership and Owner non-bypass |
| DC06 | PASS — Approved vs Draft sharing separated |
| DC07 | PASS — explicit Human approver and full snapshot; automated approval 0 |
| DC08 | PASS — Save does not create formal revision |
| DC09 | PASS — immutable complete revision/history and prior period data |
| DC10 | PASS — optimistic stale checks, idempotency, transaction fault rollback |
| DC11 | PASS — manual relation separate from execution source of truth |
| DC12 | PASS — approval-time relation snapshot and later relation versions distinguished |
| DC13 | PASS — typed Source units and exact-revision citations |
| DC14 | PASS — current approved, period, historical and explicit Draft modes |
| DC15 | PREPARED — shared audience/current membership/AI-policy/date binding; live 35 integration later |
| DC16 | PREPARED — revision/period/relation/cutoff lineage fields and citation handle |
| DC17 | PASS — sanitized operation/audit tracking without body/secret copy |
| DC18 | READY FOR HUMAN — readable/responsive UI and Save/Approve separation implemented; Human review pending |
| DC19 | PASS — additive DB, SQLite/MariaDB, regression, bounded input and cleanup |
| DC20 | PREPARED — later 35–37 consumer handoff contract exists; fixed answers/provider calls 0 |

`PREPARED` is the approved 35A completion meaning for downstream integration items; it does not claim Scope 35–37 implementation.

## Product decisions retained

- 35A-RDP01 Option B: manage / approved view / Draft view / edit / approve remain separate; Owner, Group and Position do not bypass.
- 35A-RDP02 Option B: approval requires valid period and non-empty Policy; Purpose/Background optional; Theme/Priority/Department 0..N; Department 1..N Statements at approval.
- 35A-RDP03 Option B: only Organization periods resolve current; corrections are versioned/non-overlapping; gaps allowed; Annual declared period does not alter current resolution.
- 35A-RDP04 Option B: approval-time semantic relation set and later Human-confirmed relation versions are distinct; later edges do not create body revisions.
- UX clarification: “在籍中のスタッフ全員” and “選んだ人” control Approved/History only; they do not grant Draft/edit/approve/manage.

## Human and production boundary

- H01–H12: **all READY / all NOT REVIEWED**. See `CompanyOS_35A_AnnualManagementPolicy_Human_Product_Review_v001.md`.
- Production connection/mutation: 0.
- Production migration/deploy: 0.
- Local ordinary database used as fixture: no; SQLite test DB and GUID-scoped disposable MariaDB datadir only.
- Scope 35 provider registration/retrieval: 0.
- Scope 36 generated response: 0.
- Scope 37 voice implementation: 0.
- Existing MDC/CE/IR-1 formal states are not reopened or reclassified.

## Decision

The implementation satisfies the approved Product Boundary without a FIXED Product Decision change. Technical implementation and automated verification are complete. Human experience sign-off is deliberately outstanding.

# **35A IMPLEMENTATION CLOSE CANDIDATE / HUMAN PRODUCT REVIEW**
