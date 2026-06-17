#!/bin/bash
# cPanel Git deployment hook — builds and syncs to DEPLOYPATH.
# Called from .cpanel.yml after git pull.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

if [ -z "${DEPLOYPATH:-}" ]; then
    echo "ERROR: DEPLOYPATH is not set (configure Deployment Path in cPanel Git)" >&2
    exit 1
fi

if [ ! -d "$DEPLOYPATH" ]; then
    echo "ERROR: DEPLOYPATH does not exist: $DEPLOYPATH" >&2
    exit 1
fi

TMPDIR=$(mktemp -d /tmp/zamanak-deploy-XXXXXX)
BACKUP_DIR=$(mktemp -d /tmp/zamanak-backup-XXXXXX)

cleanup() {
    rm -rf "$TMPDIR" "$BACKUP_DIR"
}
trap cleanup EXIT

echo "Building deploy package..."
bash "$SCRIPT_DIR/build-deploy-package.sh" "$TMPDIR"

PRESERVE_FILES=(
    "php/.env"
    "php/storage/php-errors.log"
)

echo "Backing up server-only files..."
for rel in "${PRESERVE_FILES[@]}"; do
    if [ -f "$DEPLOYPATH/$rel" ]; then
        mkdir -p "$(dirname "$BACKUP_DIR/$rel")"
        cp -a "$DEPLOYPATH/$rel" "$BACKUP_DIR/$rel"
    fi
done

echo "Syncing to $DEPLOYPATH ..."
rsync -a --delete \
    --exclude 'php/.env' \
    --exclude 'php/.env.local' \
    --exclude 'php/storage/php-errors.log' \
    --exclude 'php/storage/*.sqlite' \
    "$TMPDIR/" "$DEPLOYPATH/"

echo "Restoring server-only files..."
for rel in "${PRESERVE_FILES[@]}"; do
    if [ -f "$BACKUP_DIR/$rel" ]; then
        mkdir -p "$(dirname "$DEPLOYPATH/$rel")"
        cp -a "$BACKUP_DIR/$rel" "$DEPLOYPATH/$rel"
    fi
done

mkdir -p "$DEPLOYPATH/php/storage"
chmod 775 "$DEPLOYPATH/php/storage" 2>/dev/null || chmod 755 "$DEPLOYPATH/php/storage"

if command -v curl >/dev/null 2>&1; then
    HEALTH_URL="${APP_BASE_URL:-https://hamgam.zamanak24.ir}/php/hamgam/health.php"
    echo "Health check: $HEALTH_URL"
    if response=$(curl -sf --max-time 15 "$HEALTH_URL" 2>/dev/null); then
        echo "  OK: ${response:0:120}"
    else
        echo "  WARNING: health check failed (site may still be propagating)"
    fi
fi

echo "Deploy complete: $DEPLOYPATH"
