# Company OS Scope 8 P0 Audit

- Date: 2026-09-24 JST
- Baseline HEAD: `797b87e12d0f632dd2df27c0a1c93aa57e3a274b`
- Result: **P0 passed / S8-C01 none / S8-C02 none**
- Boundary: read-only audit and implementation design. No normal-local write, Production connection, deploy, HOW, or Scope 9.

## Authoritative sources

| Source | SHA-256 |
|---|---|
| `CompanyOS_Scope8_Codex_Instructions_v002.md` | `133281101F6061CA700B0DE7CD43524D229B48E8CBA017E036CB926CD1BC45B2` |
| `CompanyOS_Scope8_Decisions_Pending_v002.md` | `87D34E743A76744AA243620B6344431BB26922B1C869203A268ABE0C4A1A1E0C` |
| `CompanyOS_Scope8_Implementation_Preparation_v002.md` | `A62BF71FCD55B666A17D9B9551DC6466F159B125F324659B1B47BABB0C4BE832` |
| `CompanyOS_Ver1_要件仕様書_v037_Scope8_ProjectAction.xlsx` | `4F87F3E95192BC791696B8428D37C4400814D8AD578B74F685E7D3FE76E1B051` |
| `CompanyOS_v141_scope8_project_action_decision.pptx` | `C3A412FFF72F6BD07B04F89013717E5B562A01F72C99D3608E8FA007636CE5D5` |

S8-DE-01〜03は確定、S8-PD-01〜03とS8-B01〜03は解消済みとして再質問せず採用した。

## Reused evidence

- Scope 1 AI Proposal safety engine and Close evidence.
- Scope 3〜6 Organization / Membership / Cross-Org / epoch closed contracts.
- Scope 7 and PUX-A / PUX-B closed contracts.
- PUX-A RG02: MariaDB 10.11.19 / InnoDB / utf8mb4 / REPEATABLE READ、row lock、1213 / 1205、bounded retry evidence.
- Account separation Formal Close and current normal-local data inventory.

## Current-data inventory

Read-only normal-local inventory at P0:

- Project: live 15 / including soft-deleted 17
- Project Member: 17
- Roadmap: 36
- Theme / Improvement: 51
- Task / Action: live 50 / including soft-deleted 51
- Legacy Theme without Roadmap: 16
- Direct Action: 0
- Soft-deleted Project with live descendants: 2

No tenant/relation corruption was found. The legacy shape is retained as legacy data; it is not guessed into Scope 8, bulk-published, or destructively converted.

## Writer and compatibility mapping

- New Scope 8 rows are explicitly identified by `projects.execution_contract_version = scope8.v1`.
- Existing Project/Task/Roadmap/Improvement writers remain the legacy canonical path for legacy rows.
- Scope 8 rows use `ProjectExecutionWriter`; legacy mutation routes fail closed for Scope 8 rows.
- Existing entity IDs are reused. Project/Roadmap/Improvement/Task are not duplicated into a second source of truth.
- Canonical execution roles use individual `project_members` plus additive multi-role assignments. Organization title/role and Group visibility do not implicitly grant write permission.
- Company / Group / Confidential read visibility is independent from explicit execution membership.
- Scope 1 `project-plan.v1` allowlist remains unchanged; Scope 8 uses a separate adapter contract.

## P0 risk decision

- S8-C01 major compatibility issue: not detected.
- S8-C02 Product / Permission / Tenant conflict: not detected.
- Main controlled risks: legacy writer bypass, confidential name leakage, Group-to-member privilege escalation, direct Action snapshot omission, AI v1 allowlist expansion, owner race, and MariaDB identifier/rollback differences.
- Decision: advance autonomously to S8-P1〜P5 with additive schema, explicit adapters, isolated DB verification, and risk-focused regression.

