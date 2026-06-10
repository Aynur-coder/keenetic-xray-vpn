#!/bin/sh
# Migration runner for keenetic-xray-vpn.
# Usage: migrate.sh OLD_VERSION NEW_VERSION
#
# Runs migrations from /opt/etc/xray/migrations/NNNN_*.sh whose number
# is greater than the last-applied. Each migration script must be
# idempotent. Applied scripts are recorded in /opt/etc/xray/.migrations
# (one filename per line).
#
# Migration scripts receive OLD_VERSION and NEW_VERSION as $1 and $2.

set -eu

OLD_VER="${1:-0.0.0}"
NEW_VER="${2:-0.0.0}"

MIG_DIR="/opt/etc/xray/migrations"
APPLIED="/opt/etc/xray/.migrations"

log() { echo "[migrate $(date '+%F %T')] $*"; }

mkdir -p "$MIG_DIR"
touch "$APPLIED"

log "OLD=$OLD_VER NEW=$NEW_VER"

# Run all NNNN_*.sh in numerical order that are not yet in $APPLIED
_count=0
for _mig in "$MIG_DIR"/*.sh; do
    [ -f "$_mig" ] || continue
    _bn="$(basename "$_mig")"

    # Skip if already applied
    if grep -Fxq "$_bn" "$APPLIED" 2>/dev/null; then
        continue
    fi

    log "running $_bn"
    if sh "$_mig" "$OLD_VER" "$NEW_VER"; then
        printf '%s\n' "$_bn" >> "$APPLIED"
        _count=$((_count + 1))
        log "  ok"
    else
        log "  FAILED — aborting"
        exit 1
    fi
done

log "applied $_count migration(s)"
