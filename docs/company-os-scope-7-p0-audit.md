# Company OS Ver.1 Scope 7 P0 Audit

## 1. Audit identity

- Scope: Scope 7 / Business Domain
- Audited at: 2026-09-21 (Asia/Tokyo)
- Repository: `C:\xampp\htdocs\rise-gate-os`
- Branch: `master`
- HEAD: `5ffdd69cb2e263cf09e4291d264d024ee2a39d5c`
- `origin/master`: `5ffdd69cb2e263cf09e4291d264d024ee2a39d5c`
- Worktree at P0 start: clean

## 2. Source documents

The following seven files were present and treated in the precedence defined by the implementation instruction. The Master files are the upper source of truth and the Scope 7 instruction controls Scope 7 implementation details.

| Document | SHA-256 |
| --- | --- |
| `CompanyOS_v136_scope6_done_business_domain_brushup.pptx` | `F9BD885930C1CD9C61F8C61CC9987F8BBEE66B61EA670E5D16E3EC103D416F19` |
| `CompanyOS_Ver1_要件仕様書_v032_Scope6_FinalClose_BusinessDomain.xlsx` | `FB5CE2B4981045D6A98F1B2C3B042DBD03CF5855E6EE9ADA185164F7A3885980` |
| `CompanyOS_Ver1_RemainingScope_Roadmap_v008_Scope7確定版.md` | `983E1100596DE309F82057BD85FDF56D8526438E438D83C437764DA8867F3948` |
| `CompanyOS_Ver1_RemainingScope_管理表_v008_Scope7確定版.xlsx` | `DDC55F5DC287202D673085F87A348DC2D309EF3718EA33B411AB4809614B67EE` |
| `CompanyOS_Scope7_実装準備書_v002_確定版.md` | `173FCFB289F8FD8E65DC2F4DCBF27CD2A13CB0B5CA8AD7D1A2355665D3A4A960` |
| `CompanyOS_Scope7_管理表_v002_確定版.xlsx` | `567615D513BB6BF0B62FFE02D74C0C9ED699E525C703092FE7A6E2BA016E65E9` |
| `CompanyOS_Scope7_Codex実装指示書_v002_確定版.md` | `A9BAEDBBB135B0FA28EA2A7CF0C554D720430BF074B853D341EA9E960C4CB770` |

Repository `docs/` contains the Scope 1–6 close reports. Scope 1–6 remain Closed Contracts.

## 3. Fresh baseline

Executed against the current repository, rather than reusing a previous report result:

```text
php artisan test
Tests: 414 passed (3396 assertions)
Duration: 154.76s
```

## 4. Existing implementation and responsibility boundary

- No existing `business_domains` table, Business Domain model, controller, route, writer, query service, editor grant, revision, operation, view, or test was found.
- `WorkspaceBusinessProfile` is a Workspace/document issuer profile and remains unchanged.
- Project, Roadmap, Improvement, Task, Project membership, and cross-organization collaboration remain unchanged.
- Financial, Company Observation, Client, Project App, Organization Group/Position, and Workspace membership remain separate domains.
- `AiProjectContextGuard::SCOPE_ONE_CATEGORIES` remains limited to Scope 1 project categories. Scope 7 must not add Business Domain to automatic AI context or allowed AI categories.
- Organization current-context middleware establishes an active Organization membership, but every Scope 7 read/write path still requires dedicated Business Domain authorization.
- Existing `CompanyAccess::allows()` is not suitable for Scope 7 and will not be used for its grants.

## 5. Permission and concurrency findings

- `OrganizationAccess` provides the authoritative active membership and Organization Role boundary.
- Scope 7 needs a dedicated `BusinessDomainAccess`: active Owner has edit/history access; active Admin/Member needs an active explicit editor grant; an active Organization membership alone provides current-value view only.
- System Admin state, Position, Group, Workspace, Project, Financial, legacy company permission, and AI keys must never derive Business Domain access.
- Existing Organization writers serialize by locking Organization and membership records. Scope 7 will preserve the order: Organization -> actor membership -> editor grant (if required) -> Business Domain.
- Suspended/left membership, inactive User, stale session/URL, and cross-Organization identifiers must be rejected by the dedicated access layer.
- Revision snapshots and operation records must be committed in the same transaction as current-value changes. Optimistic version checks and request-id/payload-hash idempotency are required.

## 6. Migration state

- Scope 7 migration does not yet exist at P0.
- Normal local DB has the Scope 5 and Scope 6 migrations pending.
- P0 did not mutate the normal local DB and did not run pending migrations.
- Scope 7 must use additive tables only. No existing table rename, destructive data rewrite, or permission-column repurposing is required.
- Empty-DB and cloned-data rehearsal will be performed in P4/P5. Production remains unapplied and no Production Deploy is authorized.

## 7. Compatibility gates

### S7-C01

No physical Business Domain implementation or incompatible same-purpose source of truth exists. Existing adjacent entities have distinct responsibilities and can remain untouched.

Result: **Not triggered**.

### S7-C02

The fixed permission boundary can be implemented without changing existing Project/Workspace/Financial/AI permissions and without weakening Organization membership lifecycle enforcement.

Result: **Not triggered**.

## 8. P0 decision

No confirmed-specification change,重大 Permission/Security decision, destructive migration, or major Scope expansion is required. Proceed autonomously to S7-P1 through S7-P5 under S7-DE-01–04.

Non-blocking technical choices will be recorded in the implementation report and evidence instead of reopening the fixed decisions.
