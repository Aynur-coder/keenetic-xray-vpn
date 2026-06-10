#!/bin/sh
# Redirect all LAN DNS queries (UDP+TCP port 53) to AdGuard Home on this router.
# Called by Keenetic ndm when firewall rules are (re)applied.
# Environment: $type = iptables|ip6tables, $table = nat|filter|mangle

[ "$type" = "ip6tables" ] && exit 0
[ "$table" = "nat" ] || exit 0

# Idempotent: add only if rule not already present
iptables -t nat -C PREROUTING -i br0 -p udp --dport 53 -j REDIRECT --to-port 53 2>/dev/null || \
    iptables -t nat -I PREROUTING 1 -i br0 -p udp --dport 53 -j REDIRECT --to-port 53

iptables -t nat -C PREROUTING -i br0 -p tcp --dport 53 -j REDIRECT --to-port 53 2>/dev/null || \
    iptables -t nat -I PREROUTING 2 -i br0 -p tcp --dport 53 -j REDIRECT --to-port 53

exit 0
