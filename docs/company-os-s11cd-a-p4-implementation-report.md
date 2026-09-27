# Company OS｜S11 Companion Delta A P4 Implementation Report

作業日：2026-09-27 JST  
対象：Conversation Input / Attachment / Voice P4  
判定：**P4 Done候補／P5未開始／人＋ChatGPT P4 Done Review待ち**

## 1｜Base / Development Line

- Base（P3 Evidence HEAD）：`d166b8d112163f8ae83ac31bc6a6c841e97cface`
- Branch：`s11cd-a-conversation-input-attachment-p4`
- Implementation Commit：`450c67185e7a30f8233f3cad34782f14fdcb7612`
- Scope 11：Formal Closed維持（再Openなし）
- S11-CD-A P1 / P2 / P3：Done維持
- CD-AB-C02：Resolved維持
- A-G01：OPEN / 通過済み維持
- P5：未開始

## 2｜P4実装

### 2.1 Attachment / Transcript由来Proposal

- Attachment extractおよびTranscriptのSource Revisionを、既存`AiCommonProposalFactory`へ接続した。
- 第二Proposal Engine、独自Approval、独自Unit Writerは追加していない。
- 既存のcanonical hash、field whitelist、Human Approval、Atomic Apply、Attempt / Item Result、stale、idempotency、既存Unit Writerをそのまま使用する。
- Proposal作成時に、既存messageのimmutable Source Revision relationをProposalへ複製する。本文やbinaryを複製しない。
- Source由来Proposalの作成Evidenceは、同一Proposal transaction内でsanitized auditとして確定する。
- Source revoke / archive / current permission loss後は、既存C02 authorizationにより表示・Approval・Applyをfail-closedとする。

### 2.2 Privacy / Private provenance / Audit

- additive table `ai_common_audit_events`を追加した。
- AuditはOrganization、private Conversation、actor、operation ID、event、subject public ID、result、safe error、許可済みmetadata、fingerprint、UTC時刻のみを保持する。
- transcript本文、Attachment binary、filename、storage path、human / assistant message本文、Proposal bodyをAuditへ複製しない。
- Audit rowはapplicationからupdate / deleteできないimmutable modelとした。
- source付きProposalでは、Source Revision件数、lineage version、既存Proposal operation、target typeだけを記録する。

### 2.3 Usage Ledger

- 既存`ai_usage_ledgers`へ、application operation ID、usage unit、usage quantity、media durationをadditiveに追加した。
- Application operationとProvider attemptを分離し、同一operation / purpose / attemptをuniqueにした。
- Providerが返した明示usageだけを記録し、音声durationから請求usage / costを推測しない。
- known costはprice version / currencyとともに記録し、unknown costは`NULL`のまま保持する。0へ変換しない。
- resultは`success` / `failed` / `unknown` / `discarded`を区別する。
- Audit / Usageには本文、binary、transcript内容、filename、storage pathを保存しない。

### 2.4 Failure / retry / idempotency / response-loss

- Provider送信前のcurrent authorization失敗はProvider attemptへ数えない。
- storage integrity失敗はapplication auditを残すが、Provider Usageを作らない。
- timeout / response-lossは`unknown`として1 attemptを記録し、同じoperation IDをblind retryしない。
- Provider response取得後のMembership / policy / Attachment revoke / state変更は`discarded`とし、Transcript / Message / Proposalへ公開しない。
- sequential replayは既存結果へ収束し、Usage / Audit / Proposal / Unit Apply / Notificationを重複させない。
- Attachment transcriptionの同一operation競合は、unique競合の敗者をsafe validationへ収束させ、Providerを二重呼出ししない。
- Provider I/O中にDB transaction / row lockを保持しない。

## 3｜Schema / Migration

Migration：

- `database/migrations/2026_09_27_000007_add_s11_companion_delta_a_p4_evidence.php`

additive変更：

- `ai_usage_ledgers.application_operation_id`
- `ai_usage_ledgers.usage_unit`
- `ai_usage_ledgers.usage_quantity`
- `ai_usage_ledgers.media_duration_ms`
- unique：application operation × purpose × attempt
- new table：`ai_common_audit_events`
- Audit FK 3件、operation unique、actor / subject index

既存Conversation / Message / Attachment / Transcript / Source / Proposal / Usage IDおよび意味は変更していない。既存Dataの推測backfillは行っていない。P4 Evidenceが存在する場合のdestructive rollbackは拒否する。

## 4｜P4 Focused Verification

`tests/Feature/S11CompanionDeltaAP4Test.php`

- **9 PASS / 52 assertions**
- transcription success / replay：Operation、Revision、Usage、Audit各1件
- known cost：explicit usage unit / quantity / price version / currencyを保持
- unknown cost：`NULL`維持
- response-loss：`unknown`、blind retryなし、Provider call 1件
- Provider I/O中revoke：`discarded`、Transcript 0件
- pre-provider storage failure：Provider call 0、Usage 0、sanitized audit 1件
- Temporary Voice：application operation / Provider attempt分離
- bounded Attachment extract由来Proposal：lineage維持、本文非複製
- Transcript由来Proposal：既存Engine / Approval / Unit Writer / idempotent Apply、Capture 1件、Notification 1件
- Source revoke後のProposal Approval / Unit write：fail-closed

