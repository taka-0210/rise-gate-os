# Company OS｜IR-1 G4 Immutable Topology Package Close Candidate

- Evidence version: v001
- Date: 2026-10-03 JST
- Gate: G4 Immutable Topology Package
- Recommended Decision: **G4 PACKAGE CLOSE CANDIDATE / G5 TARGET ENVIRONMENT READY**
- Production Deploy / Migration: **NO-GO**

## 1. G3 Formal Close input

G4は次のFrozen RCへ固定されている。

| Item | Exact value |
|---|---|
| Source commit | `924af91188cc60d33ff87c91b94ecc1d539566e6` |
| Git tree | `7d979ef6a854bce7943740044a3f0c4d942dc617` |
| Release ID | `ir1-924af91188cc60d33ff87c91b94ecc1d539566e6` |
| Application artifact SHA-256 | `2de9b840627d0dbfd1beabaca7e8609dc9e16be2fd9c3021e3dfdc2c764cdb69` |
| Application artifact bytes | `5,807,985` |
| G3 Evidence SHA-256 | `dd08b0bc4e0b0a44863922423e3f34f7492948a4c1ff4769765a69f1e064e4a1` |

G4はApplication artifactを再生成または変更しない。Topology packageは上記identityを照合してからのみ動作する。

## 2. Formal topology contract

```text
<topology-root>/
  releases/<release-id>/
  shared/.env
  shared/storage/
  current             -> releases/<active-release-id>
  current.previous    -> releases/<previous-release-id>

<public-entry>         -> <topology-root>/current/public
```

- Application release directoryはimmutableかつrelease ID単位で新規配置する。
- `.env`と`storage`はrelease外のshared stateとし、release内からabsolute symlinkで参照する。
- 公開entryは`current/public`だけを参照し、個別release pathを直接参照しない。
- 切替は同一filesystem内のsymlink renameを使用する。
- `current.previous`は直前のcode releaseを保持する。
- Rollbackはcodeのみを戻し、additive database schemaは維持する。
- database rollbackはG4 Contractに含めない。

## 3. Separated operator package

| Operation | Script | Mutation boundary |
|---|---|---|
| Topology verification | `verify-topology.sh` | none / read-only |
| Immutable placement | `install-release.sh` | exact new release directory only |
| Atomic switch | `switch-release.sh` | `current.previous`と`current`のatomic renameのみ |
| Code rollback | `rollback-release.sh` | `current.previous`と`current`のatomic renameのみ |

各operationは別承認とし、Human Operationは **1 Step = 1 Command / PASS or STOP** とする。Installは`current`を変更せず、Migrationは別Gateのためpackageに含めない。

## 4. Fail-closed safety contract

- package manifest、全file checksum、contract ID、RC SHA、tree、application artifact SHA/bytesを実行前に照合する。
- package rootのexact file setを検証し、追加fileまたはsymlink混入時はSTOPする。
- absolute allowlisted pathのみ受理し、root path・shell metacharacterを拒否する。
- 既存release、incoming path、staging symlinkを上書きしない。
- shared `.env` / `storage`の存在と型を確認し、値・credentialを出力しない。
- old/new releaseのmanifestとshared linkを切替前に検証する。
- public entryがexact `current/public` symlinkでなければSTOPする。
- PASS / STOP後のblind retryは禁止する。
- outputはsanitized statusのみとし、Secret / raw path / raw exception / Business Data / Personal Dataを出力しない。

## 5. Exact G4 package identity

| Item | Exact value |
|---|---|
| Package | `company-os-g4-topology-ir1-924af91188cc60d33ff87c91b94ecc1d539566e6.tar.gz` |
| Package SHA-256 | `5c99d35d03bcfd08bb83e0ad81cb90ed0cf4ef7e4eb4f363930126e7c4c9118a` |
| Package bytes | `6,325` |
| Package manifest SHA-256 | `9751b2d7ed41718c1316e31700c3247161255a508805140535f117f29bc3708b` |
| Checksums SHA-256 | `47a830d2ae0ded6201be84e3c7f0a2edc7faf669ed25618c1856483538855c08` |
| Evidence schema | `company-os.ir1.g4-topology-package.evidence.v1` |
| Evidence SHA-256 | `e2ddfa37719e0d837f918d1620f9c2c99bc6d4929c11b6dcf7e62d5f6505a1c6` |
| Archive file count | 9 |
| Archive symlink count | 0 |

