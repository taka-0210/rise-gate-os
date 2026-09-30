# Company OS｜Management Design Core P1 Implementation Evidence v001

- Evidence date: 2026-10-01 JST
- Scope: `MDC-P1 Foundation + Text Experience`
- Gate: `MDC-G01 = OPEN`
- Recommended decision: **P1 CLOSE CANDIDATE**
- Review state: **Human + ChatGPT Review required**
- P2: **NOT STARTED**

## 1｜Product Contract

The implementation was checked against the complete MDC-P1 instructions and the latest authoritative Master:

- `CompanyOS_Ver1_要件仕様書_v051_Management_Design_Core_P0_TP01_G01_Open.xlsx`
- read-only verification copy SHA-256: `B267DD56A8DFF52492B84B23FDBA2C4C801250B38B7C7190E155F17934099940`
- `MDC-PD01〜08`: FIXED, unchanged
- `MDC-PP01`: FIXED, unchanged
- `MDC-RDP01`: Option B APPROVED / RESOLVED, unchanged
- `MDC-RDP02`: Option B APPROVED / RESOLVED, unchanged
- `MDC-RDP03`: Option A APPROVED / RESOLVED, unchanged
- Product Decision Pending: `0`
- Compatibility Blocker at start: `0`

P1 IN was kept to the common text foundation and text experience:

- fixed item types: Philosophy / Vision / Policy
- Organization boundary and stable IDs
- free `0..N` Sections and ordering
- optional overall/Section explanations shared by all fixed types while keeping official wording separate
- immutable Revision, one current official, optional change reason
- Archive / Reopen
- View / Edit / Manage Permission separation
- operation idempotency, optimistic concurrency, transaction rollback and safe audit
- Philosophy ROOT / Vision FUTURE / Policy DIRECTION read experiences
- View / Edit / History journey and Company Context entry

P1 OUT remains out:

- image asset/version/delivery/gallery
- Business Domain relation
- MDC AI READ, historical AI READ, Source / Citation and shared permission intersection
- MDC AI proposal/write/apply
- plan, KPI, progress, due date or task semantics
- motion/autoplay
- destructive delete or migration

## 2｜Repository

- verified base SHA: `b952bce121b67b82afde2a22d5a3d4294ebb1ce5`
- branch: `mdc-core-philosophy-vision-policy`
- independent worktree: `C:\xampp\htdocs\rise-gate-os-mdc`
- implementation commit: `c98a774c25c81553005e1e81248c57fb5940880e`
- CE worktree and history: unchanged
- Public Push: `0`

Changed paths are limited to:

- `database/migrations/2026_10_01_000001_create_management_design_core_p1.php`
- `database/migrations/2026_10_01_000002_add_explanations_to_management_design_core_p1.php`
- `app/Models/ManagementDesign*`
- `app/Services/ManagementDesign/*`
- `app/Http/Controllers/ManagementDesignController.php`
- Company Home controller and Company Context navigation integration
- `resources/views/management-design/*`
- `routes/web.php`
- MDC P1 Feature, Browser and Browser setup tests
- Release Hardening migration ledger expectation (`108 → 110`)

## 3｜Schema / Migration

The migrations are additive. The foundation migration creates six tables, and the Human Visual Review corrective adds only nullable explanation columns. Neither migration updates, deletes or fabricates content in existing records:

1. `management_design_access_settings`
2. `management_design_grants`
3. `management_design_items`
4. `management_design_sections`
5. `management_design_operations`
6. `management_design_revisions`

Explanation corrective:

- `management_design_items.statement_explanation`: nullable long text
- `management_design_sections.explanation`: nullable long text
- Philosophy / Vision / Policy use the same Technical Data Model capability.
- The fields remain optional for every type.
- Existing records receive `null`; no inferred or fabricated explanation is backfilled.

Isolated SQLite verification:

