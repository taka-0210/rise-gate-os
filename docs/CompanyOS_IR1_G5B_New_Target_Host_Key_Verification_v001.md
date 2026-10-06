# Company OS - IR-1 G5-B New Target Host Key Verification v001

- Date: 2026-10-06 JST
- Gate: G5-B READ-ONLY TARGET DISCOVERY / NEW TARGET SSH BINDING
- Frozen RC: `924af91188cc60d33ff87c91b94ecc1d539566e6`
- Prior Repository HEAD: `7ab893c7b793d6424cc65ffa852bff478829df5a`
- Status: **HOST KEY VERIFIED / ONE-COMMAND HUMAN GATE READY**

## Trusted target binding

| Item | Value |
|---|---|
| Xserver Server ID / SSH user | `xs377816` |
| SSH host | `sv17169.xserver.jp` |
| SSH port | `10022` |
| target IP | `85.131.221.130` |
| `app.company-os.jp` A record | `85.131.221.130` |
| authentication | public key |
| Host Key algorithm | `ssh-ed25519` |

The legacy `company-os-production -> sv17033.xserver.jp` alias is not used by
the new-target profile.

## Host Key trust-anchor verification

Human supplied the ED25519 fingerprint displayed by Xserver Server Panel:

```text
SHA256:JW8I6QkDccWlz2UNvbmnKlZzVn9Dc3GL7JLAmUjSLt8
```

The network Host Key was then retrieved without SSH authentication or remote
command execution. The observed ED25519 fingerprint was:

```text
SHA256:JW8I6QkDccWlz2UNvbmnKlZzVn9Dc3GL7JLAmUjSLt8
```

| Verification | Result |
|---|---:|
| Panel fingerprint / network fingerprint | exact match |
| Host / port | exact match |
| DNS target IP | exact match |
| SSH authentication | not attempted |
| remote shell | not attempted |
| remote command | not attempted |
| Production mutation | 0 |

The matched ED25519 public Host Key was written to a candidate-bound local
known-hosts file. It was not added to or inferred from the legacy Server's
global known-hosts identity.

| Local Evidence | SHA-256 |
|---|---|
| candidate known-hosts | `ba34cd1acb3c594d282a6c78a4352971f9f4831097c30f4423f6a326ceaa8983` |
| Host Key verification receipt | `834b0309366619f960c12e34a27d41f3633e2a8cf0f09fdc29fa18b96d4526ba` |

## Dedicated authentication identity

| Item | Value |
|---|---|
| identity leaf | `codex-company-os-target-production` |
| algorithm | ED25519 |
| fingerprint | `SHA256:GvM1nK35B8W444sHzoURREhsjSFmY5JTOfxqXG1IT9g` |
| Xserver key status | Human-confirmed ON |
| Xserver access restriction | Human-confirmed ON |

The legacy identity is not reused.

## G5-B new-target helper binding

The additive `NewTarget` profile binds:

- exact host `sv17169.xserver.jp`
- exact user `xs377816`
- exact port `10022`
- dedicated identity fingerprint
- Panel-verified ED25519 Host Key fingerprint
- candidate known-hosts SHA-256
- exact read-only remote script SHA-256
- `BatchMode=yes`, `StrictHostKeyChecking=yes`, zero password prompts
- one connection attempt and one-shot local Evidence state
- no legacy SSH alias
- no POSIX capability rehearsal path

| Artifact | SHA-256 |
|---|---|
| read-only remote script | `11796c27cdf80ff695ec8d0f4053b991ea793a408334c000b2c06bbcb9922f14` |
| helper after NewTarget binding | `3f95f8a7386072c78af3250986d155280054e6b50563d4f3b58d6f0b9d468d16` |

## Production-free verification

| Verification | Result |
|---|---:|
| PowerShell AST | PASS |
| NewTarget VerifyOnly | PASS |
| identity fingerprint binding | PASS |
| candidate known-hosts hash binding | PASS |
| ED25519 Host Key fingerprint binding | PASS |
| G5 focused regression | 10 tests / 127 assertions PASS |
| authenticated SSH connection | 0 |
| Production mutation | 0 |

## One-command Human Gate

The following command authorizes a maximum of one authenticated SSH connection
and runs only the G5-B read-only target discovery contract:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File "C:\xampp\htdocs\rise-gate-os\deployment\g5-target-environment\Invoke-G5BReadOnlyTargetDiscovery.ps1" -Attempt NewTarget
```

The command is terminal on PASS or STOP and does not retry automatically.
After execution, return the output to Human + ChatGPT review.

## Still NO-GO

- G5-C POSIX capability rehearsal
- directory / permission / symlink mutation
- application placement
- Deploy / Migration / SQL
- DNS / SSL change

A G5-B PASS does not authorize or automatically start any item above.
