#!/usr/bin/env bash
set -Eeuo pipefail

G4_CONTRACT_ID='company-os.ir1.g4-immutable-topology.v1'
G4_RELEASE_ID='ir1-924af91188cc60d33ff87c91b94ecc1d539566e6'
G4_RC_SHA='924af91188cc60d33ff87c91b94ecc1d539566e6'
G4_ARTIFACT_SHA256='2de9b840627d0dbfd1beabaca7e8609dc9e16be2fd9c3021e3dfdc2c764cdb69'

g4_fail() {
    printf '%s\n' 'G4_TOPOLOGY=STOP' "safe_error_code=$1" 'retry_performed=false' 'secret_output=false' >&2
    exit 1
}

g4_validate_absolute_path() {
    local value="$1"
    [[ "$value" == /* && "$value" != '/' && "$value" =~ ^/[A-Za-z0-9._/-]+$ ]] || g4_fail 'UNSAFE_PATH_CONTRACT'
}

g4_verify_package() {
    local package_root="$1" php_bin="$2"
    g4_validate_absolute_path "$package_root"
    g4_validate_absolute_path "$php_bin"
    [[ -x "$php_bin" && -d "$package_root" && ! -L "$package_root" ]] || g4_fail 'PACKAGE_PRECONDITION_FAILED'
    [[ -f "$package_root/package-manifest.json" && -f "$package_root/checksums.sha256" ]] || g4_fail 'PACKAGE_FILE_SET_INCOMPLETE'

    "$php_bin" -r '
        $expected=[
            "G4_PACKAGE_PROCEDURE.md","checksums.sha256","g4-common.sh",
            "install-release.sh","package-manifest.json","rollback-release.sh",
            "switch-release.sh","topology-contract.json","verify-topology.sh",
        ];
        $actual=array_values(array_diff(scandir($argv[1]),[".",".."])) ;
        sort($actual,SORT_STRING);
        if ($actual !== $expected) { exit(20); }
        foreach ($actual as $name) {
            if (is_link($argv[1].DIRECTORY_SEPARATOR.$name)) { exit(21); }
        }
        $m=json_decode(file_get_contents($argv[1]."/package-manifest.json"),true,flags:JSON_THROW_ON_ERROR);
        $declared=array_column($m["files"]??[],"path");
        sort($declared,SORT_STRING);
        $manifestExpected=array_values(array_diff($expected,["checksums.sha256","package-manifest.json"]));
        if ($declared !== $manifestExpected) { exit(22); }
    ' "$package_root" 2>/dev/null || g4_fail 'PACKAGE_FILE_SET_INVALID'

    (cd "$package_root" && sha256sum -c checksums.sha256 >/dev/null) || g4_fail 'PACKAGE_CHECKSUM_MISMATCH'

    local identity
    identity="$($php_bin -r '
        $m=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);
        echo ($m["contract_id"]??"")."\t".($m["release_id"]??"")."\t".($m["source_commit"]??"")."\t".($m["artifact_sha256"]??"");
    ' "$package_root/package-manifest.json" 2>/dev/null)" || g4_fail 'PACKAGE_MANIFEST_INVALID'
    [[ "$identity" == "$G4_CONTRACT_ID"$'\t'"$G4_RELEASE_ID"$'\t'"$G4_RC_SHA"$'\t'"$G4_ARTIFACT_SHA256" ]] \
        || g4_fail 'PACKAGE_IDENTITY_MISMATCH'
}

g4_verify_contract() {
    local package_root="$1" php_bin="$2"
    local contract="$package_root/topology-contract.json"
    [[ -f "$contract" ]] || g4_fail 'TOPOLOGY_CONTRACT_MISSING'

    local identity
    identity="$($php_bin -r '
        $m=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);
        echo ($m["contract_id"]??"")."\t".($m["release_id"]??"")."\t".($m["source_commit"]??"")."\t".($m["artifact"]["sha256"]??"");
    ' "$contract" 2>/dev/null)" || g4_fail 'TOPOLOGY_CONTRACT_INVALID'
    [[ "$identity" == "$G4_CONTRACT_ID"$'\t'"$G4_RELEASE_ID"$'\t'"$G4_RC_SHA"$'\t'"$G4_ARTIFACT_SHA256" ]] \
        || g4_fail 'TOPOLOGY_CONTRACT_IDENTITY_MISMATCH'
}

g4_verify_shared() {
    local topology_root="$1"
    [[ -d "$topology_root/releases" && ! -L "$topology_root/releases" ]] || g4_fail 'RELEASES_ROOT_INVALID'
    [[ -d "$topology_root/shared" && ! -L "$topology_root/shared" ]] || g4_fail 'SHARED_ROOT_INVALID'
    [[ -f "$topology_root/shared/.env" && ! -L "$topology_root/shared/.env" ]] || g4_fail 'SHARED_ENV_INVALID'
    [[ -d "$topology_root/shared/storage" && ! -L "$topology_root/shared/storage" ]] || g4_fail 'SHARED_STORAGE_INVALID'
}

g4_verify_release_directory() {
    local release_dir="$1" topology_root="$2" php_bin="$3" expected_sha="$4"
    local releases_real release_real identity manifest_rc manifest_composer manifest_package
    [[ -d "$release_dir" && ! -L "$release_dir" ]] || g4_fail 'RELEASE_DIRECTORY_INVALID'
    releases_real="$(realpath "$topology_root/releases")" || g4_fail 'RELEASES_REALPATH_FAILED'
    release_real="$(realpath "$release_dir")" || g4_fail 'RELEASE_REALPATH_FAILED'
    [[ "$release_real" == "$releases_real"/* ]] || g4_fail 'RELEASE_OUTSIDE_ROOT'
    [[ -f "$release_dir/artisan" && -f "$release_dir/vendor/autoload.php" && -f "$release_dir/public/build/manifest.json" ]] \
        || g4_fail 'RELEASE_CONTENT_INCOMPLETE'
    [[ -L "$release_dir/.env" && "$(readlink "$release_dir/.env")" == "$topology_root/shared/.env" ]] \
        || g4_fail 'RELEASE_ENV_LINK_INVALID'
    [[ -L "$release_dir/storage" && "$(readlink "$release_dir/storage")" == "$topology_root/shared/storage" ]] \
        || g4_fail 'RELEASE_STORAGE_LINK_INVALID'
    [[ -f "$release_dir/release-manifest.json" ]] || g4_fail 'RELEASE_MANIFEST_MISSING'
    identity="$($php_bin -r '
        $m=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);
        echo ($m["rc_sha"]??"")."\t".($m["composer_lock_sha256"]??"")."\t".($m["package_lock_sha256"]??"");
    ' "$release_dir/release-manifest.json" 2>/dev/null)" || g4_fail 'RELEASE_MANIFEST_INVALID'
    IFS=$'\t' read -r manifest_rc manifest_composer manifest_package <<< "$identity"
    [[ "$manifest_rc" =~ ^[0-9a-f]{40}$ ]] || g4_fail 'RELEASE_MANIFEST_FIELDS_INVALID'
    [[ "$manifest_composer" =~ ^[0-9a-f]{64}$ ]] || g4_fail 'RELEASE_MANIFEST_FIELDS_INVALID'
    [[ "$manifest_package" =~ ^[0-9a-f]{64}$ ]] || g4_fail 'RELEASE_MANIFEST_FIELDS_INVALID'
    if [[ "$expected_sha" != 'any' ]]; then
        [[ "$manifest_rc" == "$expected_sha" ]] || g4_fail 'RELEASE_SOURCE_IDENTITY_MISMATCH'
    fi
}

g4_verify_public_entry() {
    local topology_root="$1" public_entry="$2"
    g4_validate_absolute_path "$public_entry"
    [[ -L "$public_entry" ]] || g4_fail 'PUBLIC_ENTRY_NOT_SYMLINK'
    [[ "$(readlink "$public_entry")" == "$topology_root/current/public" ]] || g4_fail 'PUBLIC_ENTRY_TARGET_MISMATCH'
}
