#!/bin/sh
set -eu

EXPECTED_CONFIRMATION='IR1-G5-PRIMARY-MAILER-DIAGNOSTIC'
EXPECTED_HOME='/home/xs377816'
EXPECTED_UID='20046'
EXPECTED_GID='1000'

stop() {
    printf '%s\n' \
        'G5_PRIMARY_MAILER_TARGET_CAPABILITY=STOP' \
        "safe_error_code=$1" \
        'production_change_scope=none_read_only_target' \
        'secret_output=false' \
        'next_action=RETURN_TO_HUMAN_CHATGPT'
    exit 1
}

[ "$#" -eq 2 ] || stop ARGUMENT_COUNT_MISMATCH
[ "$1" = "$EXPECTED_CONFIRMATION" ] || stop CONFIRMATION_MISMATCH
CAPABILITY_ID=$2
[ "${HOME:-}" = "$EXPECTED_HOME" ] || stop TARGET_HOME_MISMATCH
[ "$(id -u)" = "$EXPECTED_UID" ] || stop TARGET_UID_MISMATCH
[ "$(id -g)" = "$EXPECTED_GID" ] || stop TARGET_GID_MISMATCH

SENDMAIL_STATE='not_required'
PROC_OPEN_STATE='not_required'
case "$CAPABILITY_ID" in
    tc01)
        [ -x /usr/sbin/sendmail ] || stop TARGET_SENDMAIL_CAPABILITY_MISSING
        SENDMAIL_STATE='pass'
        ;;
    tc02)
        php -r 'exit(function_exists("proc_open") ? 0 : 1);' >/dev/null 2>&1 \
            || stop TARGET_PROC_OPEN_CAPABILITY_MISSING
        PROC_OPEN_STATE='pass'
        ;;
    tc03)
        [ -x /usr/sbin/sendmail ] || stop TARGET_SENDMAIL_CAPABILITY_MISSING
        php -r 'exit(function_exists("proc_open") ? 0 : 1);' >/dev/null 2>&1 \
            || stop TARGET_PROC_OPEN_CAPABILITY_MISSING
        SENDMAIL_STATE='pass'
        PROC_OPEN_STATE='pass'
        ;;
    *)
        stop TARGET_CAPABILITY_ID_UNSUPPORTED
        ;;
esac

printf '%s\n' \
    'G5_PRIMARY_MAILER_TARGET_CAPABILITY=PASS' \
    "target_capability_id=$CAPABILITY_ID" \
    "sendmail_capability=$SENDMAIL_STATE" \
    "php_proc_open_capability=$PROC_OPEN_STATE" \
    'production_change_scope=none_read_only_target' \
    'secret_output=false' \
    'next_action=RETURN_TO_HUMAN_CHATGPT'
