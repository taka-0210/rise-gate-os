# Company OS｜IR-1 Production Release｜G1 Evidence Gap Closure Outcome v002

Date: 2026-10-02 JST

## Decision

**G1 CLOSE CANDIDATE / G2 READY**

**Production Deploy / Migration: NO-GO**

G1のread-only inspectionは1回でPASSした。G1の目的は、R0で残ったEvidence Gapを分類し、G2 Migration SafetyをProduction変更なしで開始できる状態にすることである。Production Release、Migration、`.env`変更、permission変更、backup作成・削除、DNS / SSL、symlink変更は行っていない。

## Evidence identity

- Exact IR-1 candidate: `924af91188cc60d33ff87c91b94ecc1d539566e6`
- G1 helper SHA-256: `6d9f89461e917b23b86120c18dc5391e43f33b3301b5980b0b70c0f52b6effa9`
- G1 execution state SHA-256: `ffd48f4e508b299e24921fb4a016b12f97950043af63abc80d471080ac4bf038`
- G1 sanitized Evidence SHA-256: `15e20d272f39d1bd69290af6b5c2f681b52bb42a09273b783ca77ad7eacf4340`
- Production connection attempted: true（承認済みread-only inspection 1回）
- Production mutation: false
- Retry: false
- Secret / raw stdout / raw stderr output: false
- Evidence completeness: `COMPLETE_FOR_SUPPORTED_G1_READ_ONLY_SCOPE`

## Gap classification

| 対象 | 分類 | Evidence / 判断 |
|---|---|---|
| Production release markerの存在・形式 | **ESTABLISHED** | 40桁SHA形式。markerは`a06975797519c0fd4382258a9b4e0b92ef4fe8d5`。IR-1 candidateとは異なる。 |
| Markerと実Application codeのbinding | **UNKNOWN / BLOCKER before Production mutation** | release manifestなし、deployed Git HEAD取得不可。critical fileは3件中2件だけcandidate一致のため、markerまたはcandidateへApplication全体を帰属できない。 |
| Application fingerprint | **ESTABLISHED** | sanitized SHA-256 `8688e93e8e85afe67e70df6c2251ad54baa51fe86c4912b8ddabc2cb45fd4415`。identity bindingそのものではない。 |
| Backup canonical path | **ESTABLISHED** | HumanのFile Manager観測と一致するlegacy public parentの`public_html/_backup`。Evidenceには不可逆path hashのみ保存。 |
| Backup inventory | **ESTABLISHED** | readable directory、mode `0705`、21 files、87,610 bytes、inventory truncationなし。 |
| Usable backup | **UNKNOWN / BLOCKER before Production mutation** | ファイル存在だけではintegrity・対象範囲・復元可能性を証明できない。archive candidate 0。 |
| DB backup | **ESTABLISHED: NOT FOUND IN CANONICAL DIRECTORY** | DB backup candidate 0。Xserver側の別backup機構や外部backupの有無は**UNKNOWN**。 |
| Restore readiness | **BLOCKER before Production mutation** | integrity、Application/DBの同一時点性、retention、復元手順、isolated restore rehearsalが未成立。 |
| File ManagerとR0 Host Auditのbackup不一致 | **ESTABLISHED: PATH RESOLVED / CAUSE UNKNOWN** | G1 shell inspectionではcanonical pathを確認。R0 PHP Host Auditの`backup_root_unavailable`は観測runtime/path allowlist境界による可能性があるが、原因は断定しない。 |
| Scheduler / queue process snapshot | **ESTABLISHED** | inspection時点でscheduler 0、queue worker 0。継続的不存在を意味しない。 |
| User crontab | **UNKNOWN** | `crontab` commandは存在するが`crontab -l` exit 1。空crontabとaccess不可を区別できない。 |
| External writer code paths | **ESTABLISHED** | Repositoryにnotification delivery、mail、OpenAI transport、scheduled commandsが存在。 |
| External writer Production enablement | **UNKNOWN** | `.env`値を読まず、cron/process snapshotだけでは有効化状態を確定できない。 |
| `.env` file / ownership | **ESTABLISHED** | regular file、application rootとowner/group一致。 |
| `.env` permission | **ESTABLISHED: `0604` / HARDENING BLOCKER** | others-readを許すため、Production cutover前に最小権限化が必要。G1では変更しない。 |

## Hardening decision boundary

推奨targetは、PHP実行主体とownerが同一であることをG3/G5前に再確認した上で`0600`。専用group readが必要な構成だけ`0640`を採用する。ownership/runtime identityを確認せずpermissionを変更してはならない。

## G1 close rationale

G1で要求された各Gapは、ESTABLISHED / UNKNOWN / UNSUPPORTED / BLOCKERへ分類できた。残るBLOCKERはProduction mutation前に閉じる必要があるが、Production-freeのG2 Migration Safety設計・isolated verificationを妨げない。

したがって、Human + ChatGPTの正式Review対象は **G1 CLOSE CANDIDATE / G2 READY** とする。G1 closeはProduction DeployまたはMigrationの承認を意味しない。