Local generated Evidenceは
`storage/app/release-audit/g4-topology-package-924af91188cc60d33ff87c91b94ecc1d539566e6/`
に保持する。Productionへは配置していない。

## 6. Automated verification

| Verification | Result |
|---|---|
| Shell parse / LF-only | PASS |
| Exact candidate / G3 Evidence binding | PASS |
| Deterministic package rebuild | PASS |
| Archive exact file set / checksum | PASS |
| Extracted package runtime contract | PASS |
| workflow trigger | `workflow_dispatch` only / PASS |
| Focused regression | 5 tests / 72 assertions PASS |
| G3 + G4 + Release Hardening regression | 12 tests / 129 assertions PASS |
| Repository full regression | 704 PASS / 17 skipped / 8 pre-existing state-dependent failures |
| State transition and failure injection | 7 scenarios / 22 assertions PASS |
| Public entry boundary during simulated failures | maintained |
| Production connection | 0 |
| Production mutation | 0 |
| Deploy / Migration / DNS / SSL / `.env` / permission / symlink change | 0 |

Failure injectionはartifact hash mismatch、existing release、switch前半停止、switch後半停止、rollback identity mismatch、rollback途中停止を含む。公開entryは常に`current/public`のままであり、途中失敗時に未検証releaseへ切り替わらない。

Full regressionの8 failureはG4変更箇所ではない。内訳は、既存のCompanyNavigation login expectation 1件と、正式Production Evidenceを保持するone-shot G2 Corrective-2 directoryの存在をtest preconditionが拒否した7件である。G4 testはfull regression内でも全件PASSしている。既存Evidenceを削除してtestを通す操作は行っていない。

## 7. Local limitation and G5 acceptance boundary

Windows local環境ではProduction相当のnative POSIX symlinkとsame-filesystem renameを実行できない。このため実symlink E2E rehearsalは **LOCAL UNSUPPORTED / G5 TARGET ENVIRONMENT REQUIRED** とする。

これはG4 package設計・hash固定を妨げないが、Production mutation開始前にG5で次をread-only確認し、その後に別承認rehearsalを行う必要がある。

- target filesystemとsame-filesystem atomic rename成立性
- POSIX symlink / `realpath` / `sha256sum` / GNU-compatible `tar` availability
- target path、ownership、permission、disk
- `app.company-os.jp` public-entry boundary
- shared state作成・移行計画

## 8. Continuing blockers

G4では次を解消していない。

| Evidence gap | Status carried forward |
|---|---|
| usable backup | UNKNOWN |
| DB restore readiness | BLOCKER |
| Release marker ↔ Application code binding | BLOCKER |
| `.env` permission `0604` hardening | BLOCKER |
| user cron | UNKNOWN |
| external writer Production enablement | UNKNOWN |
| active transaction / metadata lock | UNSUPPORTED |
| DB default collation / Application collation difference | OPEN / disposition required before mutation |
| real POSIX symlink / atomic rename rehearsal | G5 TARGET ENVIRONMENT REQUIRED |

いずれもProduction mutation開始前のBlocking Dependencyとして維持する。

## 9. Gate decision

G4のProduction-free Acceptance Conditionは成立した。

# **G4 PACKAGE CLOSE CANDIDATE / G5 TARGET ENVIRONMENT READY**

ここでの「G5 READY」はG5 Target Environment工程を開始可能という意味であり、Production環境が既に構築済みという意味ではない。G5ではまずread-only target readinessと継続Blockerを閉じ、Human承認なしにProduction file、DB、DNS、SSL、permission、symlinkを変更しない。
