# IR-1 R0 Read-only Audit Procedure

Status: Human approval required before any Production placement or execution.

Exact candidate: `924af91188cc60d33ff87c91b94ecc1d539566e6`

## Production Architecture boundary

Master v054 has an approved target split: the Service Site is `company-os.jp`, the Application Production URL is `app.company-os.jp`, and `os.rise-gate.com` is the pre-migration Production environment. This R0 procedure audits the current `os.rise-gate.com` state only. It does not create the immutable release topology, configure `app.company-os.jp`, change DNS or certificates, deploy, migrate the database, edit `.env`, or switch a symlink.

The bundle contains an exact `git archive` of the candidate plus the allowlisted R0 corrective overlay recorded in `r0-bundle-manifest.json`. It contains no `.env`, Git metadata, later Scope migration, CE code or MDC code.

## Responsibility split

- Application / DB audit: Laravel bootstrap with Production `.env` loaded into process memory only; DB SQL is blocked before execution unless classified as `SELECT`, `SHOW`, `DESCRIBE` or `PRAGMA`.
- Host audit: standalone PHP; does not bootstrap Laravel or connect to the DB. An explicit `legacy-fixed-root` or `immutable-release` profile observes only that topology, PHP extensions, process counts, user cron availability and bounded backup metadata.
- Restore readiness and external writers remain `UNSUPPORTED` and must never be inferred as PASS.

## Human execution gate

- Step 2 is reconciled by additive Evidence as ADOPTED_EXISTING_EMPTY_DIRECTORIES.
- Step 3 completed PASS with one placement attempt of the exact audit archive.
- Step 4 is not authorized until a separate Human + ChatGPT decision.
- The helper rechecks the exact candidate, local archive SHA-256, reconciled Step 2 state and empty candidate boundary before connection.
- Remote preparation atomically creates a private .step3-placement directory only after confirming the candidate directory remains empty.
- SCP targets only that private staging directory.
- Finalization verifies the staged archive SHA-256, uses a non-forcing hard link to fail closed if the final name exists, verifies the final archive again, and removes only its own staging file and directory.
- Step 3 never extracts the archive, loads .env, connects to the DB, runs an audit or deploys the application.
- PASS or STOP is terminal for this authorization. Retry requires a new Human + ChatGPT decision.

Step 4, when separately authorized, is limited to exact archive verification and extraction under the same private candidate directory. Before extraction the helper binds to the recorded Step 3 helper hash and PASS receipt, requires the archive to be the only candidate entry, rejects symlinks and verifies the archive SHA-256. It creates a new bundle directory, extracts with no owner or permission restoration, verifies the manifest SHA-256 and required scripts, rejects a raw .env or any extracted symlink, and checks PHP CLI compatibility. It does not bootstrap Laravel, load Production .env, connect to the DB or run either R0 audit.

## Future Human operation — not currently authorized

1. Verify the archive SHA-256 against the approved Decision Package.
2. Extract it into a new non-public, non-current audit directory. Do not place it in `public_html`, `_backup`, the legacy application root, a future release root, shared storage or `.env`.
3. Run the Application / DB command once, with `LOG_CHANNEL=stderr` and the existing legacy Production `.env` supplied only through `IR1_R0_ENV_FILE`:

   ```sh
   IR1_R0_ENV_FILE=/approved/home/rise-gate.com/rise-gate-os/.env php deployment/r0-audit/r0-artisan.php \
     release:audit-r0 \
     --confirm-read-only=IR1-R0-READ-ONLY \
     --bundle-manifest=r0-bundle-manifest.json
   ```

4. Run the standalone host command once with the legacy profile:

   ```sh
   php deployment/r0-audit/r0-host-audit.php \
     --bundle-manifest=r0-bundle-manifest.json \
     --topology-profile=legacy-fixed-root \
     --application-root=/approved/home/rise-gate.com/rise-gate-os \
     --public-root=/approved/home/rise-gate.com/public_html/os.rise-gate.com \
     --legacy-revision-marker=/approved/home/rise-gate.com/public_html/.rise-gate-deploy-revision \
     --legacy-staging-root=/approved/home/.rise-gate-os-deploy \
     --backup-root=/approved/home/rise-gate.com/public_html/_backup
   ```

5. Capture stdout only. Do not redirect stderr into the Evidence file. Do not retry a failed audit without a new Human review.

The `/approved/home` prefix remains a placeholder until the single R0 execution is separately approved. The legacy marker is not treated as site-bound merely because it contains a Git-shaped value, and `_backup` inventory does not establish restore readiness or non-public exposure. R0 results feed a later, separate immutable topology and `app.company-os.jp` migration plan.
