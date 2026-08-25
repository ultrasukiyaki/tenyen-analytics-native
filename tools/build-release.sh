#!/usr/bin/env sh
set -eu

root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
dist="$root/dist"
stage=$(mktemp -d "${TMPDIR:-/tmp}/tenyen-release.XXXXXX")
trap 'rm -rf "$stage"' EXIT HUP INT TERM
package="$stage/tenyen-analytics"

mkdir -p "$package" "$dist"
(
    cd "$root"
    find . -type f \
        ! -path './.git/*' \
        ! -path './.github/*' \
        ! -path './vendor/*' \
        ! -path './node_modules/*' \
        ! -path './dist/*' \
        ! -path './build/*' \
        ! -path './release/*' \
        ! -path './prompts/*' \
        ! -path './tools/*' \
        ! -path './tests/*' \
        ! -path './storage/*' \
        ! -path './data/*' \
        ! -name '.gitignore' \
        ! -name '.gitattributes' \
        ! -name '.editorconfig' \
        ! -name 'config.php' \
        ! -name '*.mmdb' \
        ! -name '*.log' \
        ! -name '*.zip' \
        ! -name '*.tmp' \
        -print | while IFS= read -r file; do
            mkdir -p "$package/$(dirname "$file")"
            cp -p "$file" "$package/$file"
        done
)

mkdir -p "$package/data" "$package/storage"
cp -p "$root/data/.gitkeep" "$root/data/.htaccess" "$package/data/"
cp -p "$root/storage/.gitkeep" "$root/storage/.htaccess" "$package/storage/"

archive="$dist/tenyen-analytics-v0.7.0-stable.zip"
checksums="$dist/tenyen-analytics-v0.7.0-SHA256SUMS.txt"
rm -f "$archive" "$checksums"
(cd "$stage" && zip -qr "$archive" tenyen-analytics)
unzip -t "$archive"

if unzip -Z1 "$archive" | grep -E '(^|/)(config\.php|vendor/|node_modules/|tests/|tools/|\.git/|installed\.lock)|\.mmdb$|\.log$' >/dev/null; then
    echo "Forbidden file found in release archive." >&2
    exit 1
fi

if command -v sha256sum >/dev/null 2>&1; then
    (cd "$dist" && sha256sum "$(basename "$archive")" > "$(basename "$checksums")")
elif command -v shasum >/dev/null 2>&1; then
    (cd "$dist" && shasum -a 256 "$(basename "$archive")" > "$(basename "$checksums")")
else
    echo "No SHA-256 tool is available." >&2
    exit 1
fi

echo "Created $archive"
echo "Created $checksums"
