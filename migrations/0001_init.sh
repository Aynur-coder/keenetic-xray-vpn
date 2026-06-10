#!/bin/sh
# 0001_init.sh — first-run migration. Idempotent.
# $1 = OLD_VERSION, $2 = NEW_VERSION

set -eu

XRAY_DIR="/opt/etc/xray"

# Ensure directories exist
mkdir -p "$XRAY_DIR/rules" "$XRAY_DIR/subscriptions" "$XRAY_DIR/backups" "$XRAY_DIR/migrations"
mkdir -p /opt/etc/wireguard /opt/var/log/xray /opt/share/www/xray

# Tighten sensitive perms (idempotent)
[ -f "$XRAY_DIR/.kn_pass" ]      && chmod 600 "$XRAY_DIR/.kn_pass"      || :
[ -f "$XRAY_DIR/ui-auth.json" ]  && chmod 600 "$XRAY_DIR/ui-auth.json"  || :
[ -d /opt/etc/wireguard ]        && chmod 700 /opt/etc/wireguard        || :
[ -f /opt/etc/wireguard/wg0.conf ] && chmod 600 /opt/etc/wireguard/wg0.conf || :

# If keys.json is empty-but-existing (no migration), seed empty array (idempotent)
for _f in subscriptions/list.json subscriptions/keys.json subscriptions/cached_servers.json rules/github_lists.json; do
    _p="$XRAY_DIR/$_f"
    if [ -f "$_p" ] && [ ! -s "$_p" ]; then
        echo '[]' > "$_p"
    fi
done

exit 0
