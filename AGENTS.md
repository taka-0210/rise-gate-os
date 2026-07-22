# RISE GATE repository workflow

## Git operations

- After completing and validating a coherent requested change, Codex must commit the related files and push the current branch to `origin` without requiring a separate user request.
- Stage only files related to the current task. Never stage or overwrite unrelated user changes.
- If validation, authentication, conflicts, or scope are unclear, report the blocker instead of forcing the operation.

## Deployment operations

- Git push and server deployment are separate operations.
- A push must not automatically deploy to demo or production.
- Deployment workflows must use `workflow_dispatch` only; do not configure a deployment trigger on `push`.
- The user manually runs deployments from GitHub Actions. Codex prepares and explains the workflow but does not run it unless the user explicitly overrides this rule for that deployment.
- Preserve server-owned uploads, environment files, databases, and generated content.
- Store credentials in GitHub Actions secrets. Never commit or display secrets.

## Project-specific configuration

- Application: Laravel with Composer and Node dependencies.
- Validation: run the relevant focused tests and, before delivery, `php artisan test` when the environment permits.
- Production URL: `https://os.rise-gate.com/`
- Production workflow: `.github/workflows/deploy-production.yml` (`本番へデプロイ`).
- Deployment: build and test in GitHub Actions, then transfer and switch the release over SSH.
- Protected server state: `.env`, persistent storage, user uploads, database data, and server-managed release state.
