# Company OS - IR-1 G5-B Cross-server Reconciliation Formal Close Candidate v001

- Date: 2026-10-07 JST
- Gate: G5-B READ-ONLY TARGET DISCOVERY
- Frozen RC: `924af91188cc60d33ff87c91b94ecc1d539566e6`
- Reconciler implementation commit: `53adb341ce1f0e4a5dd240ea944bd9076e40dce2`
- Production Deploy / Migration / DNS change / SSL change: **NO-GO maintained**

## Decision

**G5-B RECONCILED FORMAL PASS / G5-C POSIX CAPABILITY REHEARSAL READY**

The Production-free reconciler validated the immutable legacy-server,
new-target, Host Key, target-contract, and Human management-plane Evidence and
produced an additive candidate-bound disposition receipt. It made no SSH,
HTTP, database, or other Production connection and performed no Production
mutation.

G5-C was not executed. G5-C remains a separate Human + ChatGPT gate.

## Candidate-bound receipt

| Item | Result |
|---|---|
| Directory | `storage/app/release-audit/production-g5b-cross-server-reconciliation-924af91188cc60d33ff87c91b94ecc1d539566e6/` |
| Receipt | `g5b-reconciled-disposition.json` |
| Generated at | `2026-10-07T00:05:30+09:00` |
| Receipt SHA-256 | `ec24b30e62c34564aaaaccfa928edbac98f95e7e4748594422b641f4bbbae7c3` |
| Status | `PASS` |
| Existing Evidence modified | `false` |

The reconciler hash-bound nine existing inputs before deriving the disposition.
All input hashes were checked again before receipt generation.

## Original observations preserved

The reconciler does not rewrite or reinterpret the remote observations as
same-server evidence.

| Original field | Preserved value |
|---|---|
| Legacy server `remote_legacy_physical_separation` | `unknown` |
| NewTarget `remote_legacy_physical_separation` | `unknown` |
| NewTarget `remote_target_anchor_state` | `review_required` |

These values remain correct for the remote probe, which can compare inodes and
devices only when both roots exist on the same server.

## Cross-server reconciliation

| Identity boundary | Legacy server | NewTarget | Result |
|---|---|---|---|
| Remote FQDN | `sv17033.xserver.jp` | `sv17169.xserver.jp` | distinct |
| Host Key fingerprint | `SHA256:lkUHlNS7K7nVe/slV97qC08nvzqNzsgUsVD9p2Q1KCs` | `SHA256:JW8I6QkDccWlz2UNvbmnKlZzVn9Dc3GL7JLAmUjSLt8` | distinct |
| Login UID | `20222` | `20046` | distinct |
| Actual HOME identity | legacy HOME hash | `/home/xs377816` hash | distinct |
| Legacy paths | present | absent | separated |
| Target paths | absent | present | separated |

Reconciled disposition:

| Field | Result |
|---|---|
| `cross_server_identity` | `PASS` |
| `effective_legacy_separation` | `yes` |
| `separation_basis` | `cross_server_trusted_host_identity` |
| `target_anchor_binding` | `PASS` |
| `VHOST_DOCUMENT_ROOT_BINDING` | `PASS` |

## Management-plane reconciliation

Human confirmed without editing or saving settings:

- Xserver Server ID: `xs377816`
- `app.company-os.jp` subdomain state: normal
- File Manager breadcrumb: `company-os.jp/public_html/app.company-os.jp`
- SSH exact path:
  `/home/xs377816/company-os.jp/public_html/app.company-os.jp`
- content view: none
- Production mutation: none

The exact three pre-existing entries are classified:

| Entry | Permission | Classification |
|---|---:|---|
| `.user.ini` | `600` | other file |
| `default_page.png` | `644` | PNG image |
| `index.html` | `644` | HTML document |

The management-plane subdomain state, File Manager path and SSH path form the
accepted Xserver management-plane triangulation for
`VHOST_DOCUMENT_ROOT_BINDING=PASS`.

## Pending boundaries

The following are intentionally not closed or executed by G5-B:

| Boundary | Disposition |
|---|---|
| `PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION` | `PENDING_G5_PUBLIC_ENTRY_GATE` |
| G5-C POSIX capability rehearsal | `not_executed` / separate Human gate |
| G6 SSL | `OPEN` |
| Production Deploy / Migration | not authorized |

The three existing public-entry files must not be removed, replaced, or
overwritten until the later public-entry mutation gate explicitly decides
their preservation or disposition.

## Verification

| Verification | Result |
|---|---:|
| Reconciliation contract JSON parse | PASS |
| Reconciler PowerShell AST parse | PASS |
| Production-free `-VerifyOnly` against exact existing Evidence | PASS |
| G5 focused regression | 11 tests / 158 assertions PASS |
| Candidate-bound reconciliation execution | PASS |
| Receipt integrity | PASS |
| New Production / SSH / HTTP / DB connection | 0 |
| Production / filesystem / DNS / SSL mutation | 0 |
| G5-C execution | 0 |

Repository-wide `php artisan test` completed with:

- 759 passed
- 17 skipped
- 8 failed
- 6,538 assertions
- 458.75 seconds

The eight failures are outside the verified G5-B delta:

- seven `Ir1G2PreflightV2ExecutionTest` cases require the already-preserved
  Corrective-2 one-shot Evidence directory to be absent;
- one pre-existing `CompanyNavigationTest` redirect expectation remains
  reproducibly failing in isolation (`4 passed / 1 failed / 32 assertions`).

The G5-B focused suite is green, and neither failure class imports, invokes, or
depends on the G5-B reconciliation contract or script. They are not G5-B
Compatibility Blockers and were not changed or suppressed.

## Safety outcome

| Operation | Result |
|---|---:|
| Production connection during reconciliation | 0 |
| SSH reconnect | 0 |
| HTTP request | 0 |
| Database connection | 0 |
| Existing Evidence mutation | 0 |
| Production filesystem mutation | 0 |
| DNS / SSL change | 0 |
| Deploy / Migration | 0 |

The next action is to return to Human + ChatGPT. No G5-C command is executed or
authorized by this Evidence.
