#!/bin/sh
set -eu

EXPECTED_CONFIRM='IR1-G5B-READ-ONLY-TARGET-DISCOVERY'
test "$#" -eq 1 && test "$1" = "$EXPECTED_CONFIRM" || {
    printf '%s\n' 'G5B_TARGET_DISCOVERY=STOP' 'safe_error_code=CONFIRMATION_MISMATCH'
    exit 1
}

for REQUIRED_COMMAND in awk basename df find hostname id php readlink realpath sha256sum stat; do
    command -v "$REQUIRED_COMMAND" >/dev/null 2>&1 || {
        printf '%s\n' 'G5B_TARGET_DISCOVERY=STOP' 'safe_error_code=REQUIRED_COMMAND_MISSING'
        exit 1
    }
done

ACTUAL_HOME="$(cd ~ && pwd -P)"
case "$ACTUAL_HOME" in
    /home/*) ;;
    *) printf '%s\n' 'G5B_TARGET_DISCOVERY=STOP' 'safe_error_code=ACTUAL_HOME_INVALID'; exit 1 ;;
esac

TARGET_DOMAIN="$ACTUAL_HOME/company-os.jp"
TARGET_PUBLIC_PARENT="$TARGET_DOMAIN/public_html"
TARGET_PUBLIC_ENTRY="$TARGET_PUBLIC_PARENT/app.company-os.jp"
TARGET_TOPOLOGY="$TARGET_DOMAIN/company-os-app"
LEGACY_DOMAIN="$ACTUAL_HOME/rise-gate.com"
LEGACY_APPLICATION="$LEGACY_DOMAIN/rise-gate-os"
LEGACY_PUBLIC_ENTRY="$LEGACY_DOMAIN/public_html/os.rise-gate.com"

path_state() {
    if test -L "$1"; then printf 'symlink'
    elif test -d "$1"; then printf 'directory'
    elif test -f "$1"; then printf 'file'
    elif test -e "$1"; then printf 'other'
    else printf 'absent'
    fi
}

entry_count() {
    if test -d "$1" && test ! -L "$1"; then
        find "$1" -mindepth 1 -maxdepth 1 -print 2>/dev/null | awk 'END { print NR + 0 }'
    else
        printf '0'
    fi
}

path_sha256() {
    printf '%s' "$1" | sha256sum | awk '{ print $1 }'
}

stat_value() {
    FORMAT="$1"
    STAT_PATH="$2"
    if test -e "$STAT_PATH" || test -L "$STAT_PATH"; then
        stat -c "$FORMAT" "$STAT_PATH" 2>/dev/null || printf 'unknown'
    else
        printf 'not_applicable'
    fi
}

filesystem_type() {
    FS_PATH="$1"
    if test -e "$FS_PATH" || test -L "$FS_PATH"; then
        stat -f -c '%T' "$FS_PATH" 2>/dev/null || printf 'unknown'
    else
        printf 'not_applicable'
    fi
}

canonical_sha256() {
    CANONICAL_PATH="$1"
    if test -e "$CANONICAL_PATH" || test -L "$CANONICAL_PATH"; then
        RESOLVED="$(realpath "$CANONICAL_PATH" 2>/dev/null || true)"
        if test -n "$RESOLVED"; then path_sha256 "$RESOLVED"; else printf 'unavailable'; fi
    else
        printf 'not_applicable'
    fi
}

TARGET_DOMAIN_STATE="$(path_state "$TARGET_DOMAIN")"
TARGET_PUBLIC_PARENT_STATE="$(path_state "$TARGET_PUBLIC_PARENT")"
TARGET_PUBLIC_ENTRY_STATE="$(path_state "$TARGET_PUBLIC_ENTRY")"
TARGET_TOPOLOGY_STATE="$(path_state "$TARGET_TOPOLOGY")"
LEGACY_DOMAIN_STATE="$(path_state "$LEGACY_DOMAIN")"
LEGACY_APPLICATION_STATE="$(path_state "$LEGACY_APPLICATION")"
LEGACY_PUBLIC_ENTRY_STATE="$(path_state "$LEGACY_PUBLIC_ENTRY")"

LOGIN_UID="$(id -u)"
LOGIN_GID="$(id -g)"
TARGET_OWNER_UID="$(stat_value '%u' "$TARGET_DOMAIN")"
TARGET_GROUP_GID="$(stat_value '%g' "$TARGET_DOMAIN")"
TARGET_MODE="$(stat_value '%a' "$TARGET_DOMAIN")"
PUBLIC_PARENT_OWNER_UID="$(stat_value '%u' "$TARGET_PUBLIC_PARENT")"
PUBLIC_PARENT_GROUP_GID="$(stat_value '%g' "$TARGET_PUBLIC_PARENT")"
PUBLIC_PARENT_MODE="$(stat_value '%a' "$TARGET_PUBLIC_PARENT")"
PUBLIC_ENTRY_OWNER_UID="$(stat_value '%u' "$TARGET_PUBLIC_ENTRY")"
PUBLIC_ENTRY_GROUP_GID="$(stat_value '%g' "$TARGET_PUBLIC_ENTRY")"
PUBLIC_ENTRY_MODE="$(stat_value '%a' "$TARGET_PUBLIC_ENTRY")"

TARGET_OWNER_MATCH='not_applicable'
if test "$TARGET_OWNER_UID" != 'not_applicable' && test "$TARGET_OWNER_UID" != 'unknown'; then
    if test "$TARGET_OWNER_UID" = "$LOGIN_UID"; then TARGET_OWNER_MATCH='yes'; else TARGET_OWNER_MATCH='no'; fi
fi
PUBLIC_ENTRY_OWNER_MATCH='not_applicable'
if test "$PUBLIC_ENTRY_OWNER_UID" != 'not_applicable' && test "$PUBLIC_ENTRY_OWNER_UID" != 'unknown'; then
    if test "$PUBLIC_ENTRY_OWNER_UID" = "$LOGIN_UID"; then PUBLIC_ENTRY_OWNER_MATCH='yes'; else PUBLIC_ENTRY_OWNER_MATCH='no'; fi
fi

TARGET_DEVICE="$(stat_value '%d' "$TARGET_DOMAIN")"
PUBLIC_PARENT_DEVICE="$(stat_value '%d' "$TARGET_PUBLIC_PARENT")"
PUBLIC_ENTRY_DEVICE="$(stat_value '%d' "$TARGET_PUBLIC_ENTRY")"
LEGACY_DEVICE="$(stat_value '%d' "$LEGACY_DOMAIN")"
TARGET_INODE="$(stat_value '%i' "$TARGET_DOMAIN")"
LEGACY_INODE="$(stat_value '%i' "$LEGACY_DOMAIN")"

DOMAIN_PUBLIC_SAME_DEVICE='unknown'
if test "$TARGET_DEVICE" != 'not_applicable' && test "$PUBLIC_PARENT_DEVICE" != 'not_applicable' &&
   test "$TARGET_DEVICE" != 'unknown' && test "$PUBLIC_PARENT_DEVICE" != 'unknown'; then
    if test "$TARGET_DEVICE" = "$PUBLIC_PARENT_DEVICE"; then DOMAIN_PUBLIC_SAME_DEVICE='yes'; else DOMAIN_PUBLIC_SAME_DEVICE='no'; fi
fi

LEGACY_PHYSICAL_SEPARATION='unknown'
if test "$TARGET_DOMAIN_STATE" = 'directory' && test "$LEGACY_DOMAIN_STATE" = 'directory'; then
    TARGET_REAL="$(realpath "$TARGET_DOMAIN")"
    LEGACY_REAL="$(realpath "$LEGACY_DOMAIN")"
    if test "$TARGET_REAL" != "$LEGACY_REAL" &&
       { test "$TARGET_DEVICE" != "$LEGACY_DEVICE" || test "$TARGET_INODE" != "$LEGACY_INODE"; }; then
        LEGACY_PHYSICAL_SEPARATION='yes'
    else
        LEGACY_PHYSICAL_SEPARATION='no'
    fi
fi

PUBLIC_LINK_TARGET_SHA256='not_applicable'
if test "$TARGET_PUBLIC_ENTRY_STATE" = 'symlink'; then
    PUBLIC_LINK_TARGET="$(readlink "$TARGET_PUBLIC_ENTRY" 2>/dev/null || true)"
    if test -n "$PUBLIC_LINK_TARGET"; then PUBLIC_LINK_TARGET_SHA256="$(path_sha256 "$PUBLIC_LINK_TARGET")"; fi
fi

DEFAULT_INDEX_STATE="$(path_state "$TARGET_PUBLIC_ENTRY/index.html")"
DEFAULT_INDEX_SHA256='not_applicable'
DEFAULT_INDEX_BYTES='0'
if test "$DEFAULT_INDEX_STATE" = 'file'; then
    DEFAULT_INDEX_SHA256="$(sha256sum "$TARGET_PUBLIC_ENTRY/index.html" | awk '{ print $1 }')"
    DEFAULT_INDEX_BYTES="$(stat -c '%s' "$TARGET_PUBLIC_ENTRY/index.html")"
fi
DEFAULT_HTACCESS_STATE="$(path_state "$TARGET_PUBLIC_ENTRY/.htaccess")"
DEFAULT_INDEX_PHP_STATE="$(path_state "$TARGET_PUBLIC_ENTRY/index.php")"
UNEXPECTED_ENTRY_COUNT='0'
if test "$TARGET_PUBLIC_ENTRY_STATE" = 'directory'; then
    UNEXPECTED_ENTRY_COUNT="$(find "$TARGET_PUBLIC_ENTRY" -mindepth 1 -maxdepth 1 \
        ! -name 'index.html' ! -name '.htaccess' ! -name 'index.php' -print 2>/dev/null | awk 'END { print NR + 0 }')"
fi

REMOTE_HOST_FQDN="$(hostname -f 2>/dev/null || hostname)"
XSERVER_BOUNDARY='no'
case "$REMOTE_HOST_FQDN" in *.xserver.jp) XSERVER_BOUNDARY='yes' ;; esac
HOME_ENV_MATCH='no'
if test "${HOME:-}" = "$ACTUAL_HOME"; then HOME_ENV_MATCH='yes'; fi

PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION,".",PHP_RELEASE_VERSION;' 2>/dev/null || true)"
case "$PHP_VERSION" in [0-9]*.[0-9]*.[0-9]*) ;; *) PHP_VERSION='unknown' ;; esac
AVAILABLE_KIB="$(df -Pk "$TARGET_DOMAIN" 2>/dev/null | awk 'NR == 2 { print $4 + 0 }')"
case "$AVAILABLE_KIB" in ''|*[!0-9]*) AVAILABLE_KIB='0' ;; esac

ANCHOR_STATE='review_required'
if test "$XSERVER_BOUNDARY" = 'yes' &&
   test "$TARGET_DOMAIN_STATE" = 'directory' &&
   test "$TARGET_PUBLIC_PARENT_STATE" = 'directory' &&
   test "$TARGET_PUBLIC_ENTRY_STATE" = 'directory' &&
   test "$TARGET_OWNER_MATCH" = 'yes' &&
   test "$PUBLIC_ENTRY_OWNER_MATCH" = 'yes' &&
   test "$DOMAIN_PUBLIC_SAME_DEVICE" = 'yes' &&
   test "$LEGACY_PHYSICAL_SEPARATION" = 'yes'; then
    ANCHOR_STATE='provisioned'
fi

printf '%s\n' \
    'G5B_TARGET_DISCOVERY=PASS' \
    'discovery_binding=actual_home_not_assumed' \
    "remote_host_fqdn=$REMOTE_HOST_FQDN" \
    "xserver_management_boundary=$XSERVER_BOUNDARY" \
    "actual_home_sha256=$(path_sha256 "$ACTUAL_HOME")" \
    "actual_home_basename_sha256=$(path_sha256 "$(basename "$ACTUAL_HOME")")" \
    "home_environment_matches_actual=$HOME_ENV_MATCH" \
    "login_uid=$LOGIN_UID" \
    "login_gid=$LOGIN_GID" \
    "target_domain_root_state=$TARGET_DOMAIN_STATE" \
    "target_domain_root_path_sha256=$(path_sha256 "$TARGET_DOMAIN")" \
    "target_domain_root_canonical_sha256=$(canonical_sha256 "$TARGET_DOMAIN")" \
    "target_domain_root_entry_count=$(entry_count "$TARGET_DOMAIN")" \
    "target_domain_owner_uid=$TARGET_OWNER_UID" \
    "target_domain_group_gid=$TARGET_GROUP_GID" \
    "target_domain_mode=$TARGET_MODE" \
    "target_domain_owner_matches_login=$TARGET_OWNER_MATCH" \
    "target_public_parent_state=$TARGET_PUBLIC_PARENT_STATE" \
    "target_public_parent_owner_uid=$PUBLIC_PARENT_OWNER_UID" \
    "target_public_parent_group_gid=$PUBLIC_PARENT_GROUP_GID" \
    "target_public_parent_mode=$PUBLIC_PARENT_MODE" \
    "target_public_entry_state=$TARGET_PUBLIC_ENTRY_STATE" \
    "target_public_entry_path_sha256=$(path_sha256 "$TARGET_PUBLIC_ENTRY")" \
    "target_public_entry_canonical_sha256=$(canonical_sha256 "$TARGET_PUBLIC_ENTRY")" \
    "target_public_entry_count=$(entry_count "$TARGET_PUBLIC_ENTRY")" \
    "target_public_entry_owner_uid=$PUBLIC_ENTRY_OWNER_UID" \
    "target_public_entry_group_gid=$PUBLIC_ENTRY_GROUP_GID" \
    "target_public_entry_mode=$PUBLIC_ENTRY_MODE" \
    "target_public_entry_owner_matches_login=$PUBLIC_ENTRY_OWNER_MATCH" \
    "target_public_entry_link_target_sha256=$PUBLIC_LINK_TARGET_SHA256" \
    "target_topology_root_state=$TARGET_TOPOLOGY_STATE" \
    "target_device=$TARGET_DEVICE" \
    "target_public_parent_device=$PUBLIC_PARENT_DEVICE" \
    "target_public_entry_device=$PUBLIC_ENTRY_DEVICE" \
    "target_filesystem_type=$(filesystem_type "$TARGET_DOMAIN")" \
    "domain_public_same_device=$DOMAIN_PUBLIC_SAME_DEVICE" \
    "legacy_domain_root_state=$LEGACY_DOMAIN_STATE" \
    "legacy_application_root_state=$LEGACY_APPLICATION_STATE" \
    "legacy_public_entry_state=$LEGACY_PUBLIC_ENTRY_STATE" \
    "legacy_domain_device=$LEGACY_DEVICE" \
    "legacy_domain_canonical_sha256=$(canonical_sha256 "$LEGACY_DOMAIN")" \
    "legacy_physical_separation=$LEGACY_PHYSICAL_SEPARATION" \
    "default_index_state=$DEFAULT_INDEX_STATE" \
    "default_index_sha256=$DEFAULT_INDEX_SHA256" \
    "default_index_bytes=$DEFAULT_INDEX_BYTES" \
    "default_htaccess_state=$DEFAULT_HTACCESS_STATE" \
    "default_index_php_state=$DEFAULT_INDEX_PHP_STATE" \
    "unexpected_entry_count=$UNEXPECTED_ENTRY_COUNT" \
    "php_cli_version=$PHP_VERSION" \
    "available_kib=$AVAILABLE_KIB" \
    "target_anchor_state=$ANCHOR_STATE" \
    'posix_capability_rehearsal=not_executed' \
    'database_connection=not_attempted' \
    'production_mutation=false' \
    'secret_output=false' \
    'next_action=RETURN_TO_HUMAN_G5C_GATE'
