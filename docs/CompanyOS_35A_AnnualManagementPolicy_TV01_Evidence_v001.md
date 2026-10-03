# Company OS｜35A Annual Management Policy｜TV01 Evidence v001

Date: 2026-10-03 JST

Status: **35A-TV01 PASS / PRODUCT COMPATIBILITY BLOCKER 0**

## Repository binding

| Item | Evidence |
|---|---|
| Product root | `C:\xampp\htdocs\rise-gate-os` |
| Branch | `ce-p1-realtime-corrective` |
| Initial full HEAD | `47eafdb6a37c0eac4abc560bca641416115ee54b` |
| MDC-P1 source branch | `mdc-core-philosophy-vision-policy` at `6f1e9cb` |
| MDC-P1 integration | merge commit `e1e5622273108c74a9202031132b52bf312784e6` |
| 35A implementation | `bc5234949a435c516b0184e9889f3c606f9cf240` |
| Implementation tree | `01a97ee8a353c0d8fbd518a66867c154398e3c8c` |

The initial tracked worktree was inspected read-only. Three unrelated untracked documents already present under `docs/` were not staged, reset, stashed, cleaned, checked out, or edited. The separate historical MDC worktree path was not adopted as the product root; its formally closed branch was compared and merged into the active product branch without conflicts.

## Actual-symbol compatibility review

| Boundary | Adopted symbol / decision |
|---|---|
| Organization / Membership | Existing `Organization`, `OrganizationUser`, active membership lifecycle, access epoch |
| Role / Position / Group | Existing organization role and `OrganizationGroup`; neither is converted into 35A body access |
| MDC | Existing access/writer/revision/audit patterns reused; Annual remains an independent aggregate |
| Business Domain | Existing entity unchanged; no copy into Annual |
| Project / Action | Existing `ProjectExecutionAccess::canRead` and `ActionExecutionAccess::canRead` delegated to by typed relation adapter |
| Relation | No reusable generic engine was present; additive, Annual-scoped typed/versioned relation tables were used |
| AI Source / Citation | Existing source-lineage boundary respected; 35A only prepares a typed provider/export/citation contract |
| Management Period | No equivalent post-P1 entity existed; additive organization period and immutable period-version entities were introduced |
| Audit / operation | Existing `OrganizationAudit` plus Annual request-id operation ledger reused; body content is not copied into audit |

## Technical differences resolved inside the approved boundary

1. MariaDB 10.11 limits identifiers to 64 characters. The merged MDC operation FK and two pre-existing CE unique constraints generated longer names on a clean database. Short explicit names were added without changing tables, columns, uniqueness, FK semantics, or Product Scope.
2. Annual `current_approved_revision_id` is bound to its revision by an explicit named FK after revision-table creation; down removes the circular pointer first.
3. Department payloads that omit a stable public ID reuse the existing Annual×Group entity, preserving the fixed maximum-one contract.
4. Current/period citations resolve through the exact approved revision; a later approval cannot silently rebind an earlier citation.
5. Approval snapshot hashes include a sanitized access-state binding so a reviewed sharing/permission state cannot change between preview and approval.

None of these correctives changes 35A-DE01–10 or 35A-RDP01–04.

## TV01 conclusion

- Latest active repository: established.
- P1 / CE / Production Foundation / MDC symbols: inspected and integrated.
- Reusable assets and additive gaps: classified.
- Unrelated worktree state: preserved.
- Secret or `.env` content read/output: none.
- FIXED Product Decision change required: **no**.

**TV01 PASS. P1 → P2 → P3 execution was eligible without another Product Gate.**
