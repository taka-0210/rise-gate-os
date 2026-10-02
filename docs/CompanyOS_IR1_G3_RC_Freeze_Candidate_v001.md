# Company OS｜IR-1 G3 Release Candidate Freeze Candidate v001

- Date: 2026-10-03 JST
- Release case: `IR1-G3-RC`
- Decision: **G3 RC FREEZE CANDIDATE / G4 READY**
- Production connection / mutation: **0 / 0**

## 1. Gate transition

`G2 FORMAL CLOSE / G3 READY`を前提として、Production-freeのG3 Release Candidate Freezeを完了した。

G3 Acceptance Conditionである、exact source identity、Product Scope、dependency locks、Migration set、schema additions、build/test、deterministic artifact、output schema、およびIR-1後Scope混入0をEvidence化した。

## 2. Frozen Release Candidate

| Item | Frozen value |
| --- | --- |
| Exact commit | `924af91188cc60d33ff87c91b94ecc1d539566e6` |
| Git tree | `7d979ef6a854bce7943740044a3f0c4d942dc617` |
| Product Scope | Closed Product through Scope 9 + IR-1 Release Hardening |
| Excluded | Scope 10 / Scope 11 / S11 Companion Delta / CE realtime corrective |
| Tracked source files | 740 |
| Source manifest SHA-256 | `864e12e50df83c8ab6190bf87570cf7baa698ad3791112e8264bfca19fa1bb3b` |
| Output schema | `company-os.ir1.g3-rc-freeze.evidence.v1` |

Post-IR-1 forbidden path matchは0、`post_ir1_scope_mixed_in=false`である。

## 3. Exact Release Artifact

| Item | Result |
| --- | --- |
| File | `rise-gate-os-924af91188cc60d33ff87c91b94ecc1d539566e6.tar.gz` |
| SHA-256 | `2de9b840627d0dbfd1beabaca7e8609dc9e16be2fd9c3021e3dfdc2c764cdb69` |
| Bytes | 5,807,985 |
| Members | 7,714 |
| Deterministic rebuild | PASS; 2 builds produced the same SHA-256 |
| Runtime state / Secret inclusion | 0 detected |

Artifactはexact candidateのdetached worktreeから2回生成した。`.env`、SQLite DB、runtime storageをArtifactへ含めていない。

## 4. Dependency binding

| Lock | SHA-256 |
| --- | --- |
| `composer.lock` | `48cae0d80cc435e52cd55d2c96ac90ffc7f79ffb502d6c996a18e81fee4aa059` |
| `package-lock.json` | `4d1ec90c409b38991395209a2ff1330549b6dfeb1f3833ed22e798a97234ffdb` |
| Dependency lock manifest | `ead44969d3cdb4c006b68f6cd57b934c69bf1c38affbf0e38132b1143e6bb8aa` |

Build toolchainはPHP `8.3.30`、Node.js `v22.22.3`へ固定した。PHP required extensionsはbuild開始前に検査する。

## 5. Migration freeze

- Repository Migration: 94
- Production baseline ledger: 83
- Exact pending: 11
- Migration manifest SHA-256: `da22986bea8aa433e3e25cfa1fa726a18e44840660ac122a075c6ee017a64230`

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

## 6. Schema addition manifest correction

Exact source auditにより正本を次へ固定した。

- New tables: **31**
- Added columns: **73**
- Schema additions manifest SHA-256: `b3ecb8dd8974acca31898a3b2e5289b744850835e7e87e0d1f3bb97051165d86`

従前の72 columnsは、scannerが`Blueprint::ulid()`をcolumn additionとして分類せず、`ai_proposal_items.public_id`を欠落させたことによる計数誤りである。これはProduct Scope追加ではない。既知のfalse positiveだった`ai_proposals.evidence`はmanifestから除外した。

## 7. Verification

| Verification | Result |
| --- | --- |
| Exact candidate full test | 510 passed / 16 skipped / 4,162 assertions |
| Frontend production build | PASS |
| Production dependency install | PASS |
| Deterministic artifact rebuild | PASS |
| Artifact member safety | PASS |
| G3 focused regression | 3 passed / 28 assertions |
| Production connection | 0 |
| Production Deploy / Migration | 0 / 0 |

生成Evidence JSON SHA-256は`dd08b0bc4e0b0a44863922423e3f34f7492948a4c1ff4769765a69f1e064e4a1`である。

### Non-gating current-branch diagnostic

Deploy対象ではないIR-1後の現行branchでもfull suiteを診断実行し、699 passed / 17 skipped / 8 failedだった。7 failuresは、Formal PASS済みのG2 Corrective-2 Production execution Evidence directoryが保持されているため、「attempt state不存在」を前提とするpre-execution testがFail Closedしたもの。残る1 failureはIR-1後branchの既存login redirect挙動で、isolated rerunでも再現した。いずれもexact candidate `924af911...`のsource、Artifact、510-test PASSを変更しないためG3 freezeのgating failureには採用しない。既存G2 Evidenceは削除・変更していない。

## 8. Continuing blockers

次の状態はG3で解消しておらず、Production mutation開始前のBlocking Dependencyとして維持する。

| Item | Status |
| --- | --- |
| usable backup | UNKNOWN |
| DB restore readiness | BLOCKER |
| Release marker ↔ Application code binding | BLOCKER |
| `.env` permission `0604` hardening | BLOCKER |
| user cron | UNKNOWN |
| external writer Production enablement | UNKNOWN |
| active transaction / metadata lock | UNSUPPORTED |
| DB default collation vs Application collation | OPEN DIFFERENCE |

## 9. Formal recommendation

# **G3 RC FREEZE CANDIDATE / G4 READY**

G4のProduction-free設計・rehearsal準備へ進める。ただし、本EvidenceはProduction Deploy、Production Migration、DNS / SSL、`.env`、permission、symlink変更を承認しない。
