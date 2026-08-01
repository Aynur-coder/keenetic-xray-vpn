#!/bin/sh
# Redirect all LAN DNS queries (UDP+TCP port 53) to AdGuard Home on this router.
# Called by Keenetic ndm when firewall rules are (re)applied.
# Environment: $type = iptables|ip6tables, $table = nat|filter|mangle

[ "$type" = "ip6tables" ] && exit 0
[ "$table" = "nat" ] || exit 0

# --- Safety net -------------------------------------------------------------
# AdGuard refuses to start when an ipset named in its config does not exist
# ("initializing ipset: unknown ipset vpn1"). Those sets are normally created by
# xray-manager's setup_firewall, so a router where Xray never came up (bad config,
# expired subscription) would leave AdGuard dead — and this redirect would then
# funnel every LAN DNS query into a port nobody listens on, taking the whole
# network offline. Create the sets here so AdGuard can always start.
ipset create vpn1 hash:net family inet  2>/dev/null || :
ipset create vpn6 hash:net family inet6 2>/dev/null || :

# Never point the redirect at a dead resolver: without a listener on :53 this rule
# breaks DNS for every client. Better to leave clients on the stock Keenetic DNS
# (working internet, no VPN routing) than to black-hole the whole LAN.
if ! netstat -lnu 2>/dev/null | awk '{print $4}' | grep -qE ':53$'; then
    # Nothing listening on UDP/53 — drop any stale redirect and bail out.
    while iptables -t nat -D PREROUTING -i br0 -p udp --dport 53 -j REDIRECT --to-port 53 2>/dev/null; do :; done
    while iptables -t nat -D PREROUTING -i br0 -p tcp --dport 53 -j REDIRECT --to-port 53 2>/dev/null; do :; done
    exit 0
fi

# --- Redirect ---------------------------------------------------------------
# Idempotent: add only if rule not already present
iptables -t nat -C PREROUTING -i br0 -p udp --dport 53 -j REDIRECT --to-port 53 2>/dev/null || \
    iptables -t nat -I PREROUTING 1 -i br0 -p udp --dport 53 -j REDIRECT --to-port 53

iptables -t nat -C PREROUTING -i br0 -p tcp --dport 53 -j REDIRECT --to-port 53 2>/dev/null || \
    iptables -t nat -I PREROUTING 2 -i br0 -p tcp --dport 53 -j REDIRECT --to-port 53

exit 0
