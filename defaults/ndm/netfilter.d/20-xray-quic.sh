#!/bin/sh
# Re-attach the QUIC guard after Keenetic ndm rebuilds the filter table.
# Called by Keenetic ndm when firewall rules are (re)applied.
# Environment: $type = iptables|ip6tables, $table = nat|filter|mangle
#
# xray-manager fills the XRAY_QUIC / XRAY_QUIC6 chains (reject UDP 443 to proxied
# destinations so browsers fall back to TCP, which goes through Xray). ndm may drop our
# FORWARD jump on reload; restore it only while the chain exists and the watchdog has not
# paused the redirect (paused = traffic goes direct, blocking QUIC would gain nothing).

[ "$table" = "filter" ] || exit 0
[ "$(cat /opt/var/run/xray-watchdog.state 2>/dev/null)" = "paused" ] && exit 0

if [ "$type" = "ip6tables" ]; then
    ip6tables -L XRAY_QUIC6 -n >/dev/null 2>&1 || exit 0
    ip6tables -C FORWARD -i br0 -p udp -j XRAY_QUIC6 2>/dev/null || \
        ip6tables -I FORWARD 1 -i br0 -p udp -j XRAY_QUIC6
else
    iptables -L XRAY_QUIC -n >/dev/null 2>&1 || exit 0
    iptables -C FORWARD -i br0 -p udp -j XRAY_QUIC 2>/dev/null || \
        iptables -I FORWARD 1 -i br0 -p udp -j XRAY_QUIC
fi

exit 0
