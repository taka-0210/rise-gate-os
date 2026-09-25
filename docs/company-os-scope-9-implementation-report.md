# Company OS Scope 9 Implementation Report

- Date: 2026-09-25 JST
- Status: **Code Complete candidate / Human + ChatGPT Formal Close Review pending**
- Baseline HEAD: `6db2702f651f8da63d6cb104d9ddcea2471d45e3`
- Implementation Commit: `fa85391`
- Stop point: S9-P5 completed. Normal-local Migration, Production, deploy, IR-1, HOW, and Scope 10 remain outside this candidate.

## Implemented outcome

- Preserved `tasks` as the Action source of truth and added run settings, immutable schedule revisions, per-occurrence Executions, and append-only Events.
- Added Daily / Weekday / Weekly / Monthly / Custom recurrence; On Date / By Date / Within Window; period, count and unlimited operation; pause/resume; missed finalization; human retry; and idempotent catch-up.
- Added a Company Home entry and Today journey for executable continuous occurrences, due/overdue one-time Actions, Reviewer waits, monthly history, completion rate, and regular/retry breakdown.
- Added execution configuration and history from Project Action read/manage screens. Each completion affects only its Execution; the continuous parent remains within the existing Scope 8 four-state lifecycle.
- Added permission and tenant enforcement using current company, active explicit Project membership, current Assignee, and active Project Owner boundaries.
- Added a console catch-up command and an authenticated POST refresh that share the same bounded, idempotent generator. DB Event Scheduler and Scope 10 notifications are not required.
- Snapshot/Restore fails closed before a legacy restore can erase or reinterpret Scope 9 state.

## P4 AI draft approval and boundary

The 2026-09-25 human approval permits an external AI request only after the user explicitly selects “AIで文案を作る”. The payload is restricted to:

- user-entered destination description (`target`)
- Action title (`action_title`)
- Done Condition (`done_condition`)
- user-entered draft instruction (`instruction`)

Organization name, Project Purpose / Expected Outcome, Project/Action history, Execution results, notes, IDs, Member/Staff data, and other Company Data are not sent. `ActionDraftProvider` keeps the Domain/Service contract provider-neutral; the existing OpenAI connection is contained inside `OpenAiActionDraftProvider`. A suggestion is returned to the form only: no Action/Execution write, automatic save, completion, external send, or post occurs. Provider failure is audited and leaves manual operation available.

## Migration and data evidence

- Migration is additive and creates four Scope 9 tables. It does not classify or backfill existing Actions.
- A consistent normal-local SQLite copy was migrated in an isolated temporary Clone. Integrity was `ok` before and after.
- Legacy logical hash stayed `87bff78ff48c0dc9311d6399ae8e487ae6265c41fb0b08d9a6f642a8cd6bdfe4`; all four Scope 9 tables were empty immediately after migration.
- Normal-local SQLite SHA-256 stayed `EC7DBE7178B6343DA9313F81A6F238804CF093F82C3CFE66F1539A38F6D4396B` through isolated Clone, Browser, and MariaDB checks.
- The isolated Clone and temporary Browser/MariaDB data were removed. Normal-local Migration was not applied and Production was not connected.

## Verification evidence

### Focused and related

- Final SQLite Scope 9 focused suite: **13 tests / 53 assertions / failures 0**.
- MariaDB Scope 9 focused suite before the final pure recurrence assertion: **12 tests / 46 assertions / failures 0**. The added assertion is DB-independent and was verified on SQLite; MariaDB was not rebuilt solely for it.
- Scope 8 related regression: **14 tests / 87 assertions / failures 0**.
- PHP syntax for all Scope 9 PHP/Migration/Test support files: PASS.
- Scope 9 route inventory: 10 routes, PASS. `git diff --check`: PASS.
- Frontend build: Vite 7.3.6, 58 modules, success.

### Real Browser

- Real Chrome against an isolated SQLite database completed Today, manual communication-plan save, Execution completion/history, the explicit AI draft button boundary, and the distinction from external send.
- Desktop and 390px passed with no horizontal overflow.
- Desktop screenshot SHA-256: `C59041EC384B50E72277C3AF65147AF23C2CC2BF8EA7F094622C07A17A7F7FAC`.
- 390px screenshot SHA-256: `4B6496B7CF0CBB0AFA9CF3A5D3A09B095FCCA090CB4FB056ED890BE0E3BE11BD`.
- Browser result: `desktop=true`, `mobile390=true`, `manualPlanSave=true`, `completion=true`, `externalSend=false`.

### MariaDB 10.11 isolation and concurrency

