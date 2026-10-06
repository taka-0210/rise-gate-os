#!/usr/bin/env bash
set -Eeuo pipefail

EXPECTED_CONFIRMATION='IR1-G5-SHARED-STATE'
EXPECTED_HOME='/home/xs257823'
EXPECTED_UID='20222'
EXPECTED_GID='1000'
SOURCE_APPLICATION_ROOT="$EXPECTED_HOME/rise-gate.com/rise-gate-os"
SOURCE_ENV="$SOURCE_APPLICATION_ROOT/.env"
SOURCE_STORAGE="$SOURCE_APPLICATION_ROOT/storage"

fail() {
    printf '%s\n' \
        'G5_SHARED_SOURCE_PREFLIGHT=STOP' \
        "safe_error_code=$1" \
        'production_change_scope=none_read_only_source' \
        'secret_output=false' \
        'next_action=RETURN_TO_HUMAN_CHATGPT'
    exit 1
}

[[ "$#" -eq 1 && "$1" == "$EXPECTED_CONFIRMATION" ]] || fail 'CONFIRMATION_MISMATCH'
[[ "$HOME" == "$EXPECTED_HOME" ]] || fail 'HOME_IDENTITY_MISMATCH'
[[ "$(id -u)" == "$EXPECTED_UID" ]] || fail 'UID_IDENTITY_MISMATCH'
[[ "$(id -g)" == "$EXPECTED_GID" ]] || fail 'GID_IDENTITY_MISMATCH'
[[ -d "$SOURCE_APPLICATION_ROOT" && ! -L "$SOURCE_APPLICATION_ROOT" ]] \
    || fail 'SOURCE_APPLICATION_ROOT_INVALID'
[[ -f "$SOURCE_ENV" && ! -L "$SOURCE_ENV" ]] || fail 'SOURCE_ENV_INVALID'
[[ -r "$SOURCE_ENV" ]] || fail 'SOURCE_ENV_NOT_READABLE'
[[ -d "$SOURCE_STORAGE" && ! -L "$SOURCE_STORAGE" ]] || fail 'SOURCE_STORAGE_INVALID'
[[ -d "$SOURCE_STORAGE/app" && ! -L "$SOURCE_STORAGE/app" ]] || fail 'SOURCE_STORAGE_APP_INVALID'
[[ -r "$SOURCE_STORAGE/app" ]] || fail 'SOURCE_STORAGE_APP_NOT_READABLE'

SOURCE_ENV_MODE="$(stat -c '%a' -- "$SOURCE_ENV")"
SOURCE_ENV_UID="$(stat -c '%u' -- "$SOURCE_ENV")"
SOURCE_ENV_GID="$(stat -c '%g' -- "$SOURCE_ENV")"
SOURCE_STORAGE_MODE="$(stat -c '%a' -- "$SOURCE_STORAGE")"
SOURCE_STORAGE_UID="$(stat -c '%u' -- "$SOURCE_STORAGE")"
SOURCE_STORAGE_GID="$(stat -c '%g' -- "$SOURCE_STORAGE")"
SOURCE_APP_DEVICE="$(stat -c '%d' -- "$SOURCE_STORAGE/app")"

[[ "$SOURCE_ENV_MODE" == '604' || "$SOURCE_ENV_MODE" == '600' ]] || fail 'SOURCE_ENV_MODE_UNEXPECTED'
[[ "$SOURCE_ENV_UID" == "$EXPECTED_UID" && "$SOURCE_ENV_GID" == "$EXPECTED_GID" ]] \
    || fail 'SOURCE_ENV_OWNER_MISMATCH'
[[ "$SOURCE_STORAGE_UID" == "$EXPECTED_UID" && "$SOURCE_STORAGE_GID" == "$EXPECTED_GID" ]] \
    || fail 'SOURCE_STORAGE_OWNER_MISMATCH'

printf '%s\n' \
    'G5_SHARED_SOURCE_PREFLIGHT=PASS' \
    'source_binding=exact_legacy_application' \
    "source_env_mode=0$SOURCE_ENV_MODE" \
    "source_env_owner_uid=$SOURCE_ENV_UID" \
    "source_env_owner_gid=$SOURCE_ENV_GID" \
    "source_storage_mode=0$SOURCE_STORAGE_MODE" \
    "source_storage_owner_uid=$SOURCE_STORAGE_UID" \
    "source_storage_owner_gid=$SOURCE_STORAGE_GID" \
    "source_storage_app_device=$SOURCE_APP_DEVICE" \
    'source_env_readable=true' \
    'source_storage_app_readable=true' \
    'production_change_scope=none_read_only_source' \
    'secret_output=false' \
    'next_action=CONTINUE_SAME_AUTHORIZED_HELPER'
