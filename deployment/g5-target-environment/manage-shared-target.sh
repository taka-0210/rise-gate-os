#!/usr/bin/env bash
set -Eeuo pipefail
export LC_ALL=C

EXPECTED_CONFIRMATION='IR1-G5-SHARED-STATE'
EXPECTED_HOME='/home/xs377816'
EXPECTED_UID='20046'
EXPECTED_GID='1000'
CANDIDATE='924af91188cc60d33ff87c91b94ecc1d539566e6'
TOPOLOGY_ROOT="$EXPECTED_HOME/company-os.jp/company-os-app"
RELEASES_ROOT="$TOPOLOGY_ROOT/releases"
SHARED_ROOT="$TOPOLOGY_ROOT/shared"
TARGET_ENV="$SHARED_ROOT/.env"
TARGET_STORAGE="$SHARED_ROOT/storage"
STAGING_ROOT="$SHARED_ROOT/.g5-shared-state-$CANDIDATE"
STAGING_MARKER="$STAGING_ROOT/.g5-marker"
STAGING_SOURCE_ENV="$STAGING_ROOT/source.env"
STAGING_TARGET_ENV="$STAGING_ROOT/.env.incoming"
STAGING_STORAGE="$STAGING_ROOT/storage"
PUBLIC_ENTRY="$EXPECTED_HOME/company-os.jp/public_html/app.company-os.jp"

HEADER='G5_SHARED_TARGET'

fail() {
    printf '%s\n' \
        "$HEADER=STOP" \
        "safe_error_code=$1" \
        'secret_output=false' \
        'next_action=RETURN_TO_HUMAN_CHATGPT'
    exit 1
}

path_entry_count() {
    find "$1" -mindepth 1 -maxdepth 1 -printf '.' | wc -c | tr -d '[:space:]'
}

assert_public_entry() {
    [[ -d "$PUBLIC_ENTRY" && ! -L "$PUBLIC_ENTRY" ]] || fail 'PUBLIC_ENTRY_TYPE_MISMATCH'
    local actual
    actual="$(find "$PUBLIC_ENTRY" -mindepth 1 -maxdepth 1 -printf '%f\n' | sort)"
    [[ "$actual" == $'.user.ini\ndefault_page.png\nindex.html' ]] || fail 'PUBLIC_ENTRY_SET_MISMATCH'
    [[ -f "$PUBLIC_ENTRY/.user.ini" && ! -L "$PUBLIC_ENTRY/.user.ini" ]] || fail 'PUBLIC_USER_INI_INVALID'
    [[ -f "$PUBLIC_ENTRY/default_page.png" && ! -L "$PUBLIC_ENTRY/default_page.png" ]] \
        || fail 'PUBLIC_DEFAULT_IMAGE_INVALID'
    [[ -f "$PUBLIC_ENTRY/index.html" && ! -L "$PUBLIC_ENTRY/index.html" ]] || fail 'PUBLIC_INDEX_INVALID'
    [[ "$(stat -c '%a' -- "$PUBLIC_ENTRY/.user.ini")" == '600' ]] || fail 'PUBLIC_USER_INI_MODE_MISMATCH'
    [[ "$(stat -c '%a' -- "$PUBLIC_ENTRY/default_page.png")" == '644' ]] \
        || fail 'PUBLIC_DEFAULT_IMAGE_MODE_MISMATCH'
    [[ "$(stat -c '%a' -- "$PUBLIC_ENTRY/index.html")" == '644' ]] || fail 'PUBLIC_INDEX_MODE_MISMATCH'
}

public_snapshot() {
    {
        stat -c '%n|%F|%a|%u|%g|%d|%i|%s|%Y' -- "$PUBLIC_ENTRY"
        stat -c '%n|%F|%a|%u|%g|%d|%i|%s|%Y' -- "$PUBLIC_ENTRY/.user.ini"
        stat -c '%n|%F|%a|%u|%g|%d|%i|%s|%Y' -- "$PUBLIC_ENTRY/default_page.png"
        stat -c '%n|%F|%a|%u|%g|%d|%i|%s|%Y' -- "$PUBLIC_ENTRY/index.html"
    } | sha256sum | awk '{print $1}'
}

