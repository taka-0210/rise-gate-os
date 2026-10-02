#!/usr/bin/env sh
set -eu

candidate='924af91188cc60d33ff87c91b94ecc1d539566e6'
schema='2'
script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
bundle_root=${1-}
environment_file=${2-}
php_bin=${G2_V2_PHP_BIN:-php}

emit() {
    printf '{"schema_version":%s,"sequence":%s,"layer":"shell","event":"%s","status":"%s","data":%s}\n' "$schema" "$1" "$2" "$3" "$4"
}

stop() {
    emit "$1" terminal STOP "{\"safe_error_code\":\"$2\",\"failure_stage\":\"$3\",\"production_change_scope\":\"none_read_only_g2_preflight_v2\",\"secret_output\":false}"
    exit 1
}

emit 1 shell_started PASS '{"candidate":"924af91188cc60d33ff87c91b94ecc1d539566e6"}'
[ -n "$bundle_root" ] && [ -n "$environment_file" ] || stop 2 G2_V2_ARGUMENTS_REJECTED shell_preflight
[ -f "$script_dir/checksums.sha256" ] && [ -f "$script_dir/manifest.json" ] && [ -f "$script_dir/auditor.php" ] || stop 2 G2_V2_ARTIFACT_INCOMPLETE artifact_verification
command -v sha256sum >/dev/null 2>&1 || stop 2 G2_V2_SHA256_UNAVAILABLE artifact_verification
(cd "$script_dir" && sha256sum -c checksums.sha256 >/dev/null 2>&1) || stop 2 G2_V2_ARTIFACT_HASH_MISMATCH artifact_verification
grep -F '"source_commit": "924af91188cc60d33ff87c91b94ecc1d539566e6"' "$script_dir/manifest.json" >/dev/null 2>&1 || stop 2 G2_V2_CANDIDATE_MISMATCH artifact_verification
emit 2 artifact_verified PASS '{"manifest_schema":2,"hashes_verified":true}'
command -v "$php_bin" >/dev/null 2>&1 || stop 3 G2_V2_PHP_UNAVAILABLE php_discovery
emit 3 php_discovered PASS '{"php_cli":true}'

export G2_V2_CANDIDATE="$candidate"
export G2_V2_BUNDLE_ROOT="$bundle_root"
export IR1_R0_ENV_FILE="$environment_file"

set +e
"$php_bin" "$script_dir/auditor.php" 2>/dev/null
php_exit=$?
set -e
[ "$php_exit" -eq 0 ] || stop 4 G2_V2_PHP_AUDITOR_STOPPED php_execution
emit 4 shell_terminal PASS '{"php_exit_code":0,"production_change_scope":"none_read_only_g2_preflight_v2"}'
