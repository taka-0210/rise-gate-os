#!/bin/sh
set -u

EXPECTED_CONFIRM='IR1-G5-TARGET-SKELETON-BUILD'
RELEASE_ID='ir1-924af91188cc60d33ff87c91b94ecc1d539566e6'
EXPECTED_HOME='/home/xs377816'
EXPECTED_UID='20046'
EXPECTED_GID='1000'
TARGET_DOMAIN="$EXPECTED_HOME/company-os.jp"
TARGET_PUBLIC_PARENT="$TARGET_DOMAIN/public_html"
TARGET_PUBLIC_ENTRY="$TARGET_PUBLIC_PARENT/app.company-os.jp"
TARGET_TOPOLOGY_ROOT="$TARGET_DOMAIN/company-os-app"
STAGING_ROOT="$TARGET_DOMAIN/.ir1-g5-skeleton-build-924af91188cc60d33ff87c91b94ecc1d539566e6"
STAGING_CREATED='false'
TOPOLOGY_COMMITTED='false'
CLEANUP_STATE='not_required'
ROLLBACK_STATE='not_required'

snapshot_public_entry() {
    root_meta="$(stat -c '%d|%i|%u|%g|%a' "$TARGET_PUBLIC_ENTRY" 2>/dev/null)" || return 1
    entry_meta="$(find "$TARGET_PUBLIC_ENTRY" -mindepth 1 -maxdepth 1 \
        -printf '%f|%y|%m|%U|%G|%s|%T@\n' 2>/dev/null | LC_ALL=C sort)" || return 1
    printf '%s\n%s\n' "$root_meta" "$entry_meta" | sha256sum | awk '{print $1}'
}

exact_empty_skeleton() {
    test -d "$TARGET_TOPOLOGY_ROOT" && test ! -L "$TARGET_TOPOLOGY_ROOT" || return 1
    test -d "$TARGET_TOPOLOGY_ROOT/releases" && test ! -L "$TARGET_TOPOLOGY_ROOT/releases" || return 1
    test -d "$TARGET_TOPOLOGY_ROOT/shared" && test ! -L "$TARGET_TOPOLOGY_ROOT/shared" || return 1
    test "$(find "$TARGET_TOPOLOGY_ROOT" -mindepth 1 -maxdepth 1 -print 2>/dev/null | wc -l | tr -d ' ')" = '2' || return 1
    test "$(find "$TARGET_TOPOLOGY_ROOT/releases" -mindepth 1 -maxdepth 1 -print 2>/dev/null | wc -l | tr -d ' ')" = '0' || return 1
    test "$(find "$TARGET_TOPOLOGY_ROOT/shared" -mindepth 1 -maxdepth 1 -print 2>/dev/null | wc -l | tr -d ' ')" = '0' || return 1
    return 0
}

cleanup_staging() {
    if test "$STAGING_CREATED" != 'true'; then
        return 0
    fi
    if test -d "$STAGING_ROOT" && test ! -L "$STAGING_ROOT"; then
        rmdir -- "$STAGING_ROOT/shared" "$STAGING_ROOT/releases" "$STAGING_ROOT" >/dev/null 2>&1 || return 1
    elif test -e "$STAGING_ROOT" || test -L "$STAGING_ROOT"; then
        return 1
    fi
    STAGING_CREATED='false'
    CLEANUP_STATE='complete'
    return 0
}

rollback_committed() {
    if test "$TOPOLOGY_COMMITTED" != 'true'; then
        return 0
    fi
    exact_empty_skeleton || return 1
    rmdir -- "$TARGET_TOPOLOGY_ROOT/shared" "$TARGET_TOPOLOGY_ROOT/releases" "$TARGET_TOPOLOGY_ROOT" \
        >/dev/null 2>&1 || return 1
    TOPOLOGY_COMMITTED='false'
    ROLLBACK_STATE='complete'
    return 0
}

cleanup_on_signal() {
    rollback_committed >/dev/null 2>&1 || :
    cleanup_staging >/dev/null 2>&1 || :
    trap - 0
    exit 1
}

trap cleanup_on_signal 1 2 3 15

