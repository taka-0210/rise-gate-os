# Company OS｜IR-1 R0 Step 3 Placement Preflight v007

## Decision

**READY FOR ONE STEP 3 AUDIT BUNDLE PLACEMENT**

Step 3だけをHumanが1回実行できる。Bundle展開、Application / DB Audit、
Host Audit、Production Deployは未承認のまま維持する。

## Exact Binding

- candidate：924af91188cc60d33ff87c91b94ecc1d539566e6
- bundle id：5ba3c0fd459cabe885249d85dd13ffafbe087693435a5e24a483f5ad4a24a4c0
- bundle SHA-256：a2cc319f42a7b0b3f84afc3077aeda1af0aa95d40f96e56103b18ad31b448b0d
- Step 3 helper SHA-256：c979f7d3b90fc224ccab34644c35e5f1319154da291dcdd506e39c8101352330

## Preconditions

- STEP 2 RECONCILED / STEP 3 ELIGIBLE
- original Step 2 STOP Evidence preserved
- reconciliation disposition：ADOPTED_EXISTING_EMPTY_DIRECTORIES
- actual saved state contract：PASS
- local Bundle SHA-256：PASS
- SSH configuration / registered host key local contract：PASS
- Production connection during Preflight：0
- Production mutation during Preflight：0

## Placement Contract

1. candidate directoryが現在も空であることをremote prepareで再確認する。
2. final archive、bundle directory、専用staging directoryの不存在を確認する。
3. mode 0700相当の専用 .step3-placement directoryを原子的に作成する。
4. SCP destinationをそのstaging directory内のexact archive名だけに限定する。
5. staged archiveのSHA-256をexact expected hashと照合する。
6. final archive不存在を再確認する。
7. force optionなしのhard linkでfinal archiveを作成する。既存finalがあればfail closedする。
8. final archiveのSHA-256を再照合する。
9. 成功時のみStep 3自身のstaging file / directoryを削除する。

STOP時は再実行しない。partial stateの可能性を保存Evidenceから判定し、
Human + ChatGPT Reviewへ戻る。

## Output Safety

- stdoutは固定allowlistのみ
- raw SSH / SCP stderrは表示・保存せずSHA-256とbyte countだけを保持
- Secret / Credential / absolute home / raw identifierは出力しない
- retry available：false

## Automated Verification

- PowerShell AST：PASS
- Helper self-verification：PASS
- Step 3 actual saved-state precondition：PASS
- Step 3 local preconditions：PASS
- isolated exact-hash placement：PASS
- isolated existing-final collision / non-overwrite：PASS
- focused Helper regression：1 test / 66 assertions PASS
- R0 regression：8 tests / 136 assertions PASS

初回self-verificationでWindows PowerShell 5.1のsingle-item if output unrollingを検出した。
配列代入を明示化してCorrectiveし、上記Regressionを再実施してPASSした。

## Hold Boundary

- Bundle extraction：HOLD
- Application / DB Audit：HOLD
- Host Audit：HOLD
- R0 Audit本体：HOLD
- Production Deploy / Migration / .env / DNS / symlink変更：HOLD
- Step 3 PASS / STOP後の自動継続：禁止
