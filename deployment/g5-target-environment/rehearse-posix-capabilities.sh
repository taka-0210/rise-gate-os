#!/bin/sh
set -u

EXPECTED_CONFIRM='IR1-G5C-POSIX-CAPABILITY-REHEARSAL'
RELEASE_ID='ir1-924af91188cc60d33ff87c91b94ecc1d539566e6'
EXPECTED_HOME='/home/xs377816'
TARGET_DOMAIN="$EXPECTED_HOME/company-os.jp"
TARGET_PUBLIC_PARENT="$TARGET_DOMAIN/public_html"
TARGET_PUBLIC_ENTRY="$TARGET_PUBLIC_PARENT/app.company-os.jp"
TARGET_TOPOLOGY_ROOT="$TARGET_DOMAIN/company-os-app"
REHEARSAL_ROOT="$TARGET_DOMAIN/.ir1-g5-capability-924af91188cc60d33ff87c91b94ecc1d539566e6"
CREATED_ROOT='no'
CLEANUP_STATE='not_required'

cleanup() {
    if test "$CREATED_ROOT" != 'yes'; then
        return 0
    fi
    if test -d "$REHEARSAL_ROOT" && test ! -L "$REHEARSAL_ROOT"; then
        rm -rf -- "$REHEARSAL_ROOT" >/dev/null 2>&1 || return 1
    elif test -e "$REHEARSAL_ROOT" || test -L "$REHEARSAL_ROOT"; then
        return 1
    fi
    CREATED_ROOT='no'
    CLEANUP_STATE='complete'
    return 0
}

cleanup_on_exit() {
    if test "$CREATED_ROOT" = 'yes'; then
        cleanup >/dev/null 2>&1 || :
    fi
}

cleanup_on_signal() {
    cleanup >/dev/null 2>&1 || :
    trap - 0
    exit 1
}

trap cleanup_on_exit 0
trap cleanup_on_signal 1 2 3 15

fail() {
    CODE="$1"
    cleanup || CLEANUP_STATE='failed'
    printf '%s\n' \
        'G5C_POSIX_CAPABILITY_REHEARSAL=STOP' \
        "safe_error_code=$CODE" \
        "cleanup_state=$CLEANUP_STATE" \
        'production_change_scope=candidate_bound_isolated_rehearsal_only' \
        'target_topology_mutation_attempted=false' \
        'target_public_entry_mutation_attempted=false' \
        'legacy_production_changed=false' \
        'env_changed=false' \
        'shared_storage_changed=false' \
        'database_connection=not_attempted' \
        'dns_ssl_change=not_attempted' \
        'deploy=not_attempted' \
        'retry_performed=false' \
        'secret_output=false' \
        'next_action=RETURN_TO_HUMAN_CHATGPT'
    exit 1
}

snapshot_public_entry() {
    ROOT_META="$(stat -c '%d|%i|%u|%g|%a' "$TARGET_PUBLIC_ENTRY" 2>/dev/null)" || return 1
    ENTRY_META="$(find "$TARGET_PUBLIC_ENTRY" -mindepth 1 -maxdepth 1 \
        -printf '%f|%y|%m|%U|%G|%s|%T@\n' 2>/dev/null | LC_ALL=C sort)" || return 1
    printf '%s\n%s\n' "$ROOT_META" "$ENTRY_META" | sha256sum | awk '{print $1}'
}

test "$#" -eq 1 && test "$1" = "$EXPECTED_CONFIRM" || fail 'CONFIRMATION_MISMATCH'
test "$HOME" = "$EXPECTED_HOME" || fail 'HOME_IDENTITY_MISMATCH'
test -d "$TARGET_DOMAIN" && test ! -L "$TARGET_DOMAIN" || fail 'TARGET_DOMAIN_ROOT_INVALID'
test -d "$TARGET_PUBLIC_PARENT" && test ! -L "$TARGET_PUBLIC_PARENT" || fail 'TARGET_PUBLIC_PARENT_INVALID'
test -d "$TARGET_PUBLIC_ENTRY" && test ! -L "$TARGET_PUBLIC_ENTRY" || fail 'TARGET_PUBLIC_ENTRY_INVALID'
test ! -e "$TARGET_TOPOLOGY_ROOT" && test ! -L "$TARGET_TOPOLOGY_ROOT" || fail 'TARGET_TOPOLOGY_ALREADY_PRESENT'
test ! -e "$REHEARSAL_ROOT" && test ! -L "$REHEARSAL_ROOT" || fail 'REHEARSAL_ROOT_ALREADY_EXISTS'

PUBLIC_ENTRY_COUNT_BEFORE="$(find "$TARGET_PUBLIC_ENTRY" -mindepth 1 -maxdepth 1 -print 2>/dev/null | wc -l | tr -d ' ')" \
    || fail 'PUBLIC_ENTRY_COUNT_UNAVAILABLE'
test "$PUBLIC_ENTRY_COUNT_BEFORE" = '3' || fail 'PUBLIC_ENTRY_COUNT_MISMATCH'
PUBLIC_ENTRY_SNAPSHOT_BEFORE="$(snapshot_public_entry)" || fail 'PUBLIC_ENTRY_SNAPSHOT_UNAVAILABLE'
case "$PUBLIC_ENTRY_SNAPSHOT_BEFORE" in ''|*[!a-f0-9]*) fail 'PUBLIC_ENTRY_SNAPSHOT_INVALID' ;; esac

