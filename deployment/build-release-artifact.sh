#!/usr/bin/env bash
set -Eeuo pipefail

rc_sha="${1:?RC SHA is required.}"
output_dir="${2:?Output directory is required.}"

if [[ ! "$rc_sha" =~ ^[0-9a-f]{40}$ ]]; then
    echo "RC SHA must be an exact 40-character commit SHA." >&2
    exit 64
fi
if [[ "$(git rev-parse HEAD)" != "$rc_sha" ]]; then
    echo "Checked-out HEAD differs from the requested RC SHA." >&2
    exit 65
fi
if ! git diff --quiet || ! git diff --cached --quiet; then
    echo "Tracked working tree changes are not allowed in an RC build." >&2
    exit 66
fi
for required in composer.lock package-lock.json public/build/manifest.json vendor/autoload.php; do
    if [[ ! -f "$required" ]]; then
        echo "Required build input is missing: $required" >&2
        exit 67
    fi
done

mkdir -p -- "$output_dir"
output_dir="$(cd "$output_dir" && pwd)"
stage="$(mktemp -d)"
cleanup() { rm -rf -- "$stage"; }
trap cleanup EXIT

tar -cf - \
    app artisan bootstrap composer.json composer.lock config database deployment package.json package-lock.json \
    public resources routes storage tools/local-dev vendor vite.config.js \
    | tar -xf - -C "$stage"

rm -rf -- "$stage/storage"
find "$stage/database" -type f \( -name '*.sqlite' -o -name '*.sqlite3' \) -delete
if find "$stage" -type f \( -name '.env' -o -name '*.sqlite' -o -name '*.sqlite3' \) -print -quit | grep -q .; then
    echo "Runtime environment or database file entered the artifact." >&2
    exit 68
fi

migration_hash="$(php -r '$m=[];foreach(glob("database/migrations/*.php")?:[] as $p){$m[pathinfo($p,PATHINFO_FILENAME)]=hash_file("sha256",$p);}ksort($m);echo hash("sha256",json_encode($m,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));')"
cat > "$stage/release-manifest.json" <<EOF
{
  "schema_version": 1,
  "rc_sha": "$rc_sha",
  "composer_lock_sha256": "$(sha256sum composer.lock | awk '{print $1}')",
  "package_lock_sha256": "$(sha256sum package-lock.json | awk '{print $1}')",
  "vite_manifest_sha256": "$(sha256sum public/build/manifest.json | awk '{print $1}')",
  "repository_migrations_sha256": "$migration_hash"
}
EOF

archive="$output_dir/rise-gate-os-${rc_sha}.tar.gz"
epoch="$(git show -s --format=%ct "$rc_sha")"
tar --sort=name --mtime="@$epoch" --owner=0 --group=0 --numeric-owner -cf - -C "$stage" . \
    | gzip -n -9 > "$archive"
sha256sum "$archive" > "$archive.sha256"

printf '%s\n' "$archive"
printf '%s\n' "$(cut -d' ' -f1 "$archive.sha256")"
