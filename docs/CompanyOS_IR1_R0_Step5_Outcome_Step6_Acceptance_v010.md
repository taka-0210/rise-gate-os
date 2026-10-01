# Company OS | IR-1 R0 Step 5 Outcome / Step 6 Acceptance

Date: 2026-10-02 JST

## Decision

**STEP 5 APPLICATION / DB READ-ONLY AUDIT: PASS**

**STEP 6 HOST READ-ONLY AUDIT: READY FOR SEPARATE AUTHORIZATION**

**PRODUCTION DEPLOY / MIGRATION: HOLD / NO-GO**

Step 6 has not been executed. This outcome was produced from the retained sanitized Step 5 Evidence and local, Production-connection-free verification.

## Evidence identity

- Exact candidate: `924af91188cc60d33ff87c91b94ecc1d539566e6`
- Bundle ID: `5ba3c0fd459cabe885249d85dd13ffafbe087693435a5e24a483f5ad4a24a4c0`
- Step 5 helper SHA-256: `7daaaecab427e69828a68bd5fa162ea6f7516b19f7152fee60dd5e70f7db2336`
- Step 5 sanitized Evidence SHA-256: `4cb2f91d7d12e1083e8edaee41a3a408d0b2577d46fa1c69ad7a978a83c84be9`
- Prepared Step 6 helper SHA-256: `0c32ec4743201ba721cb93d74a6b1b479d6e53369f17ddc47575cc115af418cf`
- Output contract: schema-v2 / PASS / read-only / complete for supported capabilities
- Step 5 attempt count: 1
- Step 5 remote exit: 0
- Step 5 stderr bytes: 0
- Retry: 0

## Application / DB result

### Runtime and database

- Laravel: 12.63.0
- PHP CLI: 8.3.33
- DB driver: MySQL
- DB server: MariaDB 10.11.18
- Character set: utf8mb4
- Collation: utf8mb4_unicode_ci
- Session driver: file
- Cache driver: file
- Queue driver: sync
- Filesystem driver: local

No credential, connection string, raw DB identifier, raw Organization ID, raw User ID, Business Data body, Personal Data or raw exception is present in the Evidence.

### Migration ledger

- Repository migration count: 94
- Production ledger count: 83
- Exact pending count: 11
- Ledger-only migration count: 0

Exact pending set:

1. `2026_09_19_000001_add_scope_one_contract_to_ai_proposals`
2. `2026_09_19_000001_add_scope_two_account_security`
3. `2026_09_20_000002_add_scope_three_organization_foundation`
4. `2026_09_20_000003_add_scope_four_staff_invitation`
5. `2026_09_20_000004_add_scope_five_membership_lifecycle`
6. `2026_09_20_000005_add_scope_six_owner_onboarding`
7. `2026_09_21_000006_create_scope_seven_business_domains`
8. `2026_09_21_000007_add_direction_and_display_order_to_business_domains`
9. `2026_09_21_000008_add_product_organization_eligibility`
10. `2026_09_24_000001_add_scope_eight_project_action_foundation`
11. `2026_09_25_000001_add_scope_nine_action_execution_foundation`

This establishes that the legacy Production schema is behind the exact candidate by 11 migrations. It does not authorize or prove the safety of applying them.

### Schema inventory

- Tables: 61
- Columns: 781
- Index rows: 347
- Foreign-key rows: 147
- CHECK rows observed: 23

Relevant current tables are present for migrations, organizations, organization_users, users, workspaces and workspace_members. Relevant uniqueness includes organization public_id and slug, organization-user membership, user email, workspace public_id and organization-slug, and workspace membership. Relevant foreign keys bind organization users, users, workspaces and workspace members. The current organization_users permissions column has a JSON-validity CHECK.

The 23 CHECK rows are the metadata rows observed from MariaDB, chiefly JSON-validity checks. Duplicate metadata rows are not reclassified as distinct logical constraints.

### Protected Organization / Owner state

