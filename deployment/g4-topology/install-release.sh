#!/usr/bin/env bash
set -Eeuo pipefail

script_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
# shellcheck source=g4-common.sh
source "$script_root/g4-common.sh"

[[ "$#" -eq 4 ]] || g4_fail 'ARGUMENT_CONTRACT_FAILED'
package_root="$1"
artifact="$2"
topology_root="$3"
php_bin="$4"

g4_validate_absolute_path "$artifact"
g4_validate_absolute_path "$topology_root"
[[ -d "$topology_root" && ! -L "$topology_root" ]] || g4_fail 'TOPOLOGY_ROOT_INVALID'
[[ -f "$artifact" && ! -L "$artifact" ]] || g4_fail 'ARTIFACT_INVALID'

g4_verify_package "$package_root" "$php_bin"
g4_verify_contract "$package_root" "$php_bin"
g4_verify_shared "$topology_root"

artifact_identity="$(sha256sum "$artifact" | awk '{print $1}')"
[[ "$artifact_identity" == "$G4_ARTIFACT_SHA256" ]] || g4_fail 'ARTIFACT_SHA256_MISMATCH'
[[ "$(wc -c < "$artifact" | tr -d '[:space:]')" == '5807985' ]] || g4_fail 'ARTIFACT_SIZE_MISMATCH'

if tar -tzf "$artifact" | awk '
    /^\// { bad=1 }
    /(^|\/)\.\.($|\/)/ { bad=1 }
    END { exit bad ? 0 : 1 }
'; then
    g4_fail 'ARTIFACT_PATH_TRAVERSAL'
fi
if tar -tzf "$artifact" | awk '
    /(^|\/)(\.env|storage)(\/|$)/ { bad=1 }
    END { exit bad ? 0 : 1 }
'; then
    g4_fail 'ARTIFACT_RUNTIME_STATE_COLLISION'
fi

release_dir="$topology_root/releases/$G4_RELEASE_ID"
incoming_dir="$topology_root/releases/.incoming-$G4_RELEASE_ID"
[[ ! -e "$release_dir" && ! -L "$release_dir" ]] || g4_fail 'RELEASE_ALREADY_EXISTS'
[[ ! -e "$incoming_dir" && ! -L "$incoming_dir" ]] || g4_fail 'INCOMING_ALREADY_EXISTS'

cleanup_incoming() {
    if [[ -d "$incoming_dir" && ! -L "$incoming_dir" ]]; then
        rm -rf --one-file-system "$incoming_dir"
    fi
}
trap cleanup_incoming ERR INT TERM

mkdir --mode=0750 "$incoming_dir"
tar -xzf "$artifact" -C "$incoming_dir" --no-same-owner --no-same-permissions
[[ ! -e "$incoming_dir/.env" && ! -L "$incoming_dir/.env" ]] || g4_fail 'EXTRACTED_ENV_COLLISION'
[[ ! -e "$incoming_dir/storage" && ! -L "$incoming_dir/storage" ]] || g4_fail 'EXTRACTED_STORAGE_COLLISION'
ln -s "$topology_root/shared/.env" "$incoming_dir/.env"
ln -s "$topology_root/shared/storage" "$incoming_dir/storage"
g4_verify_release_directory "$incoming_dir" "$topology_root" "$php_bin" "$G4_RC_SHA"

mv --no-clobber "$incoming_dir" "$release_dir"
trap - ERR INT TERM
g4_verify_release_directory "$release_dir" "$topology_root" "$php_bin" "$G4_RC_SHA"

printf '%s\n' +    'G4_INSTALL_RELEASE=PASS' +    "release_id=$G4_RELEASE_ID" +    'artifact_sha256_verified=true' +    'overwrite_performed=false' +    'current_changed=false' +    'database_changed=false' +    'production_change_scope=one_immutable_release_directory_only' +    'retry_available=false' +    'secret_output=false'
