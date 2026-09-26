#!/usr/bin/env bash
set -Eeuo pipefail

base_url="${1:?Base URL is required.}"
manifest="${2:?HTTP smoke manifest is required.}"
php_bin="${PHP_BIN:-php}"

if [[ ! "$base_url" =~ ^https://[^/]+/?$ ]]; then
    echo "Smoke base URL must be an HTTPS origin without a path." >&2
    exit 64
fi
if [[ ! -f "$manifest" ]]; then
    echo "HTTP smoke manifest does not exist." >&2
    exit 65
fi

mapfile -t checks < <("$php_bin" -r '
$m=json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
foreach (($m["http_checks"] ?? []) as $c) {
    $path=(string)($c["path"] ?? ""); $status=(int)($c["status"] ?? 0);
    if (!preg_match("#^/[A-Za-z0-9_./?=&%-]*$#", $path) || $status < 100 || $status > 599) { exit(64); }
    echo $status."\t".$path."\n";
}' "$manifest")

if [[ "${#checks[@]}" -lt 2 ]]; then
    echo "At least two explicit HTTP checks are required; /login alone is insufficient." >&2
    exit 66
fi

for check in "${checks[@]}"; do
    expected="${check%%$'\t'*}"
    path="${check#*$'\t'}"
    actual="$(curl --silent --show-error --location --output /dev/null --write-out '%{http_code}' \
        --connect-timeout 10 --max-time 30 "${base_url%/}${path}")"
    if [[ "$actual" != "$expected" ]]; then
        echo "HTTP smoke failed for $path: expected $expected, received $actual" >&2
        exit 1
    fi
done

echo "HTTP smoke passed (${#checks[@]} checks)."