- up: PASS
- rerun/no-op: PASS
- step-down: PASS
- existing schema preserved after down: PASS
- reapply: PASS
- migration repository count: `110`
- MDC table count after reapply: `6`
- Section FK count: `3`
- Item index count: `4`
- unique organization + item type and operation request constraints: PASS in Feature tests
- normal local DB migration: `0`
- Production DB migration: `0`

No P1 behavior depends on a SQLite-only query or trigger. The migration uses Laravel portable FK, unique, index, boolean, JSON and text primitives. A dedicated isolated MariaDB profile was not introduced or used because no engine-specific semantic branch was required; real MariaDB/Production application remains a separate gate.

Human Visual Review isolated DB application:

- pre-migration backup: SHA-matched copy created before application
- migration ledger: `109 → 110`
- Item / Section / Revision counts before and after: `2 / 5 / 5`
- new overall / Section explanation columns: present and nullable
- non-null explanations after migration: `0 / 0` (no backfill)
- normal local DB / Production DB application: `0 / 0`
- loopback Human Review runtime restored at `http://127.0.0.1:8461`

## 4｜Foundation

### Item / current official

- Exactly one logical Item per Organization + fixed type.
- Types outside `philosophy`, `vision`, `policy` fail closed.
- One current mutable projection is linked to an immutable current Revision.
- Official save increments the optimistic version.

### Section

- Supports `0..N` freely named Sections.
- Stable ULID is retained across edits and reorder.
- Submitted order is persisted.
- Removed Sections are archived rather than destructively deleted.
- Cross-item and unknown Section IDs fail closed.
- Vision alone accepts optional whole/Section Horizon.
- Every fixed type accepts optional Section explanation through the shared schema.

### Revision / lifecycle

- Every official save, Archive and Reopen creates a new immutable snapshot.
- Snapshot schema v2 contains the overall explanation and complete ordered Section set including optional explanations.
- Historical schema v1 Revisions remain readable without rewriting or backfill.
- Old Revisions remain readable and are not rewritten by correction.
- Archive retains stable ID, current text and History.
- Reopen uses the same stable ID.
- Request IDs are idempotent; reuse with a different payload is rejected.
- Stale versions and injected rollback checkpoints fail without partial current value, Revision, operation or audit writes.

## 5｜Permission

- `View`, `Edit` and `Manage Permission` are distinct server-side checks.
- Owner is the Permission manager only; Owner has no automatic content View/Edit bypass.
- Organization Role, Group and Position do not derive MDC content View/Edit.
- View supports `all_active_staff` or explicit viewer grant.
- Edit always requires current View plus explicit editor grant.
- Permission UI prevents editor-without-view; the service independently rejects it.
- Global inactive, suspended and left users fail current membership reauthorization.
- Permission updates do not create content Revisions or change text.
- Unauthorized and cross-Organization requests are rejected before content, Section count, History or Revision metadata is loaded.

## 6｜Text Experience

### Philosophy / ROOT

- quiet, centered root statement
- restrained metadata and secondary Edit/History actions
- narrow reading width and breathing space between Sections
- `0 Section` remains complete

### Vision / FUTURE

- future statement is primary
- optional Horizon is subordinate information only
- Horizon has no deadline, KPI, progress or expiry behavior
- image-free composition is complete in P1

### Policy / DIRECTION

- ordered Section composition supports scanning judgment direction
- no Task, due, progress or completion semantics
- Policy is not converted into an Action list

### View / Edit / History

- Company Home → MDC directory → fixed type read view
- Edit is a separate secondary action and returns to read view after official save
- History and immutable Revision are separate read views
- long text over 240 characters uses a reading-size typography profile rather than rendering the full body as giant display text
- mobile layout collapses grids without horizontal overflow
- no images and no motion were added

### Explanation capability

