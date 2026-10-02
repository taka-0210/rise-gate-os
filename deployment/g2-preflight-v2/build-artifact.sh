#!/usr/bin/env bash
set -euo pipefail

candidate='924af91188cc60d33ff87c91b94ecc1d539566e6'
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
source_root="${repo_root}/deployment/g2-preflight-v2"
output_root="${1:-${repo_root}/storage/app/release-audit}"
php_bin="${2:-php}"
stage="$(mktemp -d)"
trap 'rm -rf -- "${stage}"' EXIT

mkdir -p "${output_root}"
cp "${source_root}/launcher.sh" "${source_root}/auditor.php" "${stage}/"
"${php_bin}" "${source_root}/build-manifest.php" "${stage}" >/dev/null
find "${stage}" -type f -exec touch -d '@0' {} +
artifact="${output_root}/ir1-g2-preflight-v2-${candidate}.tar.gz"
tar --sort=name --mtime='@0' --owner=0 --group=0 --numeric-owner -czf "${artifact}" -C "${stage}" .
sha256sum "${artifact}" > "${artifact}.sha256"
cp "${stage}/manifest.json" "${artifact}.manifest.json"
cp "${stage}/checksums.sha256" "${artifact}.checksums.sha256"
printf 'artifact=%s\n' "${artifact}"
printf 'sha256=%s\n' "$(sha256sum "${artifact}" | awk '{print $1}')"
printf 'source_commit=%s\n' "${candidate}"
