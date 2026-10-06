# Company OS - IR-1 G5 Target Skeleton Build Ready v001

- Date: 2026-10-07 JST
- Gate: NewTarget Target Skeleton Build
- Frozen RC: `924af91188cc60d33ff87c91b94ecc1d539566e6`
- Preparation base HEAD: `5ee242369ca84487fbab75cbffd1fce713e80ff5`
- G5-C disposition: **CORRECTIVE1 / FORMAL PASS APPROVED**
- Gate disposition: **ONE G5 TARGET SKELETON BUILD / HUMAN GATE READY**
- Production execution: **NOT EXECUTED**
- G5 disposition: **OPEN**

## Bound artifacts

| Artifact | SHA-256 |
|---|---|
| `Invoke-G5TargetSkeletonBuild.ps1` | `2085e17de492d248a1d7bb40749bb83b4d1d5961f32d0a91f0c3320da9ff0107` |
| `build-target-skeleton.sh` | `bbd05048b982b751861c48f84d2ba2c0ecaade333dd2d55f7d71b4646e57a0ff` |
| `target-skeleton-contract.json` | `de5476fbd019ab48909a4a9b82d9d12999dd21d29a0b3c4020da9d54ed728869` |
| `simulate-target-skeleton.php` | `0a15bcffb5dce08f9cd309c2e14d85c0f8438b1d18a3a5d3944728ed8815a319` |
| G5-B reconciled receipt | `ec24b30e62c34564aaaaccfa928edbac98f95e7e4748594422b641f4bbbae7c3` |
| G5-C Corrective1 PASS receipt | `a1dd2871236868affe3b4a9d5454dc87eb614b15f1049e3a555c3700dec59faf` |
| G5-C Corrective1 final state | `e696b86dbb818c85f99d0115f547408cf193eb1fd67693807a7dd9f28d2ed99c` |

The helper is also bound to the explicit `sv17169.xserver.jp` / `xs377816` /
port `10022` NewTarget identity, the Xserver-panel ED25519 Host Key trust
anchor, and the existing dedicated SSH identity fingerprint. It does not use
the Legacy SSH alias.

## Exact mutation boundary

The Human command may create only the following three empty directories:

| Absolute path | Type | Owner | Group | Mode |
|---|---|---:|---:|---:|
| `/home/xs377816/company-os.jp/company-os-app` | directory | UID 20046 | GID 1000 | `0750` |
| `/home/xs377816/company-os.jp/company-os-app/releases` | directory | UID 20046 | GID 1000 | `0750` |
| `/home/xs377816/company-os.jp/company-os-app/shared` | directory | UID 20046 | GID 1000 | `0750` |

Ownership is established by the bound `xs377816` login identity and verified
after creation. The operation does not call `chown` and does not broaden any
existing permission.

The directories are first constructed below the exact candidate-bound staging
sibling, verified for type, owner, group, mode, entry set and filesystem, then
published with a same-filesystem atomic rename. Existing topology or staging
state causes STOP before mutation.

## Explicit exclusions

The build does not create, modify, copy, link, read as content, or activate:

- `shared/.env`;
- `shared/storage`;
- an application release;
- `current` or `current.previous`;
- the live `app.company-os.jp` public-entry type or target;
- `.user.ini`, `default_page.png`, or `index.html`;
- Legacy paths;
- Application placement or Release marker binding;
- DB connection, Migration, DNS, SSL, or Deploy.

`PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION=PENDING_G5_PUBLIC_ENTRY_GATE`
remains unchanged.

## Public-entry protection

Before mutation, the remote contract requires the live public entry to remain
an ordinary directory containing exactly:

- `.user.ini` / file / `0600`;
- `default_page.png` / file / `0644`;
- `index.html` / file / `0644`.

It reads no file content. It snapshots type, mode, UID, GID, size, timestamp
and parent identity before the build and requires the same metadata snapshot
afterward. PASS is impossible if that protected boundary changes.

## At-most-once and idempotency contract

- The local helper persists an attempt before the Production SSH process.
- Any existing Skeleton attempt Evidence causes a pre-connection STOP.
- Any existing target topology or staging root causes a pre-mutation STOP.
- PASS and STOP are terminal; `retry_available=false`.
- Blind retry and successful-result replay are prohibited.
- The convergent second invocation is therefore a local STOP with no new
  Production connection or mutation, not a second build.

## Cleanup and rollback

- Before publish, failure cleanup uses `rmdir` only on the exact empty staging
  directories.
- After publish but before PASS, automatic rollback is allowed only when the
  created topology is still the exact empty three-directory skeleton.
- Recursive deletion is prohibited.
- Unknown, replaced, symlinked, or non-empty state is retained and returned as
  `failed_review_required`; it is never force-deleted.
- After PASS, rollback is not part of this command. It requires a separate
  Human gate.
- Shared `.env`, shared storage, public entry, Legacy and application content
  are never rollback targets for this operation.

## Acceptance condition

PASS requires all of the following in one sanitized remote contract:

1. G5-B and G5-C evidence hashes and dispositions match.
2. NewTarget SSH, Host Key, HOME, UID and GID match the bound identities.
3. Exact public-entry set and metadata are unchanged.
4. Exact three-directory creation set exists and is otherwise empty.
5. All three directories are ordinary directories owned by UID 20046 / GID
   1000 with mode `0750`.
6. Atomic staging publish succeeds on the target filesystem.
7. Staging cleanup is complete and residual count is zero.
8. All explicitly excluded state remains absent or unchanged.
9. Local receipt and final incremental state are persisted without raw or
   secret output.
10. The result returns to Human + ChatGPT with no retry or Deploy authority.

## Production-free verification

| Verification | Result |
|---|---:|
| PowerShell AST parse | PASS |
| remote shell syntax | PASS |
| contract JSON parse and helper hash binding | PASS |
| G5-B / G5-C / SSH / Host Key `-VerifyOnly` | PASS |
| Windows state generations 1-3 | PASS |
| atomic initial move / existing replace | PASS / PASS |
| local state `.tmp` / `.previous` residual | 0 / 0 |
| boundary simulation | 6 scenarios / 13 assertions PASS |
| G5 focused regression | 20 tests / 351 assertions PASS |
| Repository-wide regression | 768 passed / 17 skipped / 8 known unrelated failures |
| Production SSH / HTTP / DB connection during preparation | 0 |
| Production mutation during preparation | 0 |
| Skeleton attempt Evidence root | absent |

The eight repository-wide failures are unchanged: one existing
CompanyNavigation intended-URL failure and seven G2 execution-Evidence presence
failures. Every G5 and new Skeleton test passed.

## Continuing blockers

- `PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION=PENDING_G5_PUBLIC_ENTRY_GATE`
- usable backup: UNKNOWN
- DB restore readiness: BLOCKER
- Release marker / Application binding: BLOCKER
- `.env` hardening and runtime readability: BLOCKER
- user cron: UNKNOWN
- external writer enablement: UNKNOWN
- active transaction / metadata lock visibility: UNSUPPORTED
- DB/Application collation difference: OPEN RISK
- shared state, application placement, live public binding, Migration, DNS,
  SSL and Deploy: separate unapproved gates

G5 remains OPEN after this readiness result. The Human command may be issued
once only after explicit Human approval. PASS or STOP returns immediately to
Human + ChatGPT review.
