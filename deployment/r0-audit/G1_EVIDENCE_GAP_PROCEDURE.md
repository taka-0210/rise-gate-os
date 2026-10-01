# IR-1 G1 Evidence Gap Closure Procedure

## Current gate

- R0 Step 1-6: COMPLETE / Evidence Acquisition PASS
- G1: READY FOR ONE SEPARATELY AUTHORIZED READ-ONLY INSPECTION
- G2 Migration Safety: NOT STARTED
- Production Deploy / Migration: NO-GO

## Scope

The G1 helper closes only the gaps that can be observed without changing Production:

1. Bind the legacy release marker to a release manifest or Git HEAD when either identity exists.
2. Compare three critical application files with the exact IR-1 candidate without claiming that a partial fingerprint is a full release identity.
3. Discover an allowlisted or bounded _backup directory and store only its class, irreversible path hash, mode, counts, sizes, time bounds, and file-type counts.
4. Inspect the current user's crontab and process snapshot as counts only.
5. Inspect .env type, mode, and ownership alignment without reading its values.

The helper does not read business data, backup contents, cron command text, .env values, credentials, raw paths, or raw exceptions.

## Safety contract

- Human operation: **1 Step = 1 Command / PASS or STOP**.
- One SSH connection attempt; retry is forbidden.
- Batch mode, strict registered host-key checking, password prompts disabled, forwarding disabled.
- Remote operations are read-only: test, stat, bounded find, sha256sum, git rev-parse, crontab -l, and /proc reads.
- Production mutation = 0.
- No upload, create, delete, rename, permission change, backup, deploy, migration, DB connection, DNS, SSL, or symlink change.
- Raw stdout/stderr are not retained. Only allowlisted sanitized Evidence is written locally.

## Evidence interpretation

- Marker binding is **ESTABLISHED** only if the marker equals a valid deployed release manifest identity or deployed Git HEAD.
- Matching three critical files is supporting fingerprint Evidence, not complete source identity.
- A discovered backup is inventory Evidence only. Backup usability and restore readiness remain **UNKNOWN** until integrity checks and an isolated restore rehearsal are separately authorized and completed.
- DB backup scope is candidate-present, absent, or unknown; filenames and content are not collected.
- Cron state is **ESTABLISHED** only when crontab -l succeeds. A process snapshot does not prove cron configuration.
- External writer code paths can be established from Repository analysis, but their Production enablement remains **UNKNOWN** unless sanitized runtime Evidence supports it.
- .env mode 0604 is Evidence for a hardening decision; the helper never changes it.

## Acceptance condition

The one read-only inspection may return PASS when the Evidence contract is complete for supported scope. Individual findings can still be UNKNOWN or UNSUPPORTED; a PASS does not authorize G2, restore, deploy, migration, or hardening.

After PASS or STOP, do not rerun. Return to Human + ChatGPT Review. G1 can become G1 CLOSE CANDIDATE / G2 READY only after every required gap is classified as ESTABLISHED, UNKNOWN, UNSUPPORTED, or BLOCKER and no G2-blocking gap remains.
