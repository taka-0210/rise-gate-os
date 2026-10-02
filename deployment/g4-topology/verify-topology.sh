#!/usr/bin/env bash
set -Eeuo pipefail

script_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
# shellcheck source=g4-common.sh
source "$script_root/g4-common.sh"

[[ "$#" -eq 5 ]] || g4_fail 'ARGUMENT_CONTRACT_FAILED'
package_root="$1"
topology_root="$2"
public_entry="$3"
php_bin="$4"
expected_current="$5"

g4_validate_absolute_path "$topology_root"
[[ "$expected_current" == 'any' || "$expected_current" =~ ^[0-9a-f]{40}$ ]] || g4_fail 'EXPECTED_CURRENT_INVALID'
[[ -d "$topology_root" && ! -L "$topology_root" ]] || g4_fail 'TOPOLOGY_ROOT_INVALID'
g4_verify_package "$package_root" "$php_bin"
g4_verify_contract "$package_root" "$php_bin"
g4_verify_shared "$topology_root"
g4_verify_public_entry "$topology_root" "$public_entry"
[[ -L "$topology_root/current" ]] || g4_fail 'CURRENT_SYMLINK_REQUIRED'

current_release="$(realpath "$topology_root/current")" || g4_fail 'CURRENT_RESOLUTION_FAILED'
g4_verify_release_directory "$current_release" "$topology_root" "$php_bin" "$expected_current"

previous_state='absent'
if [[ -L "$topology_root/current.previous" ]]; then
    previous_release="$(realpath "$topology_root/current.previous")" || g4_fail 'PREVIOUS_RESOLUTION_FAILED'
    [[ "$previous_release" != "$current_release" ]] || g4_fail 'PREVIOUS_EQUALS_CURRENT'
    g4_verify_release_directory "$previous_release" "$topology_root" "$php_bin" 'any'
    previous_state='verified'
elif [[ -e "$topology_root/current.previous" ]]; then
    g4_fail 'PREVIOUS_NOT_SYMLINK'
fi

printf '%s\n' +    'G4_TOPOLOGY_VERIFICATION=PASS' +    "expected_current=$expected_current" +    'current_verified=true' +    "current_previous=$previous_state" +    'public_entry_verified=true' +    'database_connection=not_attempted' +    'production_change_scope=none_read_only_verification' +    'retry_available=false' +    'secret_output=false'
