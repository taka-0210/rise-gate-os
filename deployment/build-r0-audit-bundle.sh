#!/usr/bin/env bash
set -euo pipefail

candidate_commit="924af91188cc60d33ff87c91b94ecc1d539566e6"
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
output_dir="${1:-${repo_root}/storage/app/release-audit}"
php_bin="${2:-php}"
composer_phar="${3:-${repo_root}/composer.phar}"

if [[ ! -x "${php_bin}" && "${php_bin}" != "php" ]]; then
  echo "PHP CLI is unavailable." >&2
  exit 2
fi
if [[ ! -f "${composer_phar}" ]]; then
  echo "Composer PHAR is unavailable." >&2
  exit 2
fi

work_dir="$(mktemp -d)"
trap 'rm -rf -- "${work_dir}"' EXIT
stage="${work_dir}/bundle"
mkdir -p "${stage}" "${output_dir}"

git -C "${repo_root}" cat-file -e "${candidate_commit}^{commit}"
git -C "${repo_root}" archive --format=tar "${candidate_commit}" | tar -xf - -C "${stage}"

overlay_files=(
  "app/Console/Commands/AuditProductionCurrentState.php"
  "app/Services/Release/ProductionReadOnlyAudit.php"
  "app/Services/Release/R0AuditBundleVerifier.php"
  "app/Services/Release/R0AuditSafetyException.php"
  "app/Services/Release/R0BundleHash.php"
  "app/Services/Release/ReadOnlySqlGuard.php"
  "deployment/r0-audit/r0-artisan.php"
  "deployment/r0-audit/r0-host-audit.php"
  "deployment/r0-audit/R0_AUDIT_PROCEDURE.md"
)
for relative in "${overlay_files[@]}"; do
  [[ -f "${repo_root}/${relative}" ]] || { echo "Missing audited overlay." >&2; exit 3; }
  mkdir -p "$(dirname "${stage}/${relative}")"
  cp "${repo_root}/${relative}" "${stage}/${relative}"
done

rm -f -- "${stage}/.env"
mkdir -p "${stage}/bootstrap/cache" "${stage}/storage/framework/cache" "${stage}/storage/framework/sessions" "${stage}/storage/framework/views"

"${php_bin}" "${composer_phar}" install \
  --working-dir="${stage}" \
  --no-dev \
  --prefer-dist \
  --classmap-authoritative \
  --no-interaction \
  --no-ansi \
  --quiet \
  --no-progress

"${php_bin}" "${repo_root}/deployment/r0-audit/build-bundle-manifest.php" "${stage}" "${candidate_commit}"

artifact_name="ir1-r0-audit-bundle-${candidate_commit}.tar.gz"
artifact_path="${output_dir}/${artifact_name}"
(
  cd "${stage}"
  find . -exec touch -h -d '@0' {} +
  tar --sort=name --mtime='@0' --owner=0 --group=0 --numeric-owner -czf "${artifact_path}" .
)

sha256sum "${artifact_path}" > "${artifact_path}.sha256"
cp "${stage}/r0-bundle-manifest.json" "${output_dir}/${artifact_name}.manifest.json"
cp "${stage}/r0-migration-manifest.json" "${output_dir}/${artifact_name}.migrations.json"

printf 'artifact=%s\n' "${artifact_path}"
printf 'sha256=%s\n' "$(sha256sum "${artifact_path}" | awk '{print $1}')"
printf 'source_commit=%s\n' "${candidate_commit}"