fail() {
    code="$1"
    if ! rollback_committed; then
        ROLLBACK_STATE='failed_review_required'
    fi
    if ! cleanup_staging; then
        CLEANUP_STATE='failed_review_required'
    fi
    if test -e "$STAGING_ROOT" || test -L "$STAGING_ROOT"; then
        staging_residual='unknown_review_required'
    else
        staging_residual='0'
    fi
    if test -e "$TARGET_TOPOLOGY_ROOT" || test -L "$TARGET_TOPOLOGY_ROOT"; then
        topology_state='present_review_required'
    else
        topology_state='absent'
    fi
    printf '%s\n' \
        'G5_TARGET_SKELETON_BUILD=STOP' \
        "safe_error_code=$code" \
        "cleanup_state=$CLEANUP_STATE" \
        "rollback_state=$ROLLBACK_STATE" \
        "staging_residual_entry_count=$staging_residual" \
        "target_topology_state=$topology_state" \
        'production_change_scope=exact_empty_target_skeleton_only' \
        'target_public_entry_mutation_attempted=false' \
        'legacy_production_changed=false' \
        'env_created=false' \
        'shared_storage_created=false' \
        'application_release_created=false' \
        'current_link_created=false' \
        'current_previous_link_created=false' \
        'database_connection=not_attempted' \
        'migration=not_attempted' \
        'release_marker_binding=not_attempted' \
        'dns_ssl_change=not_attempted' \
        'deploy=not_attempted' \
        'retry_available=false' \
        'secret_output=false' \
        'PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION=PENDING_G5_PUBLIC_ENTRY_GATE' \
        'next_action=RETURN_TO_HUMAN_CHATGPT'
    exit 1
}

test "$#" -eq 1 && test "$1" = "$EXPECTED_CONFIRM" || fail 'CONFIRMATION_MISMATCH'
test "$HOME" = "$EXPECTED_HOME" || fail 'HOME_IDENTITY_MISMATCH'
test "$(id -u 2>/dev/null)" = "$EXPECTED_UID" || fail 'LOGIN_UID_MISMATCH'
test "$(id -g 2>/dev/null)" = "$EXPECTED_GID" || fail 'LOGIN_GID_MISMATCH'
test -d "$TARGET_DOMAIN" && test ! -L "$TARGET_DOMAIN" || fail 'TARGET_DOMAIN_ROOT_INVALID'
test "$(stat -c '%u' "$TARGET_DOMAIN" 2>/dev/null)" = "$EXPECTED_UID" || fail 'TARGET_DOMAIN_OWNER_MISMATCH'
test "$(stat -c '%g' "$TARGET_DOMAIN" 2>/dev/null)" = "$EXPECTED_GID" || fail 'TARGET_DOMAIN_GROUP_MISMATCH'
test "$(stat -c '%a' "$TARGET_DOMAIN" 2>/dev/null)" = '711' || fail 'TARGET_DOMAIN_MODE_MISMATCH'
test -d "$TARGET_PUBLIC_PARENT" && test ! -L "$TARGET_PUBLIC_PARENT" || fail 'TARGET_PUBLIC_PARENT_INVALID'
test -d "$TARGET_PUBLIC_ENTRY" && test ! -L "$TARGET_PUBLIC_ENTRY" || fail 'TARGET_PUBLIC_ENTRY_INVALID'
test "$(stat -c '%u:%g:%a' "$TARGET_PUBLIC_PARENT" 2>/dev/null)" = "$EXPECTED_UID:$EXPECTED_GID:711" \
    || fail 'TARGET_PUBLIC_PARENT_IDENTITY_MISMATCH'
test "$(stat -c '%u:%g:%a' "$TARGET_PUBLIC_ENTRY" 2>/dev/null)" = "$EXPECTED_UID:$EXPECTED_GID:711" \
    || fail 'TARGET_PUBLIC_ENTRY_IDENTITY_MISMATCH'
test ! -e "$TARGET_TOPOLOGY_ROOT" && test ! -L "$TARGET_TOPOLOGY_ROOT" || fail 'TARGET_TOPOLOGY_ALREADY_PRESENT'
test ! -e "$STAGING_ROOT" && test ! -L "$STAGING_ROOT" || fail 'STAGING_ROOT_ALREADY_PRESENT'

