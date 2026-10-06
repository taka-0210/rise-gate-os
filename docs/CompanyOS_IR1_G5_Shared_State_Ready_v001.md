# Company OS IR-1 / G5 Shared State Human Gate Ready v001

Date: 2026-10-07 JST

Candidate: `924af91188cc60d33ff87c91b94ecc1d539566e6`

Status: **PRODUCTION-FREE PREPARATION PASS / ONE HUMAN GATE READY**

## Outcome

G5 Shared State has been prepared and verified without a Production
connection or mutation. The preparation does not authorize or perform the
Shared State operation. It makes exactly one future Human command available.

The operation is bound to the approved NewTarget Skeleton Formal PASS and
keeps G5 OPEN. It does not authorize Application placement, Migration,
public-entry changes, DNS, SSL or Deploy.

## Inventory and source contract

The frozen candidate's `.env.example` and `config/*.php` form an exact sorted
allowlist of 172 environment keys:

- allowlist SHA-256:
  `d408775076252cb15ac0438b1d4ccc762f3f366e9ea10517e3e0f8b3f0d496ec`
- required source keys are validated without outputting names and values in
  remote Evidence;
- unknown Legacy-only keys are omitted;
- NewTarget URL, production/debug posture, JST, secure cookie and fixture
  posture are fixed by the candidate contract;
- a missing or inconsistent requirement stops before publish.

The Legacy `.env` is therefore a secret-value source, not a file image to be
published unchanged. Its currently observed `0604` mode is not propagated.
The NewTarget `.env` must be a regular file owned by `20046:1000` with mode
`0600`.

No secret value is written to Repository, audit Evidence, terminal output or
a local persistent file. Transfer uses pinned OpenSSH identities and Host Keys
for both servers. `scp -3` relays encrypted bytes into candidate-bound target
staging; its output is discarded. The raw source `.env` staging file is forced
to `0600` and removed before publish.

## Shared storage topology

Initial seed scope is only the Legacy `storage/app` tree. It is rejected if it
contains symlinks or special entries. Source and target manifests bind each
relative type/path and file size/hash, while paths themselves are not emitted
to Evidence.

NewTarget shared storage is created with:

- directory mode `0750`;
- file mode `0640`;
- owner/group `20046:1000`;
- empty runtime directories for private/public app data, framework cache,
  sessions, testing, views and logs;
- PHP CLI UID `20046` read/write/delete probes.

This is an initial seed. A final storage delta remains mandatory in a separate
Human gate before public-entry or traffic binding. Web-runtime proof is also
deferred until an Application release is bound.

## Backup, rollback and cleanup

This operation reads but never changes the Legacy source. It is not a backup
and does not close the existing backup/DB recovery gaps:

- `usable_backup=unknown`
- `db_restore_readiness=blocker`

Before publish, cleanup is limited to the exact candidate-bound staging root
after marker/owner/mode checks. If a publish has started, `.env` or storage is
retained and the helper returns STOP for a separate Human rollback decision.
No automated deletion of published shared state is allowed.

The three pre-existing public-entry files remain unchanged and
`PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION=PENDING_G5_PUBLIC_ENTRY_GATE`.

## Evidence Contract

The helper writes incremental local state before starting any SSH process and
uses atomic initial move / existing-file replace semantics. It records:

- candidate and implementation hashes;
- exact source and target SSH binding disposition;
- connection/process truth;
- environment and storage hashes/counts/bytes only;
- owner/mode/runtime probe disposition;
- cleanup, rollback and residual state;
- protected-boundary disposition;
- unchanged blockers and excluded operations.

Raw remote output and secret values are not stored. PASS or STOP is terminal;
the Evidence root prevents replay and `retry_available=false`.

Production-free implementation bindings:

- Contract SHA-256:
  `cc11ad5a67e0f3f869da15091d91740f9e4d584d90995bd86d38ae22d7a282d1`
- Human helper SHA-256:
  `09397ca8f568818337a82fffd5d42e5cc84d96db7822754e1d03d829176a825d`
- Source inspector SHA-256:
  `72f7d8016d6d42dd25ceb44994b00a31e279bc7daad3e4fb5911b00f6539d683`
- Target manager SHA-256:
  `6b019523e95688ebc013f01e3bfc58d895e0e6afbe6af1e34e193da8fc3ddf7f`
- Environment projector SHA-256:
  `5f438829ab57f3327797f9889cd6e9287219e644f2dd0dd5b5d99b12090f9509`

## Production-free verification

- PHP syntax: PASS
- PowerShell real Human path, persistence-only: PASS
- PowerShell real Human path, VerifyOnly: PASS
- frozen candidate allowlist reconciliation: PASS
- secret redaction fixture: PASS
- cleanup/partial-publish simulation: 7 scenarios / 36 assertions PASS
- focused G5 regression: PASS
- full Repository regression attempted: 775 passed / 17 skipped / 8 failed;
  the failures are outside this delta (one existing Company Navigation redirect
  expectation and seven G2 Preflight V2 tests whose one-shot Evidence root is
  already present). The Company Navigation failure reproduces in isolation.
- Production / SSH / HTTP / DB connection during preparation: 0
- Production mutation during preparation: 0

## ONE G5 SHARED STATE HUMAN GATE

### What the command may create

- `/home/xs377816/company-os.jp/company-os-app/shared/.env`
- `/home/xs377816/company-os.jp/company-os-app/shared/storage/`
- one temporary candidate-bound staging root that must be removed on PASS

### Secret source

Allowlisted values from the exact Legacy application `.env`, with the
NewTarget candidate-fixed values applied on the target. No blind whole-file
publication is permitted.

### Mutation boundary

Only the NewTarget `company-os-app/shared` staging and the two exact shared
state destinations above. Legacy, public entry, releases, `current`,
`current.previous`, DB, Migration, DNS, SSL and Deploy are excluded.

### Permission / owner

- `.env`: `20046:1000`, regular file, `0600`
- storage directories: `20046:1000`, `0750`
- storage files: `20046:1000`, `0640`

### Acceptance condition

PASS requires the exact environment projection hash, exact initial storage
manifest, runtime probes, raw source `.env` removal, staging and local config
residual zero, public entry unchanged, blockers retained and sanitized local
Evidence complete.

### Human command

Run exactly once from PowerShell:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File "C:\xampp\htdocs\rise-gate-os\deployment\g5-target-environment\Invoke-G5SharedState.ps1"
```

Do not rerun after PASS or STOP. Return the terminal PASS/STOP contract to
Human + ChatGPT. A PASS does not authorize any next operation.
