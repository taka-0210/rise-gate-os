#!/usr/bin/env bash
set -Eeuo pipefail

script_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
# shellcheck source=g4-common.sh
source "$script_root/g4-common.sh"

[[ "$#" -eq 4 ]] || g4_fail 'ARGUMENT_CONTRACT_FAILED'
package_root="$1"
topology_root="$2"
public_entry="$3"
php_bin="$4"

g4_validate_absolute_path "$topology_root"
[[ -d "$topology_root" && ! -L "$topology_root" ]] || g4_fail 'TOPOLOGY_ROOT_INVALID'
g4_verify_package "$package_root" "$php_bin"
g4_verify_contract "$package_root" "$php_bin"
g4_verify_shared "$topology_root"
g4_verify_public_entry "$topology_root" "$public_entry"
[[ -L "$topology_root/current" && -L "$topology_root/current.previous" ]] || g4_fail 'ROLLBACK_LINKS_REQUIRED'

rollback_next="$topology_root/.current.rollback-$G4_RELEASE_ID"
previous_next="$topology_root/.current.previous.rollback-$G4_RELEASE_ID"
[[ ! -e "$rollback_next" && ! -L "$rollback_next" ]] || g4_fail 'ROLLBACK_NEXT_ALREADY_EXISTS'
[[ ! -e "$previous_next" && ! -L "$previous_next" ]] || g4_fail 'ROLLBACK_PREVIOUS_NEXT_ALREADY_EXISTS'

current_release="$(realpath "$topology_root/current")" || g4_fail 'CURRENT_RESOLUTION_FAILED'
previous_release="$(realpath "$topology_root/current.previous")" || g4_fail 'PREVIOUS_RESOLUTION_FAILED'
[[ "$current_release" == "$topology_root/releases/$G4_RELEASE_ID" ]] || g4_fail 'CURRENT_IS_NOT_EXACT_CANDIDATE'
[[ "$previous_release" != "$current_release" ]] || g4_fail 'ROLLBACK_TARGET_EQUALS_CURRENT'
g4_verify_release_directory "$current_release" "$topology_root" "$php_bin" "$G4_RC_SHA"
g4_verify_release_directory "$previous_release" "$topology_root" "$php_bin" 'any'

cleanup_staged_links() {
    [[ ! -L "$rollback_next" ]] || rm -- "$rollback_next"
    [[ ! -L "$previous_next" ]] || rm -- "$previous_next"
}
trap cleanup_staged_links ERR INT TERM

ln -s "$previous_release" "$rollback_next"
ln -s "$current_release" "$previous_next"
[[ "$(realpath "$rollback_next")" == "$previous_release" ]] || g4_fail 'ROLLBACK_NEXT_TARGET_MISMATCH'
[[ "$(realpath "$previous_next")" == "$current_release" ]] || g4_fail 'ROLLBACK_PREVIOUS_TARGET_MISMATCH'

mv -Tf "$previous_next" "$topology_root/current.previous"
mv -Tf "$rollback_next" "$topology_root/current"
trap - ERR INT TERM

[[ "$(realpath "$topology_root/current")" == "$previous_release" ]] || g4_fail 'ROLLBACK_CURRENT_VERIFICATION_FAILED'
[[ "$(realpath "$topology_root/current.previous")" == "$current_release" ]] || g4_fail 'ROLLBACK_PREVIOUS_VERIFICATION_FAILED'
g4_verify_public_entry "$topology_root" "$public_entry"

printf '%s\n' +    'G4_CODE_ROLLBACK=PASS' +    'current_verified=true' +    'current_previous_verified=true' +    'public_entry_verified=true' +    'database_rollback_performed=false' +    'additive_schema_retained=true' +    'production_change_scope=two_atomic_symlink_renames_only' +    'retry_available=false' +    'secret_output=false'