public_names="$(find "$TARGET_PUBLIC_ENTRY" -mindepth 1 -maxdepth 1 -printf '%f\n' 2>/dev/null | LC_ALL=C sort)" \
    || fail 'PUBLIC_ENTRY_NAMES_UNAVAILABLE'
expected_public_names="$(printf '%s\n' '.user.ini' 'default_page.png' 'index.html' | LC_ALL=C sort)"
test "$public_names" = "$expected_public_names" || fail 'PUBLIC_ENTRY_SET_MISMATCH'
test -f "$TARGET_PUBLIC_ENTRY/.user.ini" && test ! -L "$TARGET_PUBLIC_ENTRY/.user.ini" \
    || fail 'PUBLIC_USER_INI_INVALID'
test -f "$TARGET_PUBLIC_ENTRY/default_page.png" && test ! -L "$TARGET_PUBLIC_ENTRY/default_page.png" \
    || fail 'PUBLIC_DEFAULT_IMAGE_INVALID'
test -f "$TARGET_PUBLIC_ENTRY/index.html" && test ! -L "$TARGET_PUBLIC_ENTRY/index.html" \
    || fail 'PUBLIC_DEFAULT_INDEX_INVALID'
test "$(stat -c '%a' "$TARGET_PUBLIC_ENTRY/.user.ini" 2>/dev/null)" = '600' || fail 'PUBLIC_USER_INI_MODE_MISMATCH'
test "$(stat -c '%a' "$TARGET_PUBLIC_ENTRY/default_page.png" 2>/dev/null)" = '644' \
    || fail 'PUBLIC_DEFAULT_IMAGE_MODE_MISMATCH'
test "$(stat -c '%a' "$TARGET_PUBLIC_ENTRY/index.html" 2>/dev/null)" = '644' \
    || fail 'PUBLIC_DEFAULT_INDEX_MODE_MISMATCH'

public_snapshot_before="$(snapshot_public_entry)" || fail 'PUBLIC_ENTRY_SNAPSHOT_UNAVAILABLE'
case "$public_snapshot_before" in ''|*[!a-f0-9]*) fail 'PUBLIC_ENTRY_SNAPSHOT_INVALID' ;; esac
domain_identity_before="$(stat -c '%d:%i:%u:%g:%a' "$TARGET_DOMAIN" 2>/dev/null)" \
    || fail 'DOMAIN_IDENTITY_UNAVAILABLE'
public_parent_identity_before="$(stat -c '%d:%i:%u:%g:%a' "$TARGET_PUBLIC_PARENT" 2>/dev/null)" \
    || fail 'PUBLIC_PARENT_IDENTITY_UNAVAILABLE'
domain_device="$(stat -c '%d' "$TARGET_DOMAIN" 2>/dev/null)" || fail 'DOMAIN_DEVICE_UNAVAILABLE'

mkdir -m 0750 -- "$STAGING_ROOT" >/dev/null 2>&1 || fail 'STAGING_ROOT_CREATE_FAILED'
STAGING_CREATED='true'
CLEANUP_STATE='pending'
mkdir -m 0750 -- "$STAGING_ROOT/releases" "$STAGING_ROOT/shared" >/dev/null 2>&1 \
    || fail 'SKELETON_DIRECTORIES_CREATE_FAILED'

for path in "$STAGING_ROOT" "$STAGING_ROOT/releases" "$STAGING_ROOT/shared"; do
    test -d "$path" && test ! -L "$path" || fail 'SKELETON_DIRECTORY_TYPE_MISMATCH'
    test "$(stat -c '%u:%g:%a' "$path" 2>/dev/null)" = "$EXPECTED_UID:$EXPECTED_GID:750" \
        || fail 'SKELETON_DIRECTORY_IDENTITY_MISMATCH'
    test "$(stat -c '%d' "$path" 2>/dev/null)" = "$domain_device" || fail 'SKELETON_FILESYSTEM_MISMATCH'