## 5｜Regression / C02

P4 + P3 + P2 + P1 + Scope 11 / C02 bundle：

- SQLite：**59 PASS / 330 assertions**
- MariaDB 10.11.19：**59 PASS / 330 assertions**
- P4：9 PASS
- P3：8 PASS
- P2：12 PASS
- P1：7 PASS
- Scope 11 / C02：23 PASS

C02 Focused：

- F01｜推移的Source lineage：PASS維持
- F02｜immutable revision / reselect：PASS維持
- F03｜retry直前current Policy再認可：PASS維持
- F04｜Provider I/O中Membership失権：PASS維持
- F05｜Provider I/O中reselect：PASS維持

既存Proposal Engine、canonical hash、whitelist、Capture L1、Action / Project L2、Business Domain L3、stale、Atomic Apply、idempotency、Undo、S9 AI payloadの意味は変更していない。

## 6｜SQLite Evidence

通常local DBではなく、空の一時SQLiteを使用した。

- 全102 Migration：PASS
- `PRAGMA integrity_check`：`ok`
- P4 rollback：PASS（101 Migration、P4 table削除）
- P4 reapply：PASS（102 Migration、P4 table復帰）
- reapply後`PRAGMA integrity_check`：`ok`
- 検証後、一時SQLiteを削除した。

## 7｜MariaDB 10.11 Evidence

通常local DB / Production DB / IR-1 DBではなく、公式MariaDB 10.11.19 binary、loopback `127.0.0.1:13349`、今回専用datadir、synthetic schemaを使用した。

- Version：MariaDB 10.11.19
- 全102 Migration：PASS
- P4 audit table：1
- P4 audit FK：3
- `ai_common_audit_events` / `ai_usage_ledgers`：`CHECK TABLE OK`
- P4 rollback：PASS（101 Migration、audit table 0）
- P4 reapply：PASS（102 Migration、対象table `CHECK TABLE OK`）
- P4 + P3 + P2 + P1 + Scope 11 / C02：59 PASS / 330 assertions

### Independent process concurrency

- 2つの独立PHP processが同一Attachment transcription operationを同時開始した。
- 1 workerが成功し、1 workerはProviderを呼ばずsafeに収束した。
- 確定値：operation 1、Transcript Revision 1、Usage 1、Audit 1、Provider call 1。
- duplicate Transcript / Usage / Audit / Provider I/Oなし。

検証後、synthetic schemaをdropし、専用MariaDB processを停止した。port 13349 listener 0、専用datadir / storage / log削除済み。

## 8｜Full SQLite Suite

- **596 PASS**
- **5 FAIL（既知baseline）**
- **16 SKIP**
- **4,610 assertions**
- P4由来Regression：0

5 FAILはP3 Final Reviewと同分類：

1. Scope 9日付依存fixture：3件
2. `CompanyNavigationTest`既存baseline：1件
3. `ReleaseHardeningTest`：固定済みIR-1 R0のMigration 95に対しRepository現在値が102である差：1件

期待値弱体化、PASS化、SKIP追加、IR-1 Test / Manifest / RC / Artifact更新は行っていない。

## 9｜Compatibility / Product Decision

- Scope 11 Formal Closed Contract：変更なし
- P1 / P2 / P3 Contract：変更なし
- CD-AB-C02：Resolved維持
- 第二Proposal Engine：追加なし
- Unit Writer迂回：なし
- Tenant / Permission / Privacy緩和：なし
- 既存Data推測変換：なし
- destructive / irreversible Migration：なし
- Providerへの許可外送信：なし
- Product Decision追加：不要
- C01 / C03〜C08への新規Compatibility Blocker波及：なし

## 10｜P5への引継ぎ

P4では新規UI / JavaScript / CSS変更がないため、Browser / 390pxをP4 PASSとして再取得していない。P5で既存v002 Contractに従い、実Browser / 390px、実codec / playback、追加failure / degraded mode、最終Evidence統合を行う。

Release Verificationへ残す環境EvidenceはP4 Development PASSへ読み替えない：

- 実malware scanner / audio probe
- 実Provider / Secret / Model / Price
- retention / Legal条件
- host storage / backup / orphan cleanup
- Scope 10：実SMTP Provider受信
- Scope 10：Android Chrome PWA / Push / Deep Link

## 11｜保護境界

- IR-1：非変更
- Product Master：非変更
- master branch：非変更
- Production：未接続
- Deploy：未実施
- 通常local DB：未接続・非変更
- Delta B：未開始・非変更
- 保護対象の未追跡Master 2点：変更・stage・commitなし

## 12｜最終判定

- S11 Companion Delta A P4：**Done候補**
- Focused / Regression / SQLite / MariaDB / independent-process concurrency：必要Evidence取得済み
- Full Suite：既知baseline 5 FAIL、新規Regression 0
- C02：Resolved維持
- Product Decision追加：不要
- P5：未開始
- 停止地点：人＋ChatGPT P4 Done Review待ち

Evidence Commit / origin / working treeの最終値は、本Reportを含むEvidence commitをpush後、完了報告で固定する。
