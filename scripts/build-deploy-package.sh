#!/bin/bash
# Creates a clean deploy package ready for cPanel upload.
# Usage: build-deploy-package.sh [OUTPUT_DIR]
# Default OUTPUT_DIR: <repo-root>/deploy

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
OUT="${1:-$ROOT/deploy}"

EXCLUDE_DIRS=(".git" ".vscode" "dev" "deploy" "scripts" "node_modules")

get_asset_version() {
    local css="$1"
    local js="$2"
    local content=""

    if [ -f "$css" ]; then
        content+=$(cat "$css")
    fi
    if [ -f "$js" ]; then
        content+=$(cat "$js")
    fi

    if [ -z "$content" ]; then
        date +%Y%m%d%H%M
        return
    fi

    printf '%s' "$content" | sha256sum | awk '{print substr($1, 1, 8)}'
}

stamp_html_file() {
    local html="$1"
    local css="$2"
    local js="$3"

    if [ ! -f "$html" ]; then
        return
    fi

    local version
    version=$(get_asset_version "$css" "$js")

    sed -i -E \
        -e "s|href=\"style\.css(\?v=[^\"]*)?\"|href=\"style.css?v=$version\"|g" \
        -e "s|src=\"script\.js(\?v=[^\"]*)?\"|src=\"script.js?v=$version\"|g" \
        "$html"

    echo "Asset version v=$version -> $html"
}

copy_tree() {
    local src="$1"
    local dest="$2"
    local name

    mkdir -p "$dest"

    for item in "$src"/* "$src"/.[!.]* "$src"/..?*; do
        [ -e "$item" ] || continue

        name=$(basename "$item")
        for excluded in "${EXCLUDE_DIRS[@]}"; do
            if [ "$name" = "$excluded" ]; then
                continue 2
            fi
        done

        if [ -d "$item" ]; then
            copy_tree "$item" "$dest/$name"
        else
            cp -a "$item" "$dest/$name"
        fi
    done
}

strip_php_bom() {
    local file="$1"
    local bom

    bom=$(head -c 3 "$file" | od -An -tx1 2>/dev/null | tr -d ' \n')
    if [ "$bom" = "efbbbf" ]; then
        tail -c +4 "$file" > "${file}.nobom"
        mv "${file}.nobom" "$file"
        echo "Stripped BOM: $(basename "$file")"
    fi
}

# Stamp asset URLs in HTML before packaging (cache busting for CSS/JS)
stamp_html_file "$ROOT/index.html" "$ROOT/style.css" "$ROOT/script.js"
if [ -f "$ROOT/landing/index.html" ]; then
    stamp_html_file "$ROOT/landing/index.html" "$ROOT/landing/style.css" "$ROOT/landing/script.js"
fi

rm -rf "$OUT"
mkdir -p "$OUT"

# Root frontend
for f in index.html script.js style.css .htaccess; do
    if [ -f "$ROOT/$f" ]; then
        cp -a "$ROOT/$f" "$OUT/$f"
    fi
done

# PHP backend (exclude secrets and local-only)
copy_tree "$ROOT/php" "$OUT/php"

for rel in .env .env.local storage/database.sqlite storage/php-errors.log; do
    if [ -e "$OUT/php/$rel" ]; then
        rm -rf "$OUT/php/$rel"
    fi
done

# Ensure writable storage
mkdir -p "$OUT/php/storage"
if [ ! -f "$OUT/php/storage/.htaccess" ] && [ -f "$ROOT/php/storage/.htaccess" ]; then
    cp -a "$ROOT/php/storage/.htaccess" "$OUT/php/storage/.htaccess"
fi

# Strip BOM only from PHP files that actually have it
while IFS= read -r -d '' php_file; do
    strip_php_bom "$php_file"
done < <(find "$OUT" -type f -name '*.php' -print0)

echo "Deploy package ready: $OUT"
