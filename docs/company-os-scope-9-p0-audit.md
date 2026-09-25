# Company OS Scope 9 P0 Audit

- Date: 2026-09-25 JST
- Baseline HEAD: `6db2702f651f8da63d6cb104d9ddcea2471d45e3`
- Result: **P0 passed / S9-C01 none / S9-C02 none / S9-C03 none**
- Boundary: read-only delta audit and implementation design. No normal-local write, Production connection, deploy, IR-1, HOW, or Scope 10.

## Authoritative sources

| Source | SHA-256 |
|---|---|
| `CompanyOS_Scope9_Codex_Instructions_v002.md` | `FB88BBF8005B3B663A17617DFEADC855DE44645AC4AEC32107F54B8E89264C26` |
| `CompanyOS_Scope9_Decisions_Pending_v002.md` | `44CE5AA2F9D28DE6FEB71010D3776B6925D2CB2D7CBDDDDEBFA60A42D748AF14` |
| `CompanyOS_Scope9_Implementation_Preparation_v002.md` | `59478695211C4BD0603181C2914142E64515CACBC3859D354E00E0A3DFCE0030` |
| `CompanyOS_Ver1_要件仕様書_v038_Scope9_TodayContinuous.xlsx` | `D3057527667DADB6CEF090870D9EE3016DE6313F9C91014284F7F167D9F87149` |
| `CompanyOS_v142_scope9_today_continuous_decision.pptx` | `8F04E5B11EB2AA11C0088FA3A12C8977B14F2F8AA38DEA8C78B6824B6FA5FD8D` |

S9-DE-01 / 02は確定、S9-PD-01 / 02は解決済み、S9-B01 / 02は解消済みとして再質問せず採用した。

## Reused evidence

- Scope 1 AI Proposal safety contract and Provider/Audit boundary.
- Scope 3〜6 Organization / Membership / Tenant / epoch closed contracts.
- Scope 8 Project・Action foundation, `project-action.v1`, Snapshot v2, corrective AI Proposal Journey evidence.
- PUX-A RG02 MariaDB 10.11.19 / InnoDB / utf8mb4 / REPEATABLE READ, row-lock and bounded-retry evidence.
- Account separation Formal Close and current normal-local isolation boundary.

## Delta and compatibility audit

- `tasks` remains the canonical Action. Existing Project / Roadmap / Theme / Action IDs, four states, Assignee, Reviewer, Done Condition, History, visibility, and soft delete remain unchanged.
- Scope 9 is additive: run setting, immutable schedule revision, per-occurrence Execution, and append-only Event are separate records. Existing Actions are not guessed into continuous/one-time execution and no historical Execution is backfilled.
- Execution uses `planned / completed / missed / skipped`; parent Action completion and Review remain Scope 8 responsibilities.
- Current Assignee and active explicit Project membership determine present execution authority; historical planned assignee and actors remain evidence.
- Count means regular planned slots. `missed` and `skipped` consume a slot; retry creates a separate Execution and does not change regular count.
- Schedule mutation uses a new immutable revision; Snapshot/Restore fails closed when a Scope 9 setting exists and never rewrites Execution history.
- Today remains under currentCompany and existing session/membership guards. Login and company selection are unchanged.
- AI draft assistance is a separate text-only adapter inside the existing AI boundary; it does not expand `project-action.v1`, write Action/Execution, or send externally.

## P0 risk decision

- **S9-C01:** no Scope 1 / Scope 8 Contract collision detected.
- **S9-C02:** no inferred conversion, destructive migration, or actor/history reassignment is required.
- **S9-C03:** existing tenant, permission, session and explicit Project-member guards support Scope 9; Scope 10 is not required.
- Controlled risks for P1〜P5: recurrence boundary/timezone, duplicate generation, terminal-state races, retry idempotency, assignment/membership loss, MariaDB unique/lock behavior, Snapshot restore, AI context leakage, and mobile Today usability.
- Decision: proceed through S9-P1〜P5 with additive schema, one Writer, isolated database verification, focused/related tests, and real Browser evidence.