- Official MariaDB 10.11.19 archive SHA-256 matched RG02 evidence: `398EA30E5036010BBEBE01D2B1804280424DCC2626E36D8E95155C04D25A0490`.
- Profile: MariaDB 10.11.19, InnoDB, utf8mb4 / utf8mb4_unicode_ci, REPEATABLE-READ, loopback port 13329.
- Independent-process barrier test: concurrent generation produced 65 rows with 65 distinct slot keys; concurrent retry returned the same Execution ID and one retry key; concurrent complete/skip allowed one terminal result and rejected the other.
- The temporary schema, process, datadir, archive and markers were cleaned; port 13329 has no listener.
- The XAMPP seed datadir reported system-table upgrade warnings. The isolated application schema, migrations, focused tests and concurrency checks completed successfully; this does not change the verified Scope 9 application boundary.

### Wider-suite observation

A raw full-suite run was not used as the Scope 9 acceptance signal: **342 passed / 174 failed / 3,208 assertions**. The failures were broad HTTP 409 responses caused by the current admission/test environment and were not localized to Scope 9. Risk-focused Scope 9, Scope 8 boundary, Browser, migration and MariaDB checks above passed. The suite was not repeatedly rerun “just in case”; this limitation is retained for Formal Close review rather than reported as success.

## S9-DC-01〜26 Done review

| DC | Result | Evidence |
|---|---|---|
| 01 | Done | P0 audit fixes v142/v038, baseline HEAD, Closed boundaries, S8 corrective delta, S10 and IR-1 separation. |
| 02 | Done | v002 source hashes and synchronized DE/PD/B decisions recorded. |
| 03 | Done | Additive Clone migration, unchanged legacy hash, zero inferred backfill. |
| 04 | Done | Today implements four groups under currentCompany; focused tenant route test and Desktop/390 Browser passed. |
| 05 | Done | One-time Action/Review stays on Scope 8 Writer; Scope 8 related 14/87 passed. |
| 06 | Done | Multiple Execution lifecycle test proves parent Action remains `in_progress`. |
| 07 | Done | Fixed-clock frequency, leap/month-end, By Date and Within Window assertions passed. |
| 08 | Done | Count consumption/no refill/retry separation and unlimited/period paths are implemented and focused-tested. |
| 09 | Done | Unique keys, transactional writer, SQLite idempotency and real MariaDB generation race passed. |
| 10 | Done | Expiry converts only after window end and preserves missed state; focused test passed. |
| 11 | Done | Human retry preserves source missed record and converges under MariaDB race. |
| 12 | Done | Only current active Assignee can complete; negative and reassignment tests passed. |
| 13 | Done | Assignee/active Project Owner gate, mandatory reason, planned-only rule, Reviewer negative and expiry rejection passed. |
| 14 | Done | `completed / (completed + missed)`, zero denominator dash, skipped/planned exclusion and origin breakdown are implemented and rendered. |
| 15 | Done | Immutable revision, future planned skip, zero-refill resume and old-revision preservation passed. |
| 16 | Done | currentCompany, active explicit membership and current Assignee filters; direct-route tenant test and inactive-user generation stop passed. |
| 17 | Done | planned assignee, completing actor, time/memo and append-only events remain distinct across reassignment. |
| 18 | Done | Manual destination/draft/time save, Today confirmation and no external send passed in Browser. |
| 19 | Done | Approved four-field payload, explicit user action, Provider fake/failure audit and no-write checks passed. |
| 20 | Done | Scope 1 allowlist unchanged; Scope 8 related regression passed; Scope 9 setting changes use existing Project version/CAS boundary. |
| 21 | Done | Snapshot capture coexists; restore fails closed before changing Scope 9 history. |
| 22 | Done | Shared bounded catch-up supports command/POST, late recovery and duplicate safety without DB Event Scheduler. |
| 23 | Done | MariaDB 10.11.19 profile, migration, focused suite, independent-process races, rollback/cleanup passed. |
| 24 | Done | Today/My Action monthly history, empty/error server states, keyboard-native controls, build, Desktop and 390px passed. No asynchronous loading state is needed for the server-rendered page. |
| 25 | Done | Existing evidence reused; focused/related/Browser/MariaDB results and the wider-suite limitation are separately recorded. |
| 26 | Done | DC review complete; remaining Release Gates and prohibited next scopes are explicitly retained below. |

## Close candidate and remaining gates

- **Scope 9 Code Complete candidate: Yes.** S9-C01〜03 did not materialize; Conditional 0, Not Done 0.
- **Formal Close:** pending human + ChatGPT review. This report does not self-approve Formal Close.
- **Master Update:** not required. Product and Architecture remain v142 / v038; implementation did not add a new Product decision.
- **Normal-local:** migration remains pending. Applying it requires a separate authorized persistent-data step with backup/restore evidence.
- **Production / IR-1:** Production connection, migration, scheduler operating procedure, backup/restore, rollback, monitoring and deploy remain separate Release work.
- **Out of scope:** no Production change, deploy, external send, IR-1, HOW, or Scope 10 implementation was performed.