- The need was discovered through Philosophy, but the Technical Data Model is not Philosophy-specific.
- Philosophy / Vision / Policy may all use optional overall and Section explanations.
- Official `Statement` / `Section Statement` remain distinct from their explanations and retain primary visual weight.
- Presentation labels are configured by type: Philosophy explains meaning, Vision explains the future it depicts, and Policy explains decision background/intent.
- Type-specific labels and display strength can evolve in the Presentation layer without changing the shared storage or Revision contract.
- Explanation remains optional; an Item and its Sections are valid without it.
- This creates richer future Company Context without implementing or pre-authorizing P2 AI READ.

## 7｜Verification

### Focused

- `ManagementDesignCoreP1Test`: **13 passed / 125 assertions**
- post-corrective MDC + ReleaseHardening: **17 passed / 154 assertions**
- PHP syntax: PASS
- Node Browser script syntax: PASS
- Pint: PASS
- `git diff --check`: PASS

Covered contracts include Organization isolation, fixed types, logical uniqueness, 0/many Sections, order, long Japanese, immutable old Revision, one current official, optional reason, shared optional explanations for all types, type-specific explanation presentation, stale rejection, foreign Section rejection, idempotency, transaction rollback, Owner non-bypass, explicit/all-staff grants, membership lifecycle, archive/reopen and Horizon type rejection.

### Browser / responsive / repeatability

After the long-text corrective, the full Browser Journey passed in **2 independent SQLite sessions (2/2)**:

- login and Company Home entry
- three fixed type journeys
- Philosophy ROOT / Vision FUTURE / Policy DIRECTION
- long Japanese and continuous Section additions
- History / immutable Revision
- stale update fail-closed
- Archive / Reopen
- desktop `1440 × 1000`
- mobile `390 × 844`
- no horizontal overflow
- keyboard focus to Add Section
- HTTP 5xx: `0`
- external requests: `0`
- each loopback server stopped after verification

The Explanation corrective then passed a fresh isolated Browser Journey:

- overall and Section explanations: visible in current View
- immutable History/Revision explanation: visible
- desktop `1440 × 1000`: PASS
- mobile `390 × 844`: PASS
- `3200px` zoom-out equivalent width assertion: PASS
- HTTP 5xx: `0`
- external requests: `0`
- `desktop-explanation-presentation.png` SHA-256: `4EC53609D8F4FF948722252149922B08A768964A41873995F176CD3168863A44`
- `mobile-explanation-presentation.png` SHA-256: `83EA3153E39A96BC5B3464DA278118F6652E0CEC96D33C75354E88CBB57A0C0E`

Final screenshot artifacts (gitignored local Evidence):

- `storage/app/mdc-p1-evidence/desktop-final.png`, `1440 × 3582`, SHA-256 `586036964B53523093F28A109DC19E1686458F2BE83896D998EA66BAAA1D0337`
- `storage/app/mdc-p1-evidence/mobile-390-final.png`, `390 × 1322`, SHA-256 `B436FCCB6580CC61C0F134C4C1E44CDF3DB1EB3217B4CAE8670D86AF9B99C23A`

### Regression

Related regression run:

- **104 passed / 1091 assertions**
- one P1-I real Provider gate skip
- one known CompanyNavigation failure

Latest full suite after the Explanation corrective:

- **684 passed / 5615 assertions**
- **17 skipped** (closed Provider/MariaDB gates)
- **1 known failure**: `CompanyNavigationTest::test_regular_login_ignores_a_stale_forbidden_intended_url`
- MDC failures: `0`
- the remaining failure is the same scope-external baseline reproduced before the corrective

Full suite run before the migration ledger expectation corrective:

- **681 passed / 5583 assertions**
- **17 skipped** (closed Provider/MariaDB gates)
- two failures:
  1. existing `CompanyNavigationTest::test_regular_login_ignores_a_stale_forbidden_intended_url`
  2. ReleaseHardening migration repository count expected `108` but correctly became `109`

Corrective:

