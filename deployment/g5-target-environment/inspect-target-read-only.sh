#!/bin/sh
set -eu

EXPECTED_CONFIRM='IR1-G5-READ-ONLY-INSPECTION'
test "$#" -eq 1 && test "$1" = "$EXPECTED_CONFIRM" || {
    printf '%s\n' 'G5_TARGET_INSPECTION=STOP' 'safe_error_code=CONFIRMATION_MISMATCH'
    exit 1
}

case "${HOME#/home/}" in
    ''|*[!A-Za-z0-9._-]*) printf '%s\n' 'G5_TARGET_INSPECTION=STOP' 'safe_error_code=HOME_IDENTITY_INVALID'; exit 1 ;;
esac
test "$HOME" != "${HOME#/home/}" || {
    printf '%s\n' 'G5_TARGET_INSPECTION=STOP' 'safe_error_code=HOME_IDENTITY_INVALID'
    exit 1
}

LEGACY_APP="$HOME/rise-gate.com/rise-gate-os"
LEGACY_PUBLIC="$HOME/rise-gate.com/public_html/os.rise-gate.com"
TARGET_DOMAIN="$HOME/company-os.jp"
TARGET_TOPOLOGY="$HOME/company-os.jp/company-os-app"
TARGET_PUBLIC_PARENT="$HOME/company-os.jp/public_html"
TARGET_PUBLIC="$HOME/company-os.jp/public_html/app.company-os.jp"
EXPECTED_PUBLIC_TARGET="$HOME/company-os.jp/company-os-app/current/public"

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

LEGACY_APP_STATE="$(path_state "$LEGACY_APP")"
LEGACY_PUBLIC_STATE="$(path_state "$LEGACY_PUBLIC")"
TARGET_DOMAIN_STATE="$(path_state "$TARGET_DOMAIN")"
TARGET_TOPOLOGY_STATE="$(path_state "$TARGET_TOPOLOGY")"
TARGET_PUBLIC_PARENT_STATE="$(path_state "$TARGET_PUBLIC_PARENT")"
TARGET_PUBLIC_STATE="$(path_state "$TARGET_PUBLIC")"

PUBLIC_LINK_BINDING='not_applicable'
if test -L "$TARGET_PUBLIC"; then
    if test "$(readlink "$TARGET_PUBLIC" 2>/dev/null || true)" = "$EXPECTED_PUBLIC_TARGET"; then
        PUBLIC_LINK_BINDING='exact'
    else
        PUBLIC_LINK_BINDING='different'
    fi
fi

REQUIRED_COMMAND_COUNT=14
MISSING_COMMAND_COUNT=0
for COMMAND in awk bash df find ln mkdir mv php readlink realpath rm sha256sum stat tar; do
    if ! command -v "$COMMAND" >/dev/null 2>&1; then
        MISSING_COMMAND_COUNT=$((MISSING_COMMAND_COUNT + 1))
    fi
done

PHP_CLI_STATE='absent'
PHP_VERSION='unknown'
if command -v php >/dev/null 2>&1; then
    PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION,".",PHP_RELEASE_VERSION;' 2>/dev/null || true)"
    case "$PHP_VERSION" in
        [0-9]*.[0-9]*.[0-9]*) PHP_CLI_STATE='present' ;;
        *) PHP_VERSION='unknown' ;;
    esac
fi

SAME_DEVICE='unknown'
if test -d "$TARGET_DOMAIN" && test ! -L "$TARGET_DOMAIN" &&
   test -d "$TARGET_PUBLIC_PARENT" && test ! -L "$TARGET_PUBLIC_PARENT"; then
    DOMAIN_DEVICE="$(stat -c '%d' "$TARGET_DOMAIN" 2>/dev/null || true)"
    PUBLIC_DEVICE="$(stat -c '%d' "$TARGET_PUBLIC_PARENT" 2>/dev/null || true)"
    if test -n "$DOMAIN_DEVICE" && test "$DOMAIN_DEVICE" = "$PUBLIC_DEVICE"; then
        SAME_DEVICE='yes'
    elif test -n "$DOMAIN_DEVICE" && test -n "$PUBLIC_DEVICE"; then
        SAME_DEVICE='no'
    fi
fi

AVAILABLE_KIB="$(df -Pk "$HOME" 2>/dev/null | awk 'NR==2 { print $4 + 0 }')"
case "$AVAILABLE_KIB" in ''|*[!0-9]*) AVAILABLE_KIB=0 ;; esac

MUTATION_READINESS='review_required'
if test "$LEGACY_APP_STATE" = 'directory' &&
   test "$LEGACY_PUBLIC_STATE" = 'directory' &&
   test "$TARGET_DOMAIN_STATE" = 'directory' &&
   test "$TARGET_TOPOLOGY_STATE" = 'absent' &&
   test "$TARGET_PUBLIC_PARENT_STATE" = 'directory' &&
   test "$TARGET_PUBLIC_STATE" = 'absent' &&
   test "$MISSING_COMMAND_COUNT" -eq 0 &&
   test "$PHP_CLI_STATE" = 'present' &&
   test "$SAME_DEVICE" = 'yes'; then
    MUTATION_READINESS='capability_rehearsal_eligible'
fi

printf '%s\n' \
    'G5_TARGET_INSPECTION=PASS' \
    "legacy_application_root_state=$LEGACY_APP_STATE" \
    "legacy_public_root_state=$LEGACY_PUBLIC_STATE" \
    "target_domain_root_state=$TARGET_DOMAIN_STATE" \
    "target_domain_root_entry_count=$(entry_count "$TARGET_DOMAIN")" \
    "target_topology_root_state=$TARGET_TOPOLOGY_STATE" \
    "target_topology_root_entry_count=$(entry_count "$TARGET_TOPOLOGY")" \
    "target_public_parent_state=$TARGET_PUBLIC_PARENT_STATE" \
    "target_public_entry_state=$TARGET_PUBLIC_STATE" \
    "target_public_entry_count=$(entry_count "$TARGET_PUBLIC")" \
    "target_public_link_binding=$PUBLIC_LINK_BINDING" \
    "required_command_count=$REQUIRED_COMMAND_COUNT" \
    "missing_required_command_count=$MISSING_COMMAND_COUNT" \
    "php_cli_state=$PHP_CLI_STATE" \
    "php_cli_version=$PHP_VERSION" \
    "domain_public_same_device=$SAME_DEVICE" \
    "available_kib=$AVAILABLE_KIB" \
    'posix_symlink=requires_mutating_rehearsal' \
    'atomic_rename=requires_mutating_rehearsal' \
    "mutation_readiness=$MUTATION_READINESS" \
    'production_change_scope=none_read_only_target_inspection' \
    'secret_output=false' \
    'next_action=RETURN_TO_HUMAN_CHATGPT'