assert_base_identity() {
    [[ "$HOME" == "$EXPECTED_HOME" ]] || fail 'HOME_IDENTITY_MISMATCH'
    [[ "$(id -u)" == "$EXPECTED_UID" ]] || fail 'UID_IDENTITY_MISMATCH'
    [[ "$(id -g)" == "$EXPECTED_GID" ]] || fail 'GID_IDENTITY_MISMATCH'
    [[ -d "$TOPOLOGY_ROOT" && ! -L "$TOPOLOGY_ROOT" ]] || fail 'TOPOLOGY_ROOT_INVALID'
    [[ -d "$RELEASES_ROOT" && ! -L "$RELEASES_ROOT" ]] || fail 'RELEASES_ROOT_INVALID'
    [[ -d "$SHARED_ROOT" && ! -L "$SHARED_ROOT" ]] || fail 'SHARED_ROOT_INVALID'
    [[ "$(stat -c '%u:%g:%a' -- "$TOPOLOGY_ROOT")" == '20046:1000:750' ]] \
        || fail 'TOPOLOGY_ROOT_IDENTITY_MISMATCH'
    [[ "$(stat -c '%u:%g:%a' -- "$RELEASES_ROOT")" == '20046:1000:750' ]] \
        || fail 'RELEASES_ROOT_IDENTITY_MISMATCH'
    [[ "$(stat -c '%u:%g:%a' -- "$SHARED_ROOT")" == '20046:1000:750' ]] \
        || fail 'SHARED_ROOT_IDENTITY_MISMATCH'
    [[ "$(path_entry_count "$RELEASES_ROOT")" == '0' ]] || fail 'RELEASES_ROOT_NOT_EMPTY'
    assert_public_entry
}

assert_marker() {
    [[ -f "$STAGING_MARKER" && ! -L "$STAGING_MARKER" ]] || fail 'STAGING_MARKER_MISSING'
    [[ "$(stat -c '%u:%g:%a' -- "$STAGING_MARKER")" == '20046:1000:600' ]] \
        || fail 'STAGING_MARKER_IDENTITY_MISMATCH'
    grep -Fxq "candidate=$CANDIDATE" "$STAGING_MARKER" || fail 'STAGING_MARKER_CANDIDATE_MISMATCH'
}

[[ "$#" -ge 2 && "$1" == "$EXPECTED_CONFIRMATION" ]] || fail 'CONFIRMATION_MISMATCH'
MODE="$2"