done
test "$(find "$STAGING_ROOT" -mindepth 1 -maxdepth 1 -print 2>/dev/null | wc -l | tr -d ' ')" = '2' \
    || fail 'SKELETON_ENTRY_COUNT_MISMATCH'

mv -T -- "$STAGING_ROOT" "$TARGET_TOPOLOGY_ROOT" >/dev/null 2>&1 || fail 'SKELETON_ATOMIC_PUBLISH_FAILED'
STAGING_CREATED='false'
CLEANUP_STATE='complete'
TOPOLOGY_COMMITTED='true'

exact_empty_skeleton || fail 'PUBLISHED_SKELETON_MISMATCH'
for path in "$TARGET_TOPOLOGY_ROOT" "$TARGET_TOPOLOGY_ROOT/releases" "$TARGET_TOPOLOGY_ROOT/shared"; do
    test "$(stat -c '%u:%g:%a' "$path" 2>/dev/null)" = "$EXPECTED_UID:$EXPECTED_GID:750" \
        || fail 'PUBLISHED_SKELETON_IDENTITY_MISMATCH'
done
test ! -e "$TARGET_TOPOLOGY_ROOT/shared/.env" && test ! -L "$TARGET_TOPOLOGY_ROOT/shared/.env" \
    || fail 'SHARED_ENV_UNEXPECTED'
test ! -e "$TARGET_TOPOLOGY_ROOT/shared/storage" && test ! -L "$TARGET_TOPOLOGY_ROOT/shared/storage" \
    || fail 'SHARED_STORAGE_UNEXPECTED'
test ! -e "$TARGET_TOPOLOGY_ROOT/current" && test ! -L "$TARGET_TOPOLOGY_ROOT/current" \
    || fail 'CURRENT_LINK_UNEXPECTED'
test ! -e "$TARGET_TOPOLOGY_ROOT/current.previous" && test ! -L "$TARGET_TOPOLOGY_ROOT/current.previous" \
    || fail 'CURRENT_PREVIOUS_LINK_UNEXPECTED'
test ! -e "$STAGING_ROOT" && test ! -L "$STAGING_ROOT" || fail 'STAGING_RESIDUAL_PRESENT'

test "$(stat -c '%d:%i:%u:%g:%a' "$TARGET_DOMAIN" 2>/dev/null)" = "$domain_identity_before" \
    || fail 'DOMAIN_IDENTITY_CHANGED'
test "$(stat -c '%d:%i:%u:%g:%a' "$TARGET_PUBLIC_PARENT" 2>/dev/null)" = "$public_parent_identity_before" \
    || fail 'PUBLIC_PARENT_IDENTITY_CHANGED'
public_snapshot_after="$(snapshot_public_entry)" || fail 'PUBLIC_ENTRY_POST_SNAPSHOT_UNAVAILABLE'
test "$public_snapshot_after" = "$public_snapshot_before" || fail 'PUBLIC_ENTRY_SNAPSHOT_CHANGED'

printf '%s\n' \
    'G5_TARGET_SKELETON_BUILD=PASS' \
    "release_id=$RELEASE_ID" \
    'skeleton_root_binding=exact_new_target' \
    'target_topology_root_created=true' \
    'releases_root_created=true' \
    'shared_root_created=true' \
    'topology_mode=0750' \
    "owner_uid=$EXPECTED_UID" \
    "group_gid=$EXPECTED_GID" \
    'atomic_publish=true' \
    'cleanup_state=complete' \
    'rollback_state=not_required' \
    'staging_residual_entry_count=0' \
    'production_change_scope=exact_empty_target_skeleton_only' \
    'target_public_entry_changed=false' \
    'legacy_production_changed=false' \
    'env_created=false' \
    'shared_storage_created=false' \
    'application_release_created=false' \
    'current_link_created=false' \
    'current_previous_link_created=false' \
    'database_connection=not_attempted' \
    'migration=not_attempted' \
    'release_marker_binding=not_attempted' \
    'dns_ssl_change=not_attempted' \
    'deploy=not_attempted' \
    'retry_available=false' \
    'secret_output=false' \
    'PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION=PENDING_G5_PUBLIC_ENTRY_GATE' \
    'next_action=RETURN_TO_HUMAN_CHATGPT'
