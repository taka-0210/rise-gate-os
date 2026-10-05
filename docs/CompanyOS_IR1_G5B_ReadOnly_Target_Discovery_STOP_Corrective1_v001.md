# Company OS - IR-1 G5-B Read-only Target Discovery STOP / Corrective-1 Ready v001

- Date: 2026-10-06 JST
- Gate: G5-B READ-ONLY TARGET DISCOVERY
- Prior Human confirmation: G5-A Target Anchor Provisioning reflection wait resolved
- Frozen RC: `924af91188cc60d33ff87c91b94ecc1d539566e6`
- Repository HEAD before this Evidence: `d277d8da19eefd0c276975fecf04f725aa6fff8d`
- Production Deploy / Migration / DNS change / SSL change: **NO-GO maintained**

## Decision

**G5-B STOP / CORRECTIVE-1 PRODUCTION-FREE READY / HUMAN ONE-COMMAND GATE**

The first G5-B connection established the exact SSH boundary and completed the
remote read-only contract with exit code 0. The local helper then stopped at
the target-anchor rebinding acceptance check. No Production mutation, database
connection, POSIX capability rehearsal, retry, Deploy, Migration, DNS change,
or SSL change occurred.

The sanitized remote observation was parsed in memory but was not persisted
before the acceptance stop. Therefore the exact mismatching target field must
remain **UNKNOWN**. It is not valid to infer a server-side topology defect from
the local stop code alone.

## Production-free rebinding

The previous SSH / HOME / server / filesystem discovery result was not reused
as current target Evidence. Local preflight rebound the new G5-B operation to:

| Boundary | Rebound Evidence |
|---|---|
| SSH alias | `company-os-production` |
| Expanded SSH host | `sv17033.xserver.jp` |
| SSH port | `10022` |
| Identity type | ED25519 |
| Identity fingerprint | `SHA256:d/dPUPa6KjfXVOpG1Sqqp66GMFjYtxYUR1dqhaRBQBg` |
| Required Host Key algorithm | `ssh-ed25519` |
| Registered Host Key fingerprint | `SHA256:lkUHlNS7K7nVe/slV97qC08nvzqNzsgUsVD9p2Q1KCs` |
| StrictHostKeyChecking | required |
| BatchMode / password prompts | enabled / zero |
| Remote auto-command / proxy | forbidden / none |
| G5-B read-only script SHA-256 | `11796c27cdf80ff695ec8d0f4053b991ea793a408334c000b2c06bbcb9922f14` |
| Initial helper SHA-256 | `db586de2c056d3c08caa832542c6b243a8fa756d89b8b9c6b641223ddddd1696` |

The remote script discovers the actual login HOME first and derives the
`company-os.jp` and legacy paths from that value. It does not output the raw
HOME or login name; exact identity is represented by SHA-256 plus numeric UID /
GID and self-owner comparisons.

## First G5-B attempt

| Evidence | Result |
|---|---:|
| SSH connection attempts | 1 |
| Native SSH process | started |
| SSH authentication | established |
| Remote shell | established |
| Remote read-only contract | complete |
| Remote exit code | 0 |
| stdout bytes | 2,260 |
| stdout SHA-256 | `43b7b0813f05b65f8f0249e17b9f5fb757af2d1836a5e61095302219938e6425` |
| stderr bytes | 0 |
| stderr SHA-256 | `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855` |
| Local acceptance | STOP |
| Safe error code | `TARGET_ANCHOR_REBINDING_FAILED` |
| Production file / directory / symlink / permission mutation | 0 |
| Database connection / SQL | 0 |
| POSIX capability rehearsal | not executed |
| Retry | 0 |

The one-shot initial state is retained locally at the candidate-bound release
audit boundary. Raw remote stdout and stderr were not stored.

## DNS / HTTP / SSL discovery

Observed read-only on 2026-10-06 JST:

| Item | Result |
|---|---|
| `app.company-os.jp` A record | `85.131.221.130` |
| HTTP | `301 Moved Permanently` to HTTPS |
| HTTPS certificate validation | **FAIL / name mismatch** |
| Presented certificate subject | `CN=*.xserver.jp` |
| Presented certificate SAN | `*.xserver.jp`, `xserver.jp` |
| `app.company-os.jp` name match | false |
| HTTPS response with validation bypassed for observation only | `200 OK`, nginx |
| Observed page title | `無効なURLです` |
| Observed response body bytes | 677 |
| Observed response body SHA-256 | `fb62bb4125f10eae968545207d300f3cac2711437f28a1f51bbefbe4d4d09ac1` |

The insecure HTTPS observation is Evidence only. It is not an approved client
behavior and does not waive TLS validation. The public route currently exposes
an Xserver default / invalid-URL entry rather than Company OS. DNS and TLS are
not changed by G5-B and remain outside this operation.

## Evidence still required from the target

Because the initial helper did not persist the sanitized parsed map before its
local acceptance stop, the following exact observations remain pending rather
than guessed:

- actual HOME binding hash and environment match
- `company-os.jp` domain root type, owner UID, group GID and mode
- `public_html/app.company-os.jp` directory / symlink type, owner / group / mode
- target and public filesystem device / filesystem type
- target topology collision state
- legacy domain / application / public entry state
- target-versus-legacy canonical and physical separation
- default entry file state and checksum
- unexpected entry count

## Corrective-1 hardening

Corrective-1 is prepared but has not connected to Production.

- requires the exact initial STOP state and rejects any different predecessor
- uses a separate candidate-bound one-shot Evidence directory
- reuses the exact read-only remote script SHA-256
- retains strict host, Host Key and identity fingerprint binding
- persists the sanitized observation before target acceptance evaluation
- records the exact names of mismatching acceptance fields
- stores no raw HOME, login name, secret, raw stdout or raw stderr
- contains no POSIX rehearsal path and no Production mutation command
- returns to the Human gate on PASS or STOP without automatic retry

## Production-free verification

| Verification | Result |
|---|---:|
| remote shell syntax | PASS |
| PowerShell AST parse | PASS |
| Corrective-1 VerifyOnly | PASS |
| SSH config / identity / Host Key binding | PASS |
| G5 focused regression | 9 tests / 117 assertions PASS |
| Production connection during Corrective-1 preparation | 0 |
| Production mutation during all G5-B work | 0 |

## Next Human gate

Corrective-1 is exactly one Human command:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File "C:\xampp\htdocs\rise-gate-os\deployment\g5-target-environment\Invoke-G5BReadOnlyTargetDiscovery.ps1" -Attempt Corrective1
```

After execution, return the result to Human + ChatGPT review. A PASS does not
authorize or automatically start G5-C POSIX capability rehearsal.

## NO-GO maintained

- G5-C POSIX capability rehearsal
- target directory / symlink / permission mutation
- application placement
- Production Deploy
- Migration / SQL
- additional DNS change
- SSL change