case "$MODE" in
    prepare)
        HEADER='G5_SHARED_TARGET_PREPARE'
        [[ "$#" -eq 3 && "$3" =~ ^[0-9]+$ ]] || fail 'PREPARE_ARGUMENT_CONTRACT_FAILED'
        EXPECTED_STORAGE_BYTES="$3"
        assert_base_identity
        [[ ! -e "$TARGET_ENV" && ! -L "$TARGET_ENV" ]] || fail 'TARGET_ENV_COLLISION'
        [[ ! -e "$TARGET_STORAGE" && ! -L "$TARGET_STORAGE" ]] || fail 'TARGET_STORAGE_COLLISION'
        [[ ! -e "$STAGING_ROOT" && ! -L "$STAGING_ROOT" ]] || fail 'STAGING_ROOT_COLLISION'
        [[ "$(path_entry_count "$SHARED_ROOT")" == '0' ]] || fail 'SHARED_ROOT_NOT_EMPTY'
        command -v php >/dev/null 2>&1 || fail 'PHP_CLI_MISSING'
        [[ "$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')" == '8.3' ]] || fail 'PHP_CLI_VERSION_MISMATCH'
        php -r 'exit(function_exists("posix_geteuid") && posix_geteuid() === 20046 ? 0 : 1);' \
            || fail 'PHP_CLI_RUNTIME_OWNER_MISMATCH'
        AVAILABLE_KIB="$(df -Pk -- "$SHARED_ROOT" | awk 'NR==2 {print $4}')"
        [[ "$AVAILABLE_KIB" =~ ^[0-9]+$ ]] || fail 'AVAILABLE_KIB_INVALID'
        AVAILABLE_BYTES=$((AVAILABLE_KIB * 1024))
        REQUIRED_BYTES=$((EXPECTED_STORAGE_BYTES + 67108864))
        (( AVAILABLE_BYTES > REQUIRED_BYTES )) || fail 'TARGET_DISK_INSUFFICIENT'
        PUBLIC_SNAPSHOT="$(public_snapshot)"
        mkdir -m 0700 -- "$STAGING_ROOT" || fail 'STAGING_ROOT_CREATE_FAILED'
        umask 077
        printf '%s\n' "candidate=$CANDIDATE" "public_snapshot=$PUBLIC_SNAPSHOT" > "$STAGING_MARKER" \
            || fail 'STAGING_MARKER_CREATE_FAILED'
        chmod 0600 -- "$STAGING_MARKER" || fail 'STAGING_MARKER_MODE_FAILED'
        mkdir -m 0700 -- "$STAGING_STORAGE" || fail 'STAGING_STORAGE_CREATE_FAILED'
        : > "$STAGING_SOURCE_ENV" || fail 'STAGING_SOURCE_ENV_CREATE_FAILED'
        chmod 0600 -- "$STAGING_SOURCE_ENV" || fail 'STAGING_SOURCE_ENV_MODE_FAILED'
        assert_marker
        [[ "$(stat -c '%u:%g:%a' -- "$STAGING_ROOT")" == '20046:1000:700' ]] \
            || fail 'STAGING_ROOT_IDENTITY_MISMATCH'
        [[ "$(stat -c '%u:%g:%a' -- "$STAGING_SOURCE_ENV")" == '20046:1000:600' ]] \
            || fail 'STAGING_SOURCE_ENV_IDENTITY_MISMATCH'
        [[ "$(stat -c '%d' -- "$STAGING_ROOT")" == "$(stat -c '%d' -- "$SHARED_ROOT")" ]] \
            || fail 'STAGING_FILESYSTEM_MISMATCH'
        printf '%s\n' \
            'G5_SHARED_TARGET_PREPARE=PASS' \
            'target_binding=exact_new_target_shared_root' \
            'target_owner_uid=20046' \
            'target_owner_gid=1000' \
            'php_cli_runtime_owner=20046' \
            'php_cli_version=8.3' \
            "target_available_bytes=$AVAILABLE_BYTES" \
            'staging_mode=0700' \
            'staging_source_env_mode=0600' \
            'same_filesystem=true' \
            'target_public_entry_changed=false' \
            'production_change_scope=candidate_bound_shared_staging_created' \
            'secret_output=false' \
            'next_action=CONTINUE_SAME_AUTHORIZED_HELPER'
        ;;
    finalize)
        HEADER='G5_SHARED_TARGET_FINALIZE'
        [[ "$#" -eq 8 ]] || fail 'FINALIZE_ARGUMENT_CONTRACT_FAILED'
        EXPECTED_ENV_SHA="$3"
        EXPECTED_ENV_BYTES="$4"
        EXPECTED_STORAGE_SHA="$5"
        EXPECTED_STORAGE_FILES="$6"
        EXPECTED_STORAGE_DIRECTORIES="$7"
        EXPECTED_STORAGE_BYTES="$8"
        [[ "$EXPECTED_ENV_SHA" =~ ^[0-9a-f]{64}$ && "$EXPECTED_ENV_BYTES" =~ ^[0-9]+$ \
            && "$EXPECTED_STORAGE_SHA" =~ ^[0-9a-f]{64}$ && "$EXPECTED_STORAGE_FILES" =~ ^[0-9]+$ \
            && "$EXPECTED_STORAGE_DIRECTORIES" =~ ^[0-9]+$ && "$EXPECTED_STORAGE_BYTES" =~ ^[0-9]+$ ]] \
            || fail 'FINALIZE_ARGUMENT_VALUE_INVALID'
        assert_base_identity
        assert_marker
        [[ ! -e "$TARGET_ENV" && ! -L "$TARGET_ENV" ]] || fail 'TARGET_ENV_COLLISION'
        [[ ! -e "$TARGET_STORAGE" && ! -L "$TARGET_STORAGE" ]] || fail 'TARGET_STORAGE_COLLISION'
        [[ -f "$STAGING_SOURCE_ENV" && ! -L "$STAGING_SOURCE_ENV" ]] || fail 'STAGING_SOURCE_ENV_INVALID'
        [[ -f "$STAGING_TARGET_ENV" && ! -L "$STAGING_TARGET_ENV" ]] || fail 'STAGING_TARGET_ENV_INVALID'
        [[ -d "$STAGING_STORAGE/app" && ! -L "$STAGING_STORAGE/app" ]] || fail 'STAGING_STORAGE_APP_INVALID'
        [[ "$(stat -c '%u:%g:%a' -- "$STAGING_SOURCE_ENV")" == '20046:1000:600' ]] \
            || fail 'STAGING_SOURCE_ENV_IDENTITY_MISMATCH'
        [[ "$(stat -c '%u:%g:%a' -- "$STAGING_TARGET_ENV")" == '20046:1000:600' ]] \
            || fail 'STAGING_TARGET_ENV_IDENTITY_MISMATCH'
        [[ "$(sha256sum -- "$STAGING_TARGET_ENV" | awk '{print $1}')" == "$EXPECTED_ENV_SHA" ]] \
            || fail 'STAGING_TARGET_ENV_HASH_MISMATCH'
        [[ "$(wc -c < "$STAGING_TARGET_ENV" | tr -d '[:space:]')" == "$EXPECTED_ENV_BYTES" ]] \
            || fail 'STAGING_TARGET_ENV_SIZE_MISMATCH'
        if find "$STAGING_STORAGE" -xdev \( -type l -o -type b -o -type c -o -type p -o -type s \) \
            -print -quit | grep -q .; then
            fail 'STAGING_STORAGE_SPECIAL_ENTRY_FORBIDDEN'
        fi
        if find "$STAGING_STORAGE" -xdev ! -uid "$EXPECTED_UID" -print -quit | grep -q .; then
            fail 'STAGING_STORAGE_OWNER_MISMATCH'
        fi
        if find "$STAGING_STORAGE" -xdev ! -gid "$EXPECTED_GID" -print -quit | grep -q .; then
            fail 'STAGING_STORAGE_GROUP_MISMATCH'
        fi
        find "$STAGING_STORAGE" -xdev -type d -exec chmod 0750 -- {} + \
            || fail 'STORAGE_DIRECTORY_MODE_FAILED'
        find "$STAGING_STORAGE" -xdev -type f -exec chmod 0640 -- {} + \
            || fail 'STORAGE_FILE_MODE_FAILED'
        mkdir -p -m 0750 -- \
            "$STAGING_STORAGE/app/private" \
            "$STAGING_STORAGE/app/public" \
            "$STAGING_STORAGE/framework/cache/data" \
            "$STAGING_STORAGE/framework/sessions" \
            "$STAGING_STORAGE/framework/testing" \
            "$STAGING_STORAGE/framework/views" \
            "$STAGING_STORAGE/logs" \
            || fail 'RUNTIME_STORAGE_TOPOLOGY_CREATE_FAILED'
        find "$STAGING_STORAGE" -xdev -type d -exec chmod 0750 -- {} + \
            || fail 'RUNTIME_STORAGE_DIRECTORY_MODE_FAILED'
        php -r '
            $dirs = array_slice($argv, 1);
            foreach ($dirs as $dir) {
                $probe = $dir."/.g5-runtime-write-probe";
                if (file_put_contents($probe, "probe", LOCK_EX) !== 5 || !unlink($probe)) { exit(1); }
            }
        ' -- \
            "$STAGING_STORAGE/app/private" \
            "$STAGING_STORAGE/app/public" \
            "$STAGING_STORAGE/framework/cache/data" \
            "$STAGING_STORAGE/framework/sessions" \
            "$STAGING_STORAGE/framework/testing" \
            "$STAGING_STORAGE/framework/views" \
            "$STAGING_STORAGE/logs" \
            || fail 'PHP_RUNTIME_WRITE_PROBE_FAILED'
        if find "$STAGING_STORAGE" -xdev ! -uid "$EXPECTED_UID" -print -quit | grep -q .; then
            fail 'RUNTIME_STORAGE_OWNER_MISMATCH'
        fi
        if find "$STAGING_STORAGE" -xdev ! -gid "$EXPECTED_GID" -print -quit | grep -q .; then
            fail 'RUNTIME_STORAGE_GROUP_MISMATCH'
        fi
        php -r 'exit(is_readable($argv[1]) ? 0 : 1);' -- "$STAGING_TARGET_ENV" \
            || fail 'PHP_RUNTIME_ENV_READ_PROBE_FAILED'
        STORED_PUBLIC_SNAPSHOT="$(awk -F= '$1 == "public_snapshot" { print $2 }' "$STAGING_MARKER")"
        [[ "$STORED_PUBLIC_SNAPSHOT" =~ ^[0-9a-f]{64}$ ]] || fail 'STORED_PUBLIC_SNAPSHOT_INVALID'
        [[ "$(public_snapshot)" == "$STORED_PUBLIC_SNAPSHOT" ]] || fail 'PUBLIC_ENTRY_CHANGED_DURING_OPERATION'
        rm -- "$STAGING_SOURCE_ENV" || fail 'RAW_SOURCE_ENV_REMOVE_FAILED'
        [[ ! -e "$STAGING_SOURCE_ENV" && ! -L "$STAGING_SOURCE_ENV" ]] || fail 'RAW_SOURCE_ENV_RESIDUAL'
        mv -T --no-clobber -- "$STAGING_STORAGE" "$TARGET_STORAGE" || fail 'TARGET_STORAGE_PUBLISH_FAILED'
        [[ -d "$TARGET_STORAGE" && ! -L "$TARGET_STORAGE" && ! -e "$STAGING_STORAGE" ]] \
            || fail 'TARGET_STORAGE_PUBLISH_VERIFY_FAILED'
        mv -T --no-clobber -- "$STAGING_TARGET_ENV" "$TARGET_ENV" || fail 'TARGET_ENV_PUBLISH_FAILED'
        [[ -f "$TARGET_ENV" && ! -L "$TARGET_ENV" && ! -e "$STAGING_TARGET_ENV" ]] \
            || fail 'TARGET_ENV_PUBLISH_VERIFY_FAILED'
        [[ "$(stat -c '%u:%g:%a' -- "$TARGET_ENV")" == '20046:1000:600' ]] \
            || fail 'TARGET_ENV_FINAL_IDENTITY_MISMATCH'
        [[ "$(stat -c '%u:%g:%a' -- "$TARGET_STORAGE")" == '20046:1000:750' ]] \
            || fail 'TARGET_STORAGE_FINAL_IDENTITY_MISMATCH'
        [[ "$(sha256sum -- "$TARGET_ENV" | awk '{print $1}')" == "$EXPECTED_ENV_SHA" ]] \
            || fail 'TARGET_ENV_FINAL_HASH_MISMATCH'
        php -r 'exit(is_readable($argv[1]) ? 0 : 1);' -- "$TARGET_ENV" \
            || fail 'TARGET_ENV_FINAL_READABILITY_FAILED'
        [[ "$(public_snapshot)" == "$STORED_PUBLIC_SNAPSHOT" ]] || fail 'PUBLIC_ENTRY_FINAL_CHANGE_DETECTED'
        rm -- "$STAGING_MARKER" || fail 'STAGING_MARKER_REMOVE_FAILED'
        rmdir -- "$STAGING_ROOT" || fail 'STAGING_ROOT_REMOVE_FAILED'
        [[ ! -e "$STAGING_ROOT" && ! -L "$STAGING_ROOT" ]] || fail 'STAGING_ROOT_RESIDUAL'
        printf '%s\n' \
            'G5_SHARED_TARGET_FINALIZE=PASS' \
            'target_shared_env_created=true' \
            'target_shared_env_mode=0600' \
            'target_shared_storage_created=true' \
            'target_storage_directory_mode=0750' \
            'target_storage_file_mode=0640' \
            'runtime_owner_uid=20046' \
            'runtime_owner_gid=1000' \
            'php_cli_env_readability=true' \
            'php_cli_storage_writeability=true' \
            "env_payload_sha256=$EXPECTED_ENV_SHA" \
            "env_payload_bytes=$EXPECTED_ENV_BYTES" \
            "storage_app_manifest_sha256=$EXPECTED_STORAGE_SHA" \
            "storage_app_file_count=$EXPECTED_STORAGE_FILES" \
            "storage_app_directory_count=$EXPECTED_STORAGE_DIRECTORIES" \
            "storage_app_total_bytes=$EXPECTED_STORAGE_BYTES" \
            'raw_source_env_removed=true' \
            'g4_shared_contract_ready=true' \
            'application_release_binding=deferred_g4_install_release' \
            'storage_final_delta_required=true' \
            'cleanup_state=complete' \
            'rollback_state=not_required' \
            'staging_residual_entry_count=0' \
            'target_public_entry_changed=false' \
            'legacy_production_changed=false' \
            'database_connection=not_attempted' \
            'migration=not_attempted' \
            'application_release_created=false' \
            'current_link_created=false' \
            'current_previous_link_created=false' \
            'release_marker_binding=not_attempted' \
            'dns_ssl_change=not_attempted' \
            'deploy=not_attempted' \
            'usable_backup=unknown' \
            'db_restore_readiness=blocker' \
            'PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION=PENDING_G5_PUBLIC_ENTRY_GATE' \
            'production_change_scope=exact_new_target_shared_env_and_storage_seed' \
            'secret_output=false' \
            'retry_available=false' \
            'next_action=RETURN_TO_HUMAN_CHATGPT'
        ;;
    cleanup)
        HEADER='G5_SHARED_TARGET_CLEANUP'
        [[ "$#" -eq 2 ]] || fail 'CLEANUP_ARGUMENT_CONTRACT_FAILED'
        assert_base_identity
        if [[ -e "$TARGET_ENV" || -L "$TARGET_ENV" || -e "$TARGET_STORAGE" || -L "$TARGET_STORAGE" ]]; then
            printf '%s\n' \
                'G5_SHARED_TARGET_CLEANUP=STOP' \
                'safe_error_code=PUBLISHED_SHARED_STATE_RETAINED' \
                'cleanup_state=review_required' \
                'rollback_state=separate_human_gate_required' \
                'published_shared_state_deleted=false' \
                'secret_output=false' \
                'next_action=RETURN_TO_HUMAN_CHATGPT'
            exit 1
        fi
        if [[ -e "$STAGING_ROOT" || -L "$STAGING_ROOT" ]]; then
            [[ -d "$STAGING_ROOT" && ! -L "$STAGING_ROOT" ]] || fail 'STAGING_ROOT_CLEANUP_INVALID'
            assert_marker
            [[ "$(stat -c '%u:%g:%a' -- "$STAGING_ROOT")" == '20046:1000:700' ]] \
                || fail 'STAGING_ROOT_CLEANUP_IDENTITY_MISMATCH'
            rm -rf --one-file-system -- "$STAGING_ROOT" || fail 'STAGING_ROOT_CLEANUP_FAILED'
        fi
        [[ ! -e "$STAGING_ROOT" && ! -L "$STAGING_ROOT" ]] || fail 'STAGING_ROOT_CLEANUP_RESIDUAL'
        assert_public_entry
        printf '%s\n' \
            'G5_SHARED_TARGET_CLEANUP=PASS' \
            'cleanup_state=complete' \
            'rollback_state=not_required_pre_publish' \
            'staging_residual_entry_count=0' \
            'published_shared_state_deleted=false' \
            'target_public_entry_changed=false' \
            'secret_output=false' \
            'next_action=RETURN_TO_HUMAN_CHATGPT'
        ;;
    *)
        fail 'MODE_INVALID'
        ;;
esac
