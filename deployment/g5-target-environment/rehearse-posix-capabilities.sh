#!/bin/sh
set -u

EXPECTED_CONFIRM='IR1-G5-POSIX-CAPABILITY-REHEARSAL'
RELEASE_ID='ir1-924af91188cc60d33ff87c91b94ecc1d539566e6'
REHEARSAL_ROOT="$HOME/company-os.jp/.ir1-g5-capability-924af91188cc60d33ff87c91b94ecc1d539566e6"
TARGET_DOMAIN="$HOME/company-os.jp"
TARGET_PUBLIC_PARENT="$HOME/company-os.jp/public_html"
CLEANUP_STATE='not_started'

cleanup() {
    if test -d "$REHEARSAL_ROOT" && test ! -L "$REHEARSAL_ROOT"; then
        rm -rf -- "$REHEARSAL_ROOT" >/dev/null 2>&1 || return 1
    elif test -e "$REHEARSAL_ROOT" || test -L "$REHEARSAL_ROOT"; then
        return 1
    fi
    CLEANUP_STATE='complete'
    return 0
}

fail() {
    CODE="$1"
    cleanup || CLEANUP_STATE='failed'
    printf '%s\n' \
        'G5_POSIX_CAPABILITY_REHEARSAL=STOP' \
        "safe_error_code=$CODE" \
        "cleanup_state=$CLEANUP_STATE" \
        'production_change_scope=isolated_temporary_capability_rehearsal_only' \
        'legacy_production_changed=false' \
        'database_connection=not_attempted' \
        'retry_performed=false' \
        'secret_output=false'
    exit 1
}

test "$#" -eq 1 && test "$1" = "$EXPECTED_CONFIRM" || fail 'CONFIRMATION_MISMATCH'
case "${HOME#/home/}" in ''|*[!A-Za-z0-9._-]*) fail 'HOME_IDENTITY_INVALID' ;; esac
test "$HOME" != "${HOME#/home/}" || fail 'HOME_IDENTITY_INVALID'
test -d "$TARGET_DOMAIN" && test ! -L "$TARGET_DOMAIN" || fail 'TARGET_DOMAIN_ROOT_INVALID'
test -d "$TARGET_PUBLIC_PARENT" && test ! -L "$TARGET_PUBLIC_PARENT" || fail 'TARGET_PUBLIC_PARENT_INVALID'
test ! -e "$REHEARSAL_ROOT" && test ! -L "$REHEARSAL_ROOT" || fail 'REHEARSAL_ROOT_ALREADY_EXISTS'

DOMAIN_DEVICE="$(stat -c '%d' "$TARGET_DOMAIN" 2>/dev/null)" || fail 'DOMAIN_DEVICE_UNAVAILABLE'
PUBLIC_DEVICE="$(stat -c '%d' "$TARGET_PUBLIC_PARENT" 2>/dev/null)" || fail 'PUBLIC_DEVICE_UNAVAILABLE'
test "$DOMAIN_DEVICE" = "$PUBLIC_DEVICE" || fail 'TARGET_PATHS_DIFFERENT_FILESYSTEM'

mkdir -m 0700 -- "$REHEARSAL_ROOT" >/dev/null 2>&1 || fail 'REHEARSAL_ROOT_CREATE_FAILED'
mkdir -m 0750 -- "$REHEARSAL_ROOT/releases" "$REHEARSAL_ROOT/releases/a" \
    "$REHEARSAL_ROOT/releases/a/public" "$REHEARSAL_ROOT/releases/b" \
    "$REHEARSAL_ROOT/releases/b/public" "$REHEARSAL_ROOT/shared" \
    "$REHEARSAL_ROOT/shared/storage" >/dev/null 2>&1 || fail 'REHEARSAL_TREE_CREATE_FAILED'
: > "$REHEARSAL_ROOT/shared/.env.fixture" 2>/dev/null || fail 'ENV_FIXTURE_CREATE_FAILED'
chmod 0600 "$REHEARSAL_ROOT/shared/.env.fixture" >/dev/null 2>&1 || fail 'ENV_MODE_SET_FAILED'
test "$(stat -c '%a' "$REHEARSAL_ROOT/shared/.env.fixture" 2>/dev/null)" = '600' || fail 'ENV_MODE_VERIFY_FAILED'

ln -s "$REHEARSAL_ROOT/releases/a" "$REHEARSAL_ROOT/current" >/dev/null 2>&1 || fail 'CURRENT_LINK_CREATE_FAILED'
ln -s "$REHEARSAL_ROOT/current/public" "$REHEARSAL_ROOT/public-entry" >/dev/null 2>&1 || fail 'PUBLIC_LINK_CREATE_FAILED'
test "$(realpath "$REHEARSAL_ROOT/current" 2>/dev/null)" = "$REHEARSAL_ROOT/releases/a" || fail 'CURRENT_LINK_VERIFY_FAILED'
test "$(readlink "$REHEARSAL_ROOT/public-entry" 2>/dev/null)" = "$REHEARSAL_ROOT/current/public" || fail 'PUBLIC_LINK_VERIFY_FAILED'

ln -s "$REHEARSAL_ROOT/releases/b" "$REHEARSAL_ROOT/current.next" >/dev/null 2>&1 || fail 'NEXT_LINK_CREATE_FAILED'
ln -s "$REHEARSAL_ROOT/releases/a" "$REHEARSAL_ROOT/current.previous.next" >/dev/null 2>&1 || fail 'PREVIOUS_LINK_CREATE_FAILED'
mv -Tf "$REHEARSAL_ROOT/current.previous.next" "$REHEARSAL_ROOT/current.previous" >/dev/null 2>&1 || fail 'PREVIOUS_ATOMIC_RENAME_FAILED'
mv -Tf "$REHEARSAL_ROOT/current.next" "$REHEARSAL_ROOT/current" >/dev/null 2>&1 || fail 'CURRENT_ATOMIC_RENAME_FAILED'
test "$(realpath "$REHEARSAL_ROOT/current" 2>/dev/null)" = "$REHEARSAL_ROOT/releases/b" || fail 'CURRENT_ATOMIC_VERIFY_FAILED'
test "$(realpath "$REHEARSAL_ROOT/current.previous" 2>/dev/null)" = "$REHEARSAL_ROOT/releases/a" || fail 'PREVIOUS_ATOMIC_VERIFY_FAILED'
test "$(realpath "$REHEARSAL_ROOT/public-entry" 2>/dev/null)" = "$REHEARSAL_ROOT/releases/b/public" || fail 'PUBLIC_ATOMIC_VERIFY_FAILED'

cleanup || fail 'CLEANUP_FAILED'
test ! -e "$REHEARSAL_ROOT" && test ! -L "$REHEARSAL_ROOT" || fail 'CLEANUP_VERIFY_FAILED'

printf '%s\n' \
    'G5_POSIX_CAPABILITY_REHEARSAL=PASS' \
    "release_id=$RELEASE_ID" \
    'same_filesystem=true' \
    'posix_symlink=true' \
    'atomic_rename=true' \
    'public_entry_followed_current=true' \
    'env_mode_0600=true' \
    'cleanup_state=complete' \
    'residual_entry_count=0' \
    'production_change_scope=isolated_temporary_capability_rehearsal_only' \
    'legacy_production_changed=false' \
    'database_connection=not_attempted' \
    'retry_available=false' \
    'secret_output=false' \
    'next_action=RETURN_TO_HUMAN_CHATGPT'
