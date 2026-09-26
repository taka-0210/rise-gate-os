#!/usr/bin/env bash
set -Eeuo pipefail

revision="${1:?RC SHA is required.}"
expected_artifact_sha="${2:?Artifact SHA-256 is required.}"
archive="${3:?Remote artifact path is required.}"
release_case="${4:?Release case is required.}"
releases_root="${5:?Release root is required.}"
shared_root="${6:?Shared root is required.}"
current_link="${7:?Current symlink is required.}"
php_bin="${8:?PHP binary is required.}"
migration_manifest="${9:?Migration manifest is required.}"
expected_migration_manifest_sha="${10:?Migration manifest SHA-256 is required.}"
verification_manifest="${11:?Verification manifest is required.}"
expected_verification_manifest_sha="${12:?Verification manifest SHA-256 is required.}"
environment_label="${13:?Environment label is required.}"

fail() { echo "Release stopped: $*" >&2; exit 1; }
[[ "$revision" =~ ^[0-9a-f]{40}$ ]] || fail 'invalid RC SHA'
[[ "$expected_artifact_sha" =~ ^[0-9a-f]{64}$ ]] || fail 'invalid artifact SHA-256'
[[ "$expected_migration_manifest_sha" =~ ^[0-9a-f]{64}$ ]] || fail 'invalid Migration manifest SHA-256'
[[ "$expected_verification_manifest_sha" =~ ^[0-9a-f]{64}$ ]] || fail 'invalid verification manifest SHA-256'
[[ "$release_case" =~ ^[A-Za-z0-9._-]+$ ]] || fail 'invalid release case'
[[ "$environment_label" =~ ^[A-Za-z0-9._-]+$ ]] || fail 'invalid environment label'

for path in "$archive" "$releases_root" "$shared_root" "$current_link" "$php_bin" "$migration_manifest" "$verification_manifest"; do
    [[ "$path" == /* && "$path" != '/' ]] || fail "unsafe or non-absolute path: $path"
    [[ "$path" =~ ^/[A-Za-z0-9._/-]+$ ]] || fail "path contains unsupported characters: $path"
done
[[ -x "$php_bin" ]] || fail 'PHP binary is unavailable'
[[ -f "$archive" && -f "$migration_manifest" && -f "$verification_manifest" ]] || fail 'release input is missing'
[[ -f "$shared_root/.env" && -d "$shared_root/storage" ]] || fail 'shared .env or storage is missing'
[[ -L "$current_link" ]] || fail 'current release reference is not an existing symlink'

hash_equals() { [[ "$(sha256sum "$1" | awk '{print $1}')" == "$2" ]]; }
hash_equals "$archive" "$expected_artifact_sha" || fail 'artifact checksum mismatch'
hash_equals "$migration_manifest" "$expected_migration_manifest_sha" || fail 'Migration manifest checksum mismatch'
hash_equals "$verification_manifest" "$expected_verification_manifest_sha" || fail 'verification manifest checksum mismatch'

while IFS= read -r member; do
    normalized="${member#./}"
    [[ -n "$normalized" && "$normalized" != /* && "$normalized" != '..' && "$normalized" != ../* && "$normalized" != */../* ]] \
        || fail "unsafe archive member: $member"
    case "$normalized" in
        .env|*/.env|database/*.sqlite|database/*.sqlite3|storage/app/*|storage/framework/*|storage/logs/*)
            fail "runtime state is forbidden in artifact: $normalized" ;;
    esac
done < <(tar -tzf "$archive")

mkdir -p -- "$releases_root"
target="$releases_root/$revision"
incoming="$releases_root/.incoming-$revision"
[[ ! -e "$target" && ! -e "$incoming" ]] || fail 'immutable release target already exists'
mkdir -- "$incoming"
tar -xzf "$archive" -C "$incoming"
[[ -f "$incoming/artisan" && -f "$incoming/vendor/autoload.php" && -f "$incoming/public/build/manifest.json" ]] \
    || fail 'artifact is incomplete'

manifest_sha="$($php_bin -r '$m=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);echo $m["rc_sha"]??"";' "$incoming/release-manifest.json")"
[[ "$manifest_sha" == "$revision" ]] || fail 'embedded RC SHA mismatch'

for approved_manifest in "$migration_manifest" "$verification_manifest"; do
    approved_identity="$($php_bin -r '$m=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);echo ($m["rc_sha"]??"")."\t".($m["release_case"]??"");' "$approved_manifest")"
    [[ "$approved_identity" == "$revision"$'\t'"$release_case" ]] \
        || fail 'approved manifest belongs to a different RC or release case'
done

rm -rf -- "$incoming/storage"
ln -s -- "$shared_root/storage" "$incoming/storage"
ln -s -- "$shared_root/.env" "$incoming/.env"
mv -- "$incoming" "$target"

old_release="$(readlink -f "$current_link")"
[[ -f "$old_release/artisan" ]] || fail 'current release is not a valid application release'

# Maintenance failure is a hard stop. No EXIT trap may run `artisan up`.
"$php_bin" "$old_release/artisan" down --retry=60

cd "$target"
"$php_bin" artisan release:migrations:verify "$migration_manifest" --for-apply
mapfile -t migrations < <("$php_bin" artisan release:migrations:verify "$migration_manifest" --for-apply --list)
for migration in "${migrations[@]}"; do
    [[ "$migration" =~ ^[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[A-Za-z0-9_]+$ ]] || fail 'unsafe Migration name'
    "$php_bin" artisan migrate --force --path="database/migrations/${migration}.php"
done
"$php_bin" artisan release:migrations:verify-applied "$migration_manifest"
"$php_bin" artisan optimize:clear
"$php_bin" artisan optimize
"$php_bin" artisan release:verify "$verification_manifest" --environment="$environment_label"

previous_link="${current_link}.previous"
next_link="${current_link}.next-$revision"
rm -f -- "$next_link"
ln -s -- "$target" "$next_link"
ln -sfn -- "$old_release" "${previous_link}.next"
mv -Tf -- "${previous_link}.next" "$previous_link"
mv -Tf -- "$next_link" "$current_link"

"$php_bin" "$current_link/artisan" release:verify "$verification_manifest" --environment="$environment_label"
# Maintenance is released only after every independent success gate above passes.
"$php_bin" "$current_link/artisan" up

rm -f -- "$archive"
echo "Release $release_case switched to immutable RC $revision. Previous release retained at $previous_link."