- Four masked Organization references were returned.
- Each has exactly one current `role = owner` row.
- Raw Organization and User identifiers were not output.

Classification:

- Protected Organization existence and one owner-role row per returned Organization: **ESTABLISHED**
- Active Owner lifecycle status: **UNKNOWN / NOT ESTABLISHED**

The legacy organization_users schema does not yet contain membership_status and organization_role. Therefore the audit correctly falls back to the legacy role column and cannot prove the later active-membership lifecycle condition.

### Pending invitation

**UNKNOWN / UNSUPPORTED ON CURRENT SCHEMA**

The organization_invitations table is absent because the corresponding migration is pending. A null Evidence value must not be interpreted as zero pending invitations.

### SQL safety

- Total audited statements: 42
- Statement classes executed: SELECT only
- Rejected statements: 0
- Contract ceiling: 48
- Persistent DB writes: 0
- DDL: 0
- Migration execution: 0

## Supported / Unknown / Unsupported

SUPPORTED in Step 5:

- application runtime configuration
- database metadata
- migration ledger and exact pending set
- schema / column / index / FK / CHECK metadata
- masked owner-role presence
- SQL statement instrumentation

UNKNOWN or not observable from Step 5:

- active Owner lifecycle semantics on the legacy schema
- pending invitation count without the invitation table
- queue worker process state
- cron / scheduler state
- external writer state

UNSUPPORTED in Step 5:

- filesystem topology
- process state
- backup restore readiness

## Step 6 Acceptance Condition

Before Production connection, the one-command helper must:

1. Require exactly one Step 5 PASS and no Step 6 attempt.
2. Bind to the approved Step 5 helper SHA-256 and retained Step 5 Evidence SHA-256.
3. Re-validate the Step 5 schema-v2 PASS contract and SQL safety.
4. Bind to the exact candidate, bundle ID, archive SHA-256 and manifest SHA-256.
5. Fail closed on unexpected candidate entries, symlinks, a raw bundle .env, missing Host audit script, or identity mismatch.
6. Keep BatchMode, strict host-key checking, one connection attempt and no password prompts.

The authorized Step 6 operation, if later approved, must:

- execute the standalone Host audit once with `legacy-fixed-root`
- not bootstrap Laravel
- not load or print .env values
- not connect to the DB
- not create, change, rename or delete files or symlinks
- not deploy, migrate, clear cache, dispatch jobs, send mail or call an external Provider
- store stdout only as sanitized schema-v2 Evidence
- return PASS or STOP and make retry unavailable

Step 6 PASS requires exact candidate / bundle / manifest binding and sanitized observations for PHP CLI/extensions, legacy topology, release marker, filesystem, disk/permissions, process snapshot, user cron availability and bounded backup inventory. It must preserve:

- immutable current/previous/shared topology: UNSUPPORTED for the legacy profile
- app.company-os.jp migration: OUT_OF_SCOPE
- restore readiness: UNSUPPORTED
- public backup exposure: UNSUPPORTED
- external writers: UNSUPPORTED

UNKNOWN remains UNKNOWN and is not promoted to PASS.

## Local corrective verification

Production connection: 0

Production mutation: 0

The Step 6 helper now verifies the retained Step 5 receipt and Evidence hash before any connection, performs an exact archive/manifest/symlink/raw-env preflight, and rejects semantically invalid Host PASS Evidence. Local PowerShell AST parsing and helper self-tests passed, including the unsupported-boundary regression.

- R0 focused regression: 7 tests / 160 assertions PASS
- Full Laravel regression: 678 PASS / 17 SKIP / 1 known unrelated CompanyNavigation failure / 5650 assertions
- Step 6 attempts: 0

## Recommended decision

**STEP 5 PASS / STEP 6 READY FOR SEPARATE AUTHORIZATION**

Do not run Step 6 until Human + ChatGPT separately authorizes one Host read-only attempt. Production Deploy and Production Migration remain HOLD.
