# Company OS｜IR-1 R0 Step 3 Outcome / Step 4 Acceptance v008

## Decision

**STEP 3 PASS / STEP 4 READY FOR SEPARATE AUTHORIZATION**

Step 4はeligibleだが未実行。R0 Audit本体 / Production DeployはHOLDを維持する。

## Step 3 Human Result

- R0_STEP_3：PASS
- bundle SHA-256 verified：true
- overwrite performed：false
- production change scope：single audit archive placement only
- retry available：false
- secret output：false
- Human retry / additional operation：0

## Saved State Evidence

- state SHA-256：8646b3b164291ee0478935a6ebfe021de5f44696e5043d0c3029b19ec683729c
- last step / status：3 / PASS
- Step 2 original STOP：preserved
- Evidence Reconciliation count：1
- Step 3 attempt count：1
- Step 3 helper SHA-256：c979f7d3b90fc224ccab34644c35e5f1319154da291dcdd506e39c8101352330
- Step 3 remote exit code：0
- Step 3 stderr bytes：0
- Step 3 safe error code：null

## Exact Bundle Evidence

- candidate：924af91188cc60d33ff87c91b94ecc1d539566e6
- bundle id：5ba3c0fd459cabe885249d85dd13ffafbe087693435a5e24a483f5ad4a24a4c0
- archive SHA-256：a2cc319f42a7b0b3f84afc3077aeda1af0aa95d40f96e56103b18ad31b448b0d
- manifest SHA-256：a15502cb7e832ef44affecd346d582f4b8550967fb55a2cd5a327d23005b9fd7
- Step 4 helper SHA-256：026a5eb062378b5a46ad50c299047119d4a636b89d02acf3a699437b0c447a58
- archive entries：7,923
- absolute / parent traversal path：0
- symlink / hardlink / device / special entry：0
- raw .env：0
- .env.example：1（template、Secret Evidenceではない）
- required r0-artisan.php：1
- required r0-host-audit.php：1

## Step 4 Acceptance Conditions

Step 4 PASSには、同一attempt内で以下すべてが必要。

### Local / State

1. Step 4 retryが存在しない。
2. last step / statusが3 / PASS。
3. Step 2 original STOPとADOPTED_EXISTING_EMPTY_DIRECTORIES Reconciliationを保持。
4. Step 3 attemptが唯一の1件。
5. Step 3 helper SHA-256が承認済みidentityと一致。
6. Step 3 remote exit code 0 / stderr bytes 0 / safe error null。
7. local archive SHA-256がexact expected hashと一致。

### Remote Pre-extraction

8. candidate directoryがregular directoryかつnon-symlink。
9. candidate内entryが配置済みarchive 1件だけ。
10. archiveがregular fileかつnon-symlink。
11. archive SHA-256がexact expected hashと一致。
12. bundle directoryが不存在。

### Extraction / Post-extraction

13. umask 077で新規bundle directoryを作成。
14. owner / permissionをarchiveから復元せず、bundle内だけへ展開。
15. manifest、r0-artisan.php、r0-host-audit.phpがregular fileとして存在。
16. manifest SHA-256がexact expected hashと一致。
17. raw .env不存在。
18. extracted symlink count 0。
19. candidate内entryがarchive + bundleの2件だけ。
20. PHP CLIが8.2以上9.0未満。
21. fixed allowlist出力だけをHumanへ表示。

## Step 4 Mutation / Safety Boundary

- 許可：private candidate directory内のbundle directory新規作成とexact archive展開
- 禁止：既存file上書き、cleanup、retry、Application bootstrap、Production .env読込、DB接続、Application / DB Audit、Host Audit、Deploy、Migration、DNS / symlink変更
- STOP時：再実行せず、保存済みfailure stage / sanitized receiptをReview

## Provider-free Verification

- PowerShell AST：PASS
- Helper self-verification：PASS
- actual saved-state Step 4 precondition：PASS
- local Bundle / SSH preconditions：PASS
- local archive member safety inspection：PASS
- isolated GNU tar extraction：PASS
- archive / manifest hash：PASS
- required scripts / raw .env absent / symlink 0：PASS
- focused Helper regression：1 test / 75 assertions PASS
- R0 regression：8 tests / 145 assertions PASS
- Production connection during Preflight：0
- Production mutation during Preflight：0

Windows標準tarは日本語pathで停止したため、Productionと同系統のGNU tar 1.35を用いてisolated再検証しPASSした。このlocal tool差による停止でProduction接続・変更は発生していない。

## Recommended Decision

**APPROVE ONE STEP 4 VERIFY AND EXTRACT**

承認される場合もHuman操作は1 Step = 1 Commandとし、PASS / STOP後は自動継続せずHuman + ChatGPT Reviewへ戻る。