DOMAIN_IDENTITY_BEFORE="$(stat -c '%d:%i:%u:%g:%a' "$TARGET_DOMAIN" 2>/dev/null)" \
    || fail 'DOMAIN_IDENTITY_UNAVAILABLE'
PUBLIC_PARENT_IDENTITY_BEFORE="$(stat -c '%d:%i:%u:%g:%a' "$TARGET_PUBLIC_PARENT" 2>/dev/null)" \
    || fail 'PUBLIC_PARENT_IDENTITY_UNAVAILABLE'
DOMAIN_DEVICE="$(stat -c '%d' "$TARGET_DOMAIN" 2>/dev/null)" || fail 'DOMAIN_DEVICE_UNAVAILABLE'
PUBLIC_DEVICE="$(stat -c '%d' "$TARGET_PUBLIC_PARENT" 2>/dev/null)" || fail 'PUBLIC_DEVICE_UNAVAILABLE'
test "$DOMAIN_DEVICE" = "$PUBLIC_DEVICE" || fail 'TARGET_PATHS_DIFFERENT_FILESYSTEM'

mkdir -m 0700 -- "$REHEARSAL_ROOT" >/dev/null 2>&1 || fail 'REHEARSAL_ROOT_CREATE_FAILED'
CREATED_ROOT='yes'
CLEANUP_STATE='pending'
test "$(stat -c '%a' "$REHEARSAL_ROOT" 2>/dev/null)" = '700' || fail 'REHEARSAL_ROOT_MODE_VERIFY_FAILED'
test "$(stat -c '%d' "$REHEARSAL_ROOT" 2>/dev/null)" = "$DOMAIN_DEVICE" || fail 'REHEARSAL_FILESYSTEM_MISMATCH'
mkdir -m 0750 -- "$REHEARSAL_ROOT/releases" "$REHEARSAL_ROOT/releases/a" \
    "$REHEARSAL_ROOT/releases/a/public" "$REHEARSAL_ROOT/releases/b" \
    "$REHEARSAL_ROOT/releases/b/public" "$REHEARSAL_ROOT/fixtures" \
    >/dev/null 2>&1 || fail 'REHEARSAL_TREE_CREATE_FAILED'
: > "$REHEARSAL_ROOT/fixtures/mode-0600" 2>/dev/null || fail 'PERMISSION_FIXTURE_CREATE_FAILED'
chmod 0600 "$REHEARSAL_ROOT/fixtures/mode-0600" >/dev/null 2>&1 || fail 'PERMISSION_MODE_SET_FAILED'
test "$(stat -c '%a' "$REHEARSAL_ROOT/fixtures/mode-0600" 2>/dev/null)" = '600' \
    || fail 'PERMISSION_MODE_VERIFY_FAILED'

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
test ! -e "$TARGET_TOPOLOGY_ROOT" && test ! -L "$TARGET_TOPOLOGY_ROOT" || fail 'TARGET_TOPOLOGY_CHANGED'
test "$(stat -c '%d:%i:%u:%g:%a' "$TARGET_DOMAIN" 2>/dev/null)" = "$DOMAIN_IDENTITY_BEFORE" \
    || fail 'DOMAIN_IDENTITY_CHANGED'
test "$(stat -c '%d:%i:%u:%g:%a' "$TARGET_PUBLIC_PARENT" 2>/dev/null)" = "$PUBLIC_PARENT_IDENTITY_BEFORE" \
    || fail 'PUBLIC_PARENT_IDENTITY_CHANGED'
PUBLIC_ENTRY_COUNT_AFTER="$(find "$TARGET_PUBLIC_ENTRY" -mindepth 1 -maxdepth 1 -print 2>/dev/null | wc -l | tr -d ' ')" \
    || fail 'PUBLIC_ENTRY_POST_COUNT_UNAVAILABLE'
PUBLIC_ENTRY_SNAPSHOT_AFTER="$(snapshot_public_entry)" || fail 'PUBLIC_ENTRY_POST_SNAPSHOT_UNAVAILABLE'
test "$PUBLIC_ENTRY_COUNT_AFTER" = "$PUBLIC_ENTRY_COUNT_BEFORE" || fail 'PUBLIC_ENTRY_COUNT_CHANGED'
test "$PUBLIC_ENTRY_SNAPSHOT_AFTER" = "$PUBLIC_ENTRY_SNAPSHOT_BEFORE" || fail 'PUBLIC_ENTRY_SNAPSHOT_CHANGED'

printf '%s\n' \
    'G5C_POSIX_CAPABILITY_REHEARSAL=PASS' \
    "release_id=$RELEASE_ID" \
    'rehearsal_root_binding=candidate_bound_isolated' \
    'actual_home_binding=exact_new_target' \
    'same_filesystem=true' \
    'posix_symlink=true' \
    'atomic_rename=true' \
    'permission_mode_0600=true' \
    'cleanup_state=complete' \
    'residual_entry_count=0' \
    'production_change_scope=candidate_bound_isolated_rehearsal_only' \
    'target_topology_changed=false' \
    'target_public_entry_changed=false' \
    'legacy_production_changed=false' \
    'env_changed=false' \
    'shared_storage_changed=false' \
    'database_connection=not_attempted' \
    'dns_ssl_change=not_attempted' \
    'deploy=not_attempted' \
    'retry_available=false' \
    'secret_output=false' \
    'next_action=RETURN_TO_HUMAN_CHATGPT'