- ReleaseHardening ledger expectation was updated to `109`.
- `ReleaseHardeningTest`: **4 passed / 29 assertions** after corrective.
- The remaining CompanyNavigation failure was reproduced unchanged on the exact base worktree, so it is a pre-existing scope-external baseline and not an MDC regression.

Frontend production build:

- Vite `7.3.6`: PASS
- modules transformed: `58`

## 8｜Security / Privacy

- Tenant/Organization is included in every Item, permission, grant and operation lookup.
- Current user and membership state are rechecked server-side.
- Content is not loaded for unauthorized entries.
- Owner bypass is absent.
- Audit stores actor, operation, stable ID, type, revision/version/status, request/operation IDs and grant counts.
- Audit does not copy statement, Section body, Horizon or change reason.
- Audit does not copy overall or Section explanations.
- Revision content remains protected by current View permission.
- No Secret, credential, `.env` value or external Provider payload was added to source/Evidence.
- External Service and Provider calls: `0`

## 9｜Non-change Evidence

- CE P1-I remains `INCONCLUSIVE / Technical Verification Required`.
- CE P1-J remains `FIRST E2E HUMAN PASS ESTABLISHED / STABILITY VERIFICATION REQUIRED`.
- Realtime Relay, Deepgram, Transcript, Provider Session and Source Range were not changed or imported into MDC.
- Master Excel v051: unchanged.
- PPT v155: unchanged.
- normal local DB: unchanged.
- Production DB / Storage / Credential: unchanged.
- Public Port / Tunnel / Firewall: unchanged.
- Deploy / Public Release / Public Push: `0 / 0 / 0`.

## 10｜Corrective Log

1. Independent worktree originally referenced the CE worktree Composer autoload through a Junction. It was replaced by an MDC-local vendor copy; CE runtime files were not changed.
2. Browser harness assertions were aligned to the actual editorial heading, Horizon prefix and same-URL Archive/Reopen navigation.
3. Long statement typography was reduced and constrained for desktop/mobile reading; computed-style assertions prevent regression.
4. ReleaseHardening migration count was updated from `108` to `109` after the additive migration.
5. Human Visual Review identified the need to explain abstract official wording without weakening it. Optional overall and Section explanations were added as a shared Philosophy / Vision / Policy capability, with type-specific Presentation labels.
6. Snapshot schema was advanced to v2 for new Revisions; v1 history remains readable and untouched.
7. The current ReleaseHardening migration count was advanced from `109` to `110` for the explanation-only additive migration.

## 11｜Remaining / Conditional

- Known scope-external failure: stale forbidden intended URL in `CompanyNavigationTest`; reproduced on base SHA.
- Human visual review of the three text experiences remains active; the isolated review environment now exposes the explanation fields without changing its existing content.
- Actual isolated MariaDB execution was not opened as a new environment because P1 introduced no engine-specific behavior. It remains part of the later approved DB application gate if Human requires it.
- No image, AI, relation or delivery function should be inferred from P1.

## 12｜P2 Readiness

P1 supplies stable Organization-scoped Items, immutable current Revision identity, Section identity/order and explicit View/Edit permission boundaries. These are sufficient foundations for a future P2 design covering manual Business Domain relation, current-official AI READ, Source/Citation, shared permission intersection and image asset/version.

P2 is not implemented and no P2 permission, AI or image contract is pre-decided by this implementation.

## 13｜Recommended Decision

**P1 CLOSE CANDIDATE**

Reason:

- the text master can be created, read, corrected, versioned, archived and reopened under the approved permission contract;
- ROOT / FUTURE / DIRECTION are usable as distinct reading experiences;
- focused, responsive Browser repeatability, related regression, build and isolated migration Evidence are established;
- no new Product Decision or Human Authority boundary was encountered;
- the only residual automated failure is proven pre-existing and scope-external.

Do not start P2 from this recommendation. Stop for Human + ChatGPT Review.
