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

target_release="$topology_root/releases/$G4_RELEASE_ID"
next_link="$topology_root/.current.next-$G4_RELEASE_ID"
previous_next="$topology_root/.current.previous.next-$G4_RELEASE_ID"
[[ -L "$topology_root/current" ]] || g4_fail 'CURRENT_SYMLINK_REQUIRED'
if [[ -e "$topology_root/current.previous" || -L "$topology_root/current.previous" ]]; then
    [[ -L "$topology_root/current.previous" ]] || g4_fail 'PREVIOUS_NOT_SYMLINK'
    previous_existing="$(realpath "$topology_root/current.previous")" || g4_fail 'PREVIOUS_RESOLUTION_FAILED'
    g4_verify_release_directory "$previous_existing" "$topology_root" "$php_bin" 'any'
fi
[[ ! -e "$next_link" && ! -L "$next_link" ]] || g4_fail 'NEXT_LINK_ALREADY_EXISTS'
[[ ! -e "$previous_next" && ! -L "$previous_next" ]] || g4_fail 'PREVIOUS_NEXT_LINK_ALREADY_EXISTS'

old_release="$(realpath "$topology_root/current")" || g4_fail 'CURRENT_RESOLUTION_FAILED'
[[ "$old_release" != "$target_release" ]] || g4_fail 'CANDIDATE_ALREADY_CURRENT'
g4_verify_release_directory "$old_release" "$topology_root" "$php_bin" 'any'
g4_verify_release_directory "$target_release" "$topology_root" "$php_bin" "$G4_RC_SHA"

cleanup_staged_links() {
    [[ ! -L "$next_link" ]] || rm -- "$next_link"
    [[ ! -L "$previous_next" ]] || rm -- "$previous_next"
}
trap cleanup_staged_links ERR INT TERM

ln -s "$target_release" "$next_link"
ln -s "$old_release" "$previous_next"
[[ "$(realpath "$next_link")" == "$(realpath "$target_release")" ]] || g4_fail 'NEXT_LINK_TARGET_MISMATCH'
[[ "$(realpath "$previous_next")" == "$old_release" ]] || g4_fail 'PREVIOUS_NEXT_TARGET_MISMATCH'

mv -Tf "$previous_next" "$topology_root/current.previous"
mv -Tf "$next_link" "$topology_root/current"
trap - ERR INT TERM

[[ "$(realpath "$topology_root/current")" == "$(realpath "$target_release")" ]] || g4_fail 'CURRENT_SWITCH_VERIFICATION_FAILED'
[[ "$(realpath "$topology_root/current.previous")" == "$old_release" ]] || g4_fail 'PREVIOUS_SWITCH_VERIFICATION_FAILED'
g4_verify_public_entry "$topology_root" "$public_entry"

printf '%s\n' +    'G4_ATOMIC_SWITCH=PASS' +    "release_id=$G4_RELEASE_ID" +    'current_verified=true' +    'current_previous_verified=true' +    'public_entry_verified=true' +    'database_changed=false' +    'production_change_scope=two_atomic_symlink_renames_only' +    'retry_available=false' +    'secret_output=false'
