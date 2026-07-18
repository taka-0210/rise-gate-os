#!/usr/bin/env bash
set -Eeuo pipefail

revision="${1:?デプロイするリビジョンが指定されていません。}"
php_bin="/usr/bin/php8.3"
app_root="$HOME/rise-gate.com/rise-gate-os"
public_root="$HOME/rise-gate.com/public_html/os.rise-gate.com"
archive="$HOME/rise-gate-os-${revision}.tar.gz"
release_root="$HOME/.rise-gate-os-deploy/${revision}"

cleanup() {
    rm -f -- "$archive"
    rm -rf -- "$release_root"
}

finish() {
    if [[ -f "$app_root/artisan" ]]; then
        "$php_bin" "$app_root/artisan" up >/dev/null 2>&1 || true
    fi
    cleanup
}

trap finish EXIT

if [[ ! "$revision" =~ ^[0-9a-f]{40}$ ]]; then
    echo "不正なリビジョン指定です。" >&2
    exit 1
fi

if [[ ! -x "$php_bin" ]]; then
    echo "PHP 8.3が見つかりません: $php_bin" >&2
    exit 1
fi

if [[ ! -f "$archive" ]]; then
    echo "転送されたパッケージが見つかりません: $archive" >&2
    exit 1
fi

if ! command -v rsync >/dev/null 2>&1; then
    echo "XServerでrsyncが利用できません。" >&2
    exit 1
fi

mkdir -p -- "$release_root" "$app_root" "$public_root"
tar -xzf "$archive" -C "$release_root"

if [[ ! -f "$release_root/artisan" || ! -f "$release_root/vendor/autoload.php" ]]; then
    echo "本番用パッケージが不完全なため、反映を中止しました。" >&2
    exit 1
fi

if [[ -f "$app_root/artisan" && -f "$app_root/vendor/autoload.php" ]]; then
    "$php_bin" "$app_root/artisan" down --retry=60 || true
fi

rsync -a --delete \
    --exclude='.env' \
    --exclude='storage/' \
    --exclude='public/' \
    --exclude='composer.phar' \
    "$release_root/" "$app_root/"

mkdir -p \
    "$app_root/storage/app/public" \
    "$app_root/storage/framework/cache/data" \
    "$app_root/storage/framework/sessions" \
    "$app_root/storage/framework/views" \
    "$app_root/storage/logs" \
    "$app_root/bootstrap/cache"

rsync -a --delete \
    --exclude='index.php' \
    --exclude='.user.ini' \
    "$release_root/public/" "$public_root/"

install -m 604 "$release_root/deployment/public-index.php" "$public_root/index.php"

cd "$app_root"
"$php_bin" artisan migrate --force
"$php_bin" artisan optimize:clear
"$php_bin" artisan optimize
"$php_bin" artisan up

trap - EXIT
cleanup

echo "RISE GATE OS ${revision} の本番反映が完了しました。"
