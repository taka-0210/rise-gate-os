# Company OS｜IR-1 G2 Remote Runtime Boundary Analysis

Date: 2026-10-02 JST

## Recommended Decision

**DO NOT RETRY THE SAME G2 PREFLIGHT**

**PREPARE ONE INDEPENDENT DB-FREE REMOTE RUNTIME DIAGNOSTIC**

**G2 OPEN / Production Deploy and Migration NO-GO**

## Production-free audit result

既存G2 Helper、生成remote script、Windows PowerShell 5.1 native stdin/stdout、Bash heredoc、PHP stdin contractを監査した。

既存G2 remote scriptの実生成結果：

- UTF-8 byte count: `16675`
- CRLF count: `2`
- LF-only count: `372`
- lone CR count: `0`
- heredoc delimiter terminated by CRLF: true

既存scriptは大部分がLFだが、PHP source末尾とheredoc delimiterに`Environment.NewLine`を使用しており、Windowsではdelimiter行がCRLFになる。Bash heredocはdelimiterの完全一致を必要とするため、末尾CRを含むdelimiterは期待delimiterと一致しない。

これはCorrective-3の、

- remote exit `1`
- stdout `0 bytes`
- stderr `808 bytes`
- sanitized shell/PHP failure Evidenceなし

と整合する確定的なtransport defectである。raw stderrを保持していないため、過去attemptで実際に返されたstderr本文との直接一致まではEvidence化できないが、同じG2をそのまま再試行する合理性はない。

## Independent Runtime Diagnostic contract

Helper:

`deployment/r0-audit/Invoke-G2RemoteRuntimeDiagnostic.ps1`

Identity:

- Candidate: `924af91188cc60d33ff87c91b94ecc1d539566e6`
- Helper SHA-256: `77a4720ec377afaee740f74708eb76b46e598cf4416118b0435c28413f349882`
- Remote script SHA-256: `a260ba374b4898a80596e4e6e3ac6a1d844bbcdb149e57a09700cb2e23a7315e`
- Corrective-3 STOP state binding: `83469c014b729870a9331894a8fd8eef25994103fa9246357afab077f0a61b70`

Diagnostic observes only:

1. SSH remote shell established
2. PHP CLI discovered
3. PHP CLI interpreter started
4. Minimal PHP received through stdin and executed
5. Fixed sanitized stdout token returned
6. Remote exit code captured

## Explicitly excluded boundaries

- DB connection
- `.env` read
- Laravel/Application bootstrap
- Composer autoload
- SQL
- Migration / DDL / data mutation
- Production file creation/change/delete
- permission change
- backup operation
- symlink change
- Deploy

Remote script is LF-only and does not use a heredoc. PowerShell pipeline transport is not used. UTF-8 bytes are written directly to the native process stdin stream.

## Safety and load

- SSH connection: maximum 1
- Remote shell: one non-interactive `bash -s`
- PHP process: maximum 2 short-lived CLI invocations
- DB connection/query: 0
- Production filesystem read of business/application files: 0
- Production filesystem mutation: 0
- Secret/raw path/raw exception output: 0
- Retry: 0

The diagnostic emits one allowlisted sanitized JSON result and keeps only sanitized Evidence plus stdout/stderr hash and byte count.

## Acceptance Condition

PASS requires all six runtime stages to be true, exact sanitized output validation, remote exit `0`, and empty stderr.

Any missing stage, nonzero exit, unexpected stdout, or stderr is STOP. The Human must not retry and must return to Human + ChatGPT Review.

## Automated Verification

- Existing G2 heredoc CRLF reproduction: PASS
- Diagnostic remote script LF-only: PASS
- Local PHP stdin/stdout byte-stream transport: PASS
- Database boundary absent: PASS
- PowerShell parse: PASS
- Local preconditions / exact prior Evidence binding: PASS
- Focused R0/G1/G2 regression: **15 tests / 320 assertions PASS**
- Production connection during analysis/verification: 0
- Production mutation during analysis/verification: 0

## Gate

The independent Runtime Diagnostic requires separate Human + ChatGPT authorization before one Production read-only execution. No execution command is presented in this package.

Only after Runtime Diagnostic PASS should the existing G2 preflight transport be corrected to an LF-only controlled byte stream and considered for a separately authorized retry.
