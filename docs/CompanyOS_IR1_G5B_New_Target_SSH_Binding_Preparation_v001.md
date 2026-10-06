# Company OS - IR-1 G5-B New Target SSH Binding Preparation v001

- Date: 2026-10-06 JST
- Gate: G5-B READ-ONLY TARGET DISCOVERY / NEW TARGET SSH BINDING
- Frozen RC: `924af91188cc60d33ff87c91b94ecc1d539566e6`
- Status: **PRODUCTION-FREE PREPARATION COMPLETE / HUMAN SSH SETTINGS WAITING**

## Human-confirmed target

| Item | Value |
|---|---|
| Xserver Server ID | `xs377816` |
| Xserver host | `sv17169.xserver.jp` |
| Target server IP | `85.131.221.130` |
| `app.company-os.jp` A record | `85.131.221.130` |
| PHP | `8.3.33` |

Production-free DNS verification confirmed that both
`sv17169.xserver.jp` and `app.company-os.jp` resolve to
`85.131.221.130`.

## Superseded binding

The legacy binding below is not authorized for the new target:

```text
company-os-production -> sv17033.xserver.jp
```

The legacy server resolves to a different IP and contains the current legacy
application topology. Its SSH identity, account, key authorization, HOME,
filesystem and Host Key are not inherited by the new target.

## Xserver SSH contract

The current Xserver official manual establishes:

- SSH user is the Server ID
- SSH port is `10022`
- authentication is public-key only
- SSH must be enabled in Server Panel
- the public key must be registered in Server Panel
- Server Panel displays Host Key fingerprints for out-of-band verification

References:

- <https://www.xserver.ne.jp/manual/man_server_ssh.php>
- <https://www.xserver.ne.jp/manual/man_server_ssh_connect_tera.php>

## Local preflight

Before preparation:

| Check | Result |
|---|---|
| new host reference in local SSH config | absent |
| new target alias | absent |
| dedicated new-target identity | absent |
| `[sv17169.xserver.jp]:10022` known_hosts entry | absent |
| Production SSH connection | 0 |

A dedicated ED25519 identity was generated locally for this target only:

| Item | Value |
|---|---|
| private-key leaf | `codex-company-os-target-production` |
| public-key leaf | `codex-company-os-target-production.pub` |
| fingerprint | `SHA256:GvM1nK35B8W444sHzoURREhsjSFmY5JTOfxqXG1IT9g` |
| comment | `codex-company-os-target-production@xs377816` |

The old `codex-company-os-production` identity was not reused.

Public key authorized for Human registration:

```text
ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIMgIpZoAjmN8h4m/UjxDf7ia49vEWeMvBF6iCfT6DzKB codex-company-os-target-production@xs377816
```

The private key is not stored in the Repository or Evidence.

## Human SSH settings gate

In the `xs377816` Server Panel:

1. Confirm SSH is enabled.
2. Register the exact public key above. If the UI indicates that registration
   would replace an existing key required by another operator, stop without
   replacing it and return to Human + ChatGPT review.
3. Return the Server Panel Host Key fingerprints, including the ED25519
   fingerprint, without making an SSH connection.

No local Host Key will be trusted from network observation alone. After the
Panel fingerprint is supplied, the next Production-free preparation will bind
the exact host, port, Server ID, dedicated identity and verified Host Key to a
new candidate-bound known-hosts file and G5-B helper.

## Not yet authorized

- SSH connection to `sv17169.xserver.jp`
- G5-B remote discovery execution
- directory / permission / symlink change
- POSIX capability rehearsal
- Deploy / Migration / SQL
- DNS / SSL change

After SSH binding is established and verified, a separate one-command Human
Gate will be presented for a maximum of one G5-B read-only discovery attempt.
