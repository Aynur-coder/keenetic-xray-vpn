#!/bin/sh
# Smoke tests for an installed keenetic-xray-vpn.
# Run inside the router (or the mock container) AFTER install.sh has completed.
#
# Usage:
#   curl -fsSL https://raw.githubusercontent.com/Aynur-coder/keenetic-xray-vpn/main/tests/smoke.sh | sh
#   sh tests/smoke.sh

set -u

# Color output if attached to a TTY
if [ -t 1 ]; then
    G=$(printf '\033[32m'); R=$(printf '\033[31m'); Y=$(printf '\033[33m'); N=$(printf '\033[0m')
else
    G=""; R=""; Y=""; N=""
fi

PASS=0
FAIL=0
WARN=0

check() {
    _name="$1"; shift
    printf '  %-46s ' "$_name"
    if "$@" >/dev/null 2>&1; then
        printf '%s✓ PASS%s\n' "$G" "$N"
        PASS=$((PASS + 1))
    else
        printf '%s✗ FAIL%s\n' "$R" "$N"
        FAIL=$((FAIL + 1))
    fi
}

warn() {
    _name="$1"; shift
    printf '  %-46s ' "$_name"
    if "$@" >/dev/null 2>&1; then
        printf '%s✓ PASS%s\n' "$G" "$N"
        PASS=$((PASS + 1))
    else
        printf '%s? warn%s\n' "$Y" "$N"
        WARN=$((WARN + 1))
    fi
}

echo "================================================="
echo " keenetic-xray-vpn smoke test"
echo "================================================="

echo
echo "Filesystem"
check "/opt/share/www/xray/index.php exists" test -f /opt/share/www/xray/index.php
check "/opt/share/www/xray/api.php exists"   test -f /opt/share/www/xray/api.php
check "/opt/etc/xray/xray-manager.sh exists" test -f /opt/etc/xray/xray-manager.sh
check "/opt/etc/xray/update.sh exists"       test -f /opt/etc/xray/update.sh
check "/opt/etc/xray/migrate.sh exists"      test -f /opt/etc/xray/migrate.sh
check "/opt/etc/init.d/S22xray exists"       test -x /opt/etc/init.d/S22xray
check "/opt/etc/init.d/S99wireguard exists"  test -x /opt/etc/init.d/S99wireguard
check "/opt/etc/xray/.version exists"        test -f /opt/etc/xray/.version

echo
echo "Permissions"
check "ui-auth.json is 600 (or missing)"     sh -c '[ ! -f /opt/etc/xray/ui-auth.json ] || [ "$(stat -c %a /opt/etc/xray/ui-auth.json 2>/dev/null)" = "600" ]'
check ".kn_pass is 600 (or missing)"         sh -c '[ ! -f /opt/etc/xray/.kn_pass ] || [ "$(stat -c %a /opt/etc/xray/.kn_pass 2>/dev/null)" = "600" ]'
check "/opt/etc/wireguard is 700"            sh -c '[ "$(stat -c %a /opt/etc/wireguard 2>/dev/null)" = "700" ]'

echo
echo "Binaries"
check "xray binary exists"                   test -x /opt/sbin/xray
check "curl available"                       command -v curl
check "php-cgi available"                    sh -c 'command -v php-cgi || command -v /opt/bin/php-cgi'
check "lighttpd binary exists"               sh -c 'command -v lighttpd || test -x /opt/sbin/lighttpd'

echo
echo "Web UI (port 91)"
check "HTTP 200 on /"                        sh -c 'curl -sf -o /dev/null -w "%{http_code}" --max-time 5 http://127.0.0.1:91/ | grep -q 200'
check "api.php?action=status returns JSON"   sh -c 'curl -sf --max-time 5 "http://127.0.0.1:91/api.php?action=status" | head -c1 | grep -q "{"'

echo
echo "Auto-update"
check "update.sh --check works"              sh -c '/opt/etc/xray/update.sh --check 2>/dev/null | head -c1 | grep -q "{"'
check "cron is registered"                   sh -c 'grep -q xray-vpn-update /opt/etc/cron.d/* 2>/dev/null || crontab -l 2>/dev/null | grep -q xray-vpn-update'

echo
echo "Optional (warnings only)"
warn "Xray process is running"               sh -c 'pgrep -x xray >/dev/null'
warn "AdGuard Home is running"               sh -c 'pgrep -x AdGuardHome >/dev/null'
warn "WireGuard interface up"                sh -c '/opt/bin/wg show wg0 2>/dev/null | head -1 | grep -q interface'

echo
echo "================================================="
printf " %sPASS=%d%s  %sFAIL=%d%s  %sWARN=%d%s\n" "$G" "$PASS" "$N" "$R" "$FAIL" "$N" "$Y" "$WARN" "$N"
echo "================================================="

[ "$FAIL" -eq 0 ] || exit 1
exit 0
