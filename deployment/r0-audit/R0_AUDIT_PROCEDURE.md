# IR-1 R0 Read-only Audit Procedure

Status: Human approval required before any Production placement or execution.

Exact candidate: `924af91188cc60d33ff87c91b94ecc1d539566e6`

The bundle contains an exact `git archive` of the candidate plus the allowlisted R0 corrective overlay recorded in `r0-bundle-manifest.json`. It contains no `.env`, Git metadata, later Scope migration, CE code or MDC code.

## Responsibility split

- Application / DB audit: Laravel bootstrap with Production `.env` loaded into process memory only; DB SQL is blocked before execution unless classified as `SELECT`, `SHOW`, `DESCRIBE` or `PRAGMA`.
- Host audit: standalone PHP; does not bootstrap Laravel or connect to the DB. It observes filesystem, symlinks, PHP extensions, process counts, user cron availability and backup inventory.
- Restore readiness and external writers remain `UNSUPPORTED` and must never be inferred as PASS.

## Future Human operation — not currently authorized

1. Verify the archive SHA-256 against the approved Decision Package.
2. Extract it into a new non-public, non-current audit directory. Never overwrite `current`, `current.previous`, shared storage or `.env`.
3. Run the Application / DB command once, with `LOG_CHANNEL=stderr` and the existing Production `.env` supplied only through `IR1_R0_ENV_FILE`:

   ```sh
   IR1_R0_ENV_FILE=/approved/shared/.env php deployment/r0-audit/r0-artisan.php \
     release:audit-r0 \
     --confirm-read-only=IR1-R0-READ-ONLY \
     --bundle-manifest=r0-bundle-manifest.json
   ```

4. Run the standalone host command once:

   ```sh
   php deployment/r0-audit/r0-host-audit.php \
     --bundle-manifest=r0-bundle-manifest.json \
     --current-link=/approved/current \
     --previous-link=/approved/current.previous \
     --shared-root=/approved/shared \
     --backup-root=/approved/backup
   ```

5. Capture stdout only. Do not redirect stderr into the Evidence file. Do not retry a failed audit without a new Human review.

The paths above are placeholders until sanitized topology evidence and Human approval establish the exact Production values.
